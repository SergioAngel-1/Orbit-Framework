import { describe, expect, it, vi } from "vitest";

// ============================================================================
//  El proxy del framework con las rutas públicas de la instancia:
//    - la URL pública se REESCRIBE a la carpeta interna (misma vista, URL bonita);
//    - la URL interna antigua responde 308 a la pública (sin contenido duplicado).
//  Se ejecuta el proxy REAL con `NextRequest`, como en los demás tests de proxy.
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/products": "/productos",
      "/checkout": "/pago",
      "/account": "/cuenta",
    },
  },
}));

const ORIGIN = "https://tienda.example";

async function get(path: string) {
  const { proxy } = await import("@/proxy");
  const { NextRequest } = await import("next/server");
  return proxy(new NextRequest(new URL(path, ORIGIN)));
}

describe("proxy con rutas públicas", () => {
  it("reescribe la URL pública a la carpeta interna", async () => {
    const res = await get("/productos/vela-roja?color=rojo");
    expect(res.status).toBe(200);
    expect(res.headers.get("x-middleware-rewrite")).toBe(
      `${ORIGIN}/es/products/vela-roja?color=rojo`,
    );
  });

  it("conserva el prefijo de idioma al reescribir", async () => {
    const res = await get("/en/productos/vela-roja");
    expect(res.headers.get("x-middleware-rewrite")).toBe(
      `${ORIGIN}/en/products/vela-roja`,
    );
  });

  it("redirige la ruta interna a la pública con 308", async () => {
    const res = await get("/products/vela-roja?color=rojo");
    expect(res.status).toBe(308);
    expect(res.headers.get("location")).toBe(
      `${ORIGIN}/productos/vela-roja?color=rojo`,
    );
  });

  it("el 308 también cubre los hijos heredados", async () => {
    const res = await get("/en/account/orders/7");
    expect(res.status).toBe(308);
    expect(res.headers.get("location")).toBe(`${ORIGIN}/en/cuenta/orders/7`);
  });

  it("una ruta sin remapear sigue igual que antes (sin redirect)", async () => {
    const res = await get("/about");
    expect(res.status).toBe(200);
    expect(res.headers.get("location")).toBeNull();
    // El rewrite al tramo interno del idioma es el de siempre (next-intl lo hace igual).
    expect(res.headers.get("x-middleware-rewrite")).toBe(`${ORIGIN}/es/about`);
  });
});
