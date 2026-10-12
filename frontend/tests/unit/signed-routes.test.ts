import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { isSignedRoute } from "@/lib/security/signed-routes";

// ============================================================================
//  Lista CERRADA de rutas server-to-server que el proxy exime de la barrera de
//  Origin (src/lib/security/signed-routes.ts).
//
//  Eximir una ruta de la barrera es una decisión de seguridad: solo es correcta
//  si la ruta se autentica por firma. Por eso, además de probar la función, se
//  recorren los `route.ts` REALES para que la lista no se pudra en ninguno de
//  los dos sentidos: ni una ruta de webhook nueva queda bloqueada sin que nadie
//  se entere (el webhook de pago devolvería 403 en producción, y WooCommerce
//  desactiva un webhook tras cinco entregas fallidas), ni se exime una ruta que
//  no verifica firma.
// ============================================================================

describe("isSignedRoute", () => {
  it.each([
    "/api/revalidate",
    "/api/webhooks/woocommerce/order-created",
    "/api/webhooks/woocommerce/order-updated",
    "/api/payments/webhook/wompi",
    "/api/payments/webhook/avalpay",
    "/api/payments/webhook/some-provider_2",
  ])("%s está eximida", (path) => {
    expect(isSignedRoute(path)).toBe(true);
  });

  it.each([
    // Prefijos parecidos que NO son webhooks: del navegador, Origin obligatorio.
    "/api/payments/create",
    "/api/payments/return",
    "/api/store/checkout",
    "/api/auth/login",
    "/api/csrf",
    // La lista es cerrada: nada cuelga de un prefijo abierto.
    "/api/webhooks",
    "/api/webhooks/",
    "/api/webhooks/woocommerce",
    "/api/webhooks/woocommerce/order-deleted",
    "/api/webhooks/otro/order-created",
    "/api/webhooks/woocommerce/order-created/extra",
    "/api/revalidate/extra",
    "/api/revalidate/",
    "/api/payments/webhook",
    "/api/payments/webhook/",
    "/api/payments/webhook/wompi/extra",
    "/api/payments/webhook/../create",
    // Otro nivel, otra mayúscula o un esquema no cuentan.
    "/api/store/payments/webhook/wompi",
    "/API/revalidate",
    "/es/api/revalidate",
    "api/revalidate",
    "",
  ])("%s NO está eximida", (path) => {
    expect(isSignedRoute(path)).toBe(false);
  });
});

// ── La lista frente al código real ──────────────────────────────────────────

const API_DIR = fileURLToPath(new URL("../../src/app/api", import.meta.url));

function routeFiles(dir: string = API_DIR): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) return routeFiles(full);
    return name === "route.ts" ? [full] : [];
  });
}

/** URL de ejemplo de un route.ts: `[provider]` → un valor cualquiera. */
function urlOf(file: string): string {
  const segments = relative(API_DIR, dirname(file)).split(sep);
  return `/api/${segments.map((s) => s.replace(/^\[.+\]$/, "ejemplo")).join("/")}`;
}

const routes = routeFiles().map((file) => ({ file, url: urlOf(file) }));

/** Rutas que, por naturaleza, las llama otro servidor y no un navegador. */
const isWebhookFamily = (url: string) =>
  url === "/api/revalidate" || url.includes("/webhook");

describe("la lista frente a los route.ts reales", () => {
  it("encuentra las rutas (el recorrido no está vacío)", () => {
    expect(routes.length).toBeGreaterThan(20);
    expect(routes.some((r) => r.url === "/api/revalidate")).toBe(true);
  });

  it("toda ruta de webhook o revalidación está en la lista", () => {
    const olvidadas = routes
      .filter((r) => isWebhookFamily(r.url) && !isSignedRoute(r.url))
      .map((r) => r.url);

    expect(olvidadas).toEqual([]);
  });

  it("no se exime ninguna otra ruta", () => {
    const sobrantes = routes
      .filter((r) => isSignedRoute(r.url) && !isWebhookFamily(r.url))
      .map((r) => r.url);

    expect(sobrantes).toEqual([]);
  });

  describe("cada ruta eximida verifica su firma antes de actuar", () => {
    // Heurística sobre el código fuente; el comportamiento real de cada
    // verificador lo prueban los tests de su propia ruta.
    const VERIFIER =
      /(?<!function )\b(?:verifyWooWebhook|verifyHweSignature|verifyHmacSignature)\(|\.verifyWebhook\(/;
    const SIDE_EFFECT =
      /\b(?:revalidateTag|markEventOnce|dispatchOrderEffects|recordAndDiffStatus)\(/;

    for (const { file, url } of routes.filter((r) => isSignedRoute(r.url))) {
      it(url, () => {
        const source = readFileSync(file, "utf8");
        const verifier = source.search(VERIFIER);
        const sideEffect = source.search(SIDE_EFFECT);

        expect(
          verifier,
          "no llama a ningún verificador de firma",
        ).toBeGreaterThanOrEqual(0);
        if (sideEffect >= 0) {
          expect(verifier, "actúa antes de verificar la firma").toBeLessThan(
            sideEffect,
          );
        }
      });
    }
  });
});
