import { NextResponse } from "next/server";
import { revalidateTag } from "next/cache";
import { verifyWooWebhook } from "@/lib/security/webhook";

export const dynamic = "force-dynamic";

/**
 * POST /api/revalidate
 * Webhook de WooCommerce: ante creación/actualización de productos (o stock),
 * invalida la caché etiquetada como "products" para que el catálogo (ISR) se
 * regenere bajo demanda. La firma HMAC del webhook es obligatoria.
 */
export async function POST(request: Request) {
  const rawBody = await request.text();
  const signature = request.headers.get("x-wc-webhook-signature");

  if (!verifyWooWebhook(rawBody, signature)) {
    return NextResponse.json(
      { error: "Firma de webhook inválida." },
      { status: 401 },
    );
  }

  revalidateTag("products");

  return NextResponse.json({ revalidated: true, now: Date.now() });
}
