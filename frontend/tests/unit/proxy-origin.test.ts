import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";

// ============================================================================
//  La barrera de Origin del proxy no puede cerrarle la puerta a los webhooks.
//
//  Una petición server-to-server (WooCommerce, el Control Center, la pasarela)
//  NO trae `Origin` ni `Referer`, y la barrera trataba esa ausencia como origen
//  no permitido: 403 antes de que el handler verificara la firma. Con
//  `ALLOWED_ORIGIN` configurado —es decir, en producción— eso rompía:
//
//   · la revalidación ISR (el catálogo no se refrescaba al publicar);
//   · el dispatcher de pedidos (nunca se enteraba de un cambio de estado);
//   · y, lo más grave, la confirmación de la pasarela, que es LA prueba de que
//     un pedido se cobró.
//
//  Como WooCommerce desactiva un webhook tras cinco entregas fallidas, el efecto
//  era permanente hasta que alguien lo reactivaba a mano.
//
//  Estos tests recorren el proxy REAL con peticiones con la forma exacta de las
//  de un webhook. La exención es una lista cerrada (lib/security/signed-routes):
//  cada ruta verifica su firma, que es una barrera más fuerte que el Origin.
// ============================================================================

const ORIGIN = "https://tienda.example";

async function send(
  path: string,
  {
    method = "POST",
    headers = {},
  }: { method?: string; headers?: Record<string, string> } = {},
) {
  const { proxy } = await import("@/proxy");
  const { NextRequest } = await import("next/server");
  const req = new NextRequest(new URL(path, ORIGIN), {
    method,
    headers: { "content-type": "application/json", ...headers },
  });
  return proxy(req);
}

const post = (path: string, headers: Record<string, string> = {}) =>
  send(path, { headers });

describe("barrera de Origin del proxy", () => {
  beforeEach(() => {
    vi.stubEnv("ALLOWED_ORIGIN", ORIGIN);
  });
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  const webhooks = [
    "/api/revalidate",
    "/api/webhooks/woocommerce/order-created",
    "/api/webhooks/woocommerce/order-updated",
    "/api/payments/webhook/wompi",
    "/api/payments/webhook/avalpay",
  ];

  for (const path of webhooks) {
    it(`deja pasar ${path} sin Origin (lo firma el emisor, no el navegador)`, async () => {
      const res = await post(path);
      expect(res.status).not.toBe(403);
    });
  }

  it("sigue bloqueando una escritura de navegador sin Origin ni Referer", async () => {
    const res = await post("/api/store/cart/items");
    expect(res.status).toBe(403);
  });

  it("sigue bloqueando una escritura con Origin ajeno", async () => {
    const res = await post("/api/store/checkout", {
      origin: "https://atacante.example",
    });
    expect(res.status).toBe(403);
  });

  it("sigue bloqueando una escritura con Referer ajeno", async () => {
    const res = await post("/api/store/checkout", {
      referer: "https://atacante.example/pagina",
    });
    expect(res.status).toBe(403);
  });

  it("deja pasar una escritura del propio sitio", async () => {
    const res = await post("/api/store/checkout", { origin: ORIGIN });
    expect(res.status).not.toBe(403);
  });

  it("no confunde un prefijo parecido con un webhook", async () => {
    // `/api/payments/create` es del navegador: Origin obligatorio.
    const res = await post("/api/payments/create");
    expect(res.status).toBe(403);
  });

  it("la lista es cerrada: una ruta nueva bajo /api/webhooks/ NO queda eximida", async () => {
    // Una ruta nueva debe añadirse a la lista a propósito; un prefijo abierto
    // eximiría en silencio cualquier handler futuro, lo verifique o no.
    const res = await post("/api/webhooks/woocommerce/order-deleted");
    expect(res.status).toBe(403);
  });

  it("un webhook con Origin ajeno también se deja pasar: la firma es su barrera", async () => {
    // La exención es por RUTA, no por ausencia de Origin; el handler rechaza lo
    // que no venga firmado (401), no el proxy.
    const res = await post("/api/revalidate", { origin: "https://atacante.example" });
    expect(res.status).not.toBe(403);
  });

  it("las lecturas no pasan por la barrera", async () => {
    const res = await send("/api/store/products", { method: "GET" });
    expect(res.status).not.toBe(403);
  });
});

describe("barrera de Origin sin ALLOWED_ORIGIN (desarrollo)", () => {
  it("no restringe nada", async () => {
    vi.stubEnv("ALLOWED_ORIGIN", "");
    try {
      const res = await post("/api/store/cart/items");
      expect(res.status).not.toBe(403);
    } finally {
      vi.unstubAllEnvs();
    }
  });
});
