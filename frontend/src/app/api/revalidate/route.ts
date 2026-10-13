import { NextResponse } from "next/server";
import { revalidateTag } from "next/cache";
import { clearPaymentSettingsCache } from "@/lib/payments/credentials";
import { verifyHmacSignature, verifyWooWebhook } from "@/lib/security/webhook";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";
import { logger } from "@/lib/observability/logger";
import { instanceRegistry } from "@/instance/registry";

export const dynamic = "force-dynamic";

/**
 * Tags del núcleo: config, entradas del blog, menús, catálogo (`products`, que
 * WooCommerce invalida por su webhook y WordPress por esta vía al editar la traducción
 * de una categoría) y los de los módulos del framework (banners, site-reviews…; cada
 * plugin de módulo registra el suyo en WordPress con `hwe_revalidation_tags`). La
 * instancia añade los suyos en `instanceRegistry.revalidationTags`.
 */
const CORE_HWE_TAGS = [
  "site-config",
  "banners",
  "posts",
  "menus",
  "products",
  "site-reviews",
  "coupons",
  "geo",
] as const;

export function resolveHweTag(raw: unknown): string | null {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return null;
  const tag = (raw as Record<string, unknown>).tag;
  if (tag === undefined) return "site-config";
  if (typeof tag !== "string") return null;
  const allowed = new Set<string>([
    ...CORE_HWE_TAGS,
    ...instanceRegistry.revalidationTags.filter((t) => typeof t === "string"),
  ]);
  return allowed.has(tag) ? tag : null;
}

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
  return verifyHmacSignature(rawBody, signature, secret, {
    encoding: "hex",
    prefix: "sha256=",
  });
}

/**
 * Peticiones por ventana y por IP. Va ANTES de leer el cuerpo y de verificar la
 * firma: sin él, cualquiera podía martillear el endpoint (HMAC + log por petición)
 * sin coste. El techo es el de los demás webhooks de WooCommerce (120/min) y no más
 * bajo porque una importación o edición masiva de productos dispara un
 * `product.updated` por producto desde la misma IP del WordPress.
 */
const REVALIDATE_LIMIT = 120;
const REVALIDATE_WINDOW_SECONDS = 60;

export async function POST(request: Request) {
  const rl = await rateLimit(
    `revalidate:${getClientIp(request)}`,
    REVALIDATE_LIMIT,
    REVALIDATE_WINDOW_SECONDS,
  );
  if (!rl.success) {
    logger.warn({ event: "revalidate.rate_limited" }, "Rate-limit en /api/revalidate");
    return NextResponse.json(
      { error: "Demasiadas solicitudes." },
      { status: 429, headers: { "Retry-After": String(rl.retryAfter) } },
    );
  }

  const rawBody = await request.text();

  // ── Rama 1: revalidación de configuración del plugin ─────────────────────
  const hweSignature = request.headers.get("x-hwe-signature");
  if (hweSignature !== null) {
    if (!verifyHweSignature(rawBody, hweSignature)) {
      logger.warn(
        { event: "revalidate.invalid_hwe_signature" },
        "Firma HWE de webhook inválida",
      );
      return NextResponse.json(
        { error: "Firma de webhook inválida." },
        { status: 401 },
      );
    }
    let parsed: unknown = null;
    try {
      parsed = JSON.parse(rawBody);
    } catch {
      parsed = null;
    }
    const tag = resolveHweTag(parsed);
    if (tag === null) {
      return NextResponse.json(
        { error: "Tag de revalidación no soportado." },
        { status: 400 },
      );
    }
    // Next 16: revalidateTag requiere un perfil de cacheLife. "max" marca el
    // tag como obsoleto y revalida en segundo plano (stale-while-revalidate),
    // acorde al modelo ISR + webhook de esta plantilla.
    revalidateTag(tag, "max");
    // Guardar el Control Center cambia también la pasarela y sus credenciales,
    // que viven en memoria del proceso (nunca en la Data Cache): se olvidan.
    if (tag === "site-config") clearPaymentSettingsCache();
    logger.info({ event: "revalidate.hwe", tag }, "Tag HWE revalidado");
    return NextResponse.json({ revalidated: true, tag, now: Date.now() });
  }

  // ── Rama 2: revalidación del catálogo WooCommerce ────────────────────────
  const wcSignature = request.headers.get("x-wc-webhook-signature");
  if (!verifyWooWebhook(rawBody, wcSignature)) {
    logger.warn(
      { event: "revalidate.invalid_wc_signature" },
      "Firma WC de webhook inválida",
    );
    return NextResponse.json({ error: "Firma de webhook inválida." }, { status: 401 });
  }
  revalidateTag("products", "max");
  logger.info({ event: "revalidate.products" }, "Catálogo de productos revalidado");
  return NextResponse.json({ revalidated: true, tag: "products", now: Date.now() });
}
