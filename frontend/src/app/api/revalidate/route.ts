import { NextResponse } from "next/server";
import { revalidateTag } from "next/cache";
import { createHmac, timingSafeEqual } from "crypto";
import { verifyWooWebhook } from "@/lib/security/webhook";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/revalidate
//  Acepta dos tipos de webhook:
//
//  1. WooCommerce (x-wc-webhook-signature)
//     → invalida el tag "products" (catálogo ISR)
//
//  2. HWE Control Center (x-hwe-signature)
//     → invalida el tag "site-config" (configuración de marca/diseño)
//     Firmado con HMAC-SHA256 usando HWE_REVALIDATION_SECRET.
// ============================================================================

function verifyHweSignature(rawBody: string, signature: string | null): boolean {
  const secret = process.env.HWE_REVALIDATION_SECRET;
  if (!signature || !secret) return false;
  const expected = "sha256=" + createHmac("sha256", secret).update(rawBody).digest("hex");
  try {
    return timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
  } catch {
    return false;
  }
}

export async function POST(request: Request) {
  const rawBody = await request.text();

  // ── Rama 1: revalidación de configuración del plugin ─────────────────────
  const hweSignature = request.headers.get("x-hwe-signature");
  if (hweSignature !== null) {
    if (!verifyHweSignature(rawBody, hweSignature)) {
      logger.warn({ event: "revalidate.invalid_hwe_signature" }, "Firma HWE de webhook inválida");
      return NextResponse.json({ error: "Firma de webhook inválida." }, { status: 401 });
    }
    revalidateTag("site-config");
    logger.info({ event: "revalidate.site_config" }, "Configuración del sitio revalidada");
    return NextResponse.json({ revalidated: true, tag: "site-config", now: Date.now() });
  }

  // ── Rama 2: revalidación del catálogo WooCommerce ────────────────────────
  const wcSignature = request.headers.get("x-wc-webhook-signature");
  if (!verifyWooWebhook(rawBody, wcSignature)) {
    logger.warn({ event: "revalidate.invalid_wc_signature" }, "Firma WC de webhook inválida");
    return NextResponse.json({ error: "Firma de webhook inválida." }, { status: 401 });
  }
  revalidateTag("products");
  logger.info({ event: "revalidate.products" }, "Catálogo de productos revalidado");
  return NextResponse.json({ revalidated: true, tag: "products", now: Date.now() });
}
