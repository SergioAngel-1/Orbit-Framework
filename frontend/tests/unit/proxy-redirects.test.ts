import { describe, expect, it, vi } from "vitest";
import { compileRedirects } from "@/lib/instance/redirects";

// ============================================================================
//  El proxy REAL con las redirecciones de la instancia: la URL antigua responde
//  301 antes de que next-intl la trate como una página (y la pinte en 404).
// ============================================================================

vi.mock("@/instance/registry", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/instance/registry")>();
  return {
    ...actual,
    instanceRegistry: {
      ...actual.instanceRegistry,
      redirects: [
        { from: "/producto/*", to: "/products/*" },
        { from: "/quienes-somos", to: "/about" },
      ],
    },
  };
});

const ORIGIN = "https://tienda.example";

async function get(path: string) {
  const { proxy } = await import("@/proxy");
  const { NextRequest } = await import("next/server");
  return proxy(new NextRequest(new URL(path, ORIGIN)));
}

describe("proxy con redirecciones de la instancia", () => {
  it("la URL antigua responde 301 a la nueva, con su consulta", async () => {
    const res = await get("/producto/vela-roja/?utm_source=old");
    expect(res.status).toBe(301);
    expect(res.headers.get("location")).toBe(
      `${ORIGIN}/products/vela-roja?utm_source=old`,
    );
  });

  it("una ruta sin regla sigue su camino", async () => {
    const res = await get("/about");
    expect(res.status).not.toBe(301);
  });
});

describe("contrato: las redirecciones del registro real son válidas", () => {
  it("compilan en modo estricto", async () => {
    const { instanceRegistry } =
      await vi.importActual<typeof import("@/instance/registry")>(
        "@/instance/registry",
      );
    expect(() =>
      compileRedirects(instanceRegistry.redirects, { strict: true }),
    ).not.toThrow();
  });
});
