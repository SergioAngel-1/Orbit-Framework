import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import {
  addressSchema,
  addressUpdateSchema,
  addressDeleteSchema,
} from "@/lib/validation/address";
import { withLock } from "@/lib/security/lock";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer } from "@/types/woocommerce";
import type { SavedAddress } from "@/types/address";

export const dynamic = "force-dynamic";

const ADDRESSES_META_KEY = "hwe_addresses";

function parseAddresses(customer: WooCustomer): SavedAddress[] {
  const meta = customer.meta_data?.find((m) => m.key === ADDRESSES_META_KEY);
  if (!meta?.value) return [];
  try {
    const arr = typeof meta.value === "string" ? JSON.parse(meta.value) : meta.value;
    return Array.isArray(arr) ? arr : [];
  } catch {
    return [];
  }
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
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = addressSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const newAddress = await withLock(`addresses:${session.userId}`, async () => {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const addresses = parseAddresses(customer);
      const addr = { ...parsed.data };

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
      return addr;
    });

    logger.info({ event: "addresses.create", userId: session.userId });
    return NextResponse.json(newAddress, { status: 201 });
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
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = addressUpdateSchema.safeParse(body);
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

      if (parsed.data.index >= addresses.length || parsed.data.index < 0) {
        return { notFound: true as const };
      }

      const updated = { ...parsed.data.address };
      if (updated.is_default) {
        addresses.forEach((a) => {
          a.is_default = false;
        });
      }
      addresses[parsed.data.index] = updated;

      await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`, {
        method: "PUT",
        body: {
          meta_data: [{ key: ADDRESSES_META_KEY, value: JSON.stringify(addresses) }],
        },
      });
      return { notFound: false as const, updated };
    });

    if (result.notFound) {
      return NextResponse.json({ error: "Dirección no encontrada." }, { status: 404 });
    }

    logger.info({
      event: "addresses.update",
      userId: session.userId,
      index: parsed.data.index,
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
    body = await request.json();
  } catch {
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

      if (parsed.data.index >= addresses.length || parsed.data.index < 0) {
        return { notFound: true as const };
      }

      addresses.splice(parsed.data.index, 1);

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
      return { notFound: false as const };
    });

    if (result.notFound) {
      return NextResponse.json({ error: "Dirección no encontrada." }, { status: 404 });
    }

    logger.info({
      event: "addresses.delete",
      userId: session.userId,
      index: parsed.data.index,
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
