import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { getSiteConfig } from "@/lib/config";
import { requireFlag } from "@/lib/api/flags";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { withLock } from "@/lib/security/lock";
import { wishlistBodySchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

const WISHLIST_META_KEY = "hwe_wishlist";

function parseWishlist(customer: WooCustomer): number[] {
  const meta = customer.meta_data?.find((m) => m.key === WISHLIST_META_KEY);
  if (!meta?.value) return [];
  try {
    const arr = typeof meta.value === "string" ? JSON.parse(meta.value) : meta.value;
    return Array.isArray(arr) ? arr.map(Number).filter((n) => !isNaN(n)) : [];
  } catch {
    return [];
  }
}

export async function GET() {
  const disabled = requireFlag(await getSiteConfig(), "wishlist_enabled");
  if (disabled) return disabled;
  try {
    const session = await requireSession();
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
    const ids = parseWishlist(customer);
    return NextResponse.json(ids, { status: 200 });
  } catch (error) {
    return handleApiError(error);
  }
}

export async function POST(request: Request) {
  const disabled = requireFlag(await getSiteConfig(), "wishlist_enabled");
  if (disabled) return disabled;
  const blocked = await guardMutation(request, {
    rateLimit: { name: "wishlist", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = wishlistBodySchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "wishlist.add.validation_error" },
      "Validación fallida en la wishlist",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const productId = parsed.data.productId;
    // Sección crítica serializada por usuario (evita lost-update concurrente).
    await withLock(`wishlist:${session.userId}`, async () => {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const ids = parseWishlist(customer);
      if (!ids.includes(productId)) {
        ids.push(productId);
        await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`, {
          method: "PUT",
          body: { meta_data: [{ key: WISHLIST_META_KEY, value: JSON.stringify(ids) }] },
        });
      }
    });

    logger.info({
      event: "wishlist.add",
      userId: session.userId,
      productId,
    });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "wishlist.add.error",
      err: error instanceof Error ? error.message : error,
    });
    return handleApiError(error);
  }
}

export async function DELETE(request: Request) {
  const disabled = requireFlag(await getSiteConfig(), "wishlist_enabled");
  if (disabled) return disabled;
  const blocked = await guardMutation(request, {
    rateLimit: { name: "wishlist", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = wishlistBodySchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "wishlist.remove.validation_error" },
      "Validación fallida en la wishlist",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const productId = parsed.data.productId;
    await withLock(`wishlist:${session.userId}`, async () => {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const ids = parseWishlist(customer).filter((id) => id !== productId);
      await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`, {
        method: "PUT",
        body: { meta_data: [{ key: WISHLIST_META_KEY, value: JSON.stringify(ids) }] },
      });
    });

    logger.info({
      event: "wishlist.remove",
      userId: session.userId,
      productId,
    });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "wishlist.remove.error",
      err: error instanceof Error ? error.message : error,
    });
    return handleApiError(error);
  }
}
