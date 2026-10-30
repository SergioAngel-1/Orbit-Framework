import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import {
  buildAddressSchema,
  buildAddressUpdateSchema,
  addressDeleteSchema,
} from "@/lib/validation/address";
import { getGeoPack } from "@/lib/geo";
import { pushCartDestination } from "@/lib/woocommerce/cart-destination";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { withLock } from "@/lib/security/lock";
import {
  ADDRESSES_META_KEY,
  findAddressIndex,
  mergeAddressLocation,
  newAddressId,
  parseSavedAddresses,
} from "@/lib/customer/addresses";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer } from "@/types/woocommerce";
import type { SavedAddress } from "@/types/address";

export const dynamic = "force-dynamic";

function parseAddresses(customer: WooCustomer): SavedAddress[] {
  return parseSavedAddresses(customer.meta_data);
}

/**
 * Tras cambiar la libreta, el carrito pasa a enviarse a la dirección que quedó como
 * predeterminada: tarifa y envío gratis dependen de esa ciudad, y Woo no lee la
 * libreta (módulo `shipping`; sin él no hace nada). Va DESPUÉS de guardar: escribir el
 * perfil hace que Woo descarte el destino que tenía la sesión del carrito.
 */
async function syncCartDestinationWith(
  userId: string,
  addresses: readonly SavedAddress[],
): Promise<void> {
  const { cartToken, authToken } = await getCartRequestAuth();
  const result = await pushCartDestination(userId, addresses, { cartToken, authToken });
  if (result) await writeCartToken(result.cartToken);
}

export async function GET() {
  try {
    const session = await requireSession();
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
    const addresses = parseAddresses(customer);
    return NextResponse.json(addresses, { status: 200 });
  } catch (error) {
    return handleApiError(error);
  }
}

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "addresses", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = buildAddressSchema(await getGeoPack()).safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const saved = await withLock(`addresses:${session.userId}`, async () => {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const addresses = parseAddresses(customer);
      const addr: SavedAddress = { ...parsed.data, id: newAddressId() };

      // If marked as default, unmark others
      if (addr.is_default) {
        addresses.forEach((a) => {
          a.is_default = false;
        });
      } else if (addresses.length === 0) {
        addr.is_default = true;
      }

      addresses.push(addr);

      await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`, {
        method: "PUT",
        body: {
          meta_data: [{ key: ADDRESSES_META_KEY, value: JSON.stringify(addresses) }],
        },
      });
      return { addr, addresses };
    });

    await syncCartDestinationWith(String(session.userId), saved.addresses);
    logger.info({ event: "addresses.create", userId: session.userId });
    return NextResponse.json(saved.addr, { status: 201 });
  } catch (error) {
    logger.error({
      event: "addresses.create.error",
      err: error instanceof Error ? error.message : error,
    });
    return handleApiError(error);
  }
}

export async function PUT(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "addresses", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = buildAddressUpdateSchema(await getGeoPack()).safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const result = await withLock(`addresses:${session.userId}`, async () => {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const addresses = parseAddresses(customer);
      const index = findAddressIndex(addresses, parsed.data);
      if (index < 0) return { notFound: true as const };

      // El pin de la dirección anterior se conserva solo si no cambian calle ni ciudad
      // (`mergeAddressLocation`); si la edición trae uno nuevo, ese vale. El identificador no
      // lo decide el cliente: es el de la dirección que se edita.
      const updated: SavedAddress = {
        ...mergeAddressLocation(addresses[index], { ...parsed.data.address }),
        id: addresses[index].id,
      };
      if (updated.is_default) {
        addresses.forEach((a) => {
          a.is_default = false;
        });
      }
      addresses[index] = updated;

      await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`, {
        method: "PUT",
        body: {
          meta_data: [{ key: ADDRESSES_META_KEY, value: JSON.stringify(addresses) }],
        },
      });
      return { notFound: false as const, updated, addresses, index };
    });

    if (result.notFound) {
      return NextResponse.json({ error: "Dirección no encontrada." }, { status: 404 });
    }
    await syncCartDestinationWith(String(session.userId), result.addresses);

    logger.info({
      event: "addresses.update",
      userId: session.userId,
      index: result.index,
    });
    return NextResponse.json(result.updated, { status: 200 });
  } catch (error) {
    logger.error({
      event: "addresses.update.error",
      err: error instanceof Error ? error.message : error,
    });
    return handleApiError(error);
  }
}

export async function DELETE(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "addresses", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = addressDeleteSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const result = await withLock(`addresses:${session.userId}`, async () => {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const addresses = parseAddresses(customer);
      const index = findAddressIndex(addresses, parsed.data);
      if (index < 0) return { notFound: true as const };

      addresses.splice(index, 1);

      // If default was removed, set first as default
      if (addresses.length > 0 && !addresses.some((a) => a.is_default)) {
        addresses[0].is_default = true;
      }

      await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`, {
        method: "PUT",
        body: {
          meta_data: [{ key: ADDRESSES_META_KEY, value: JSON.stringify(addresses) }],
        },
      });
      return { notFound: false as const, addresses, index };
    });

    if (result.notFound) {
      return NextResponse.json({ error: "Dirección no encontrada." }, { status: 404 });
    }
    await syncCartDestinationWith(String(session.userId), result.addresses);

    logger.info({
      event: "addresses.delete",
      userId: session.userId,
      index: result.index,
    });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "addresses.delete.error",
      err: error instanceof Error ? error.message : error,
    });
    return handleApiError(error);
  }
}
