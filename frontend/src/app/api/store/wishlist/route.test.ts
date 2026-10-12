import { beforeEach, describe, expect, it, vi } from "vitest";
import { requireSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { DELETE, POST } from "./route";

// ============================================================================
//  POST / DELETE /api/store/wishlist.
//
//  El cuerpo se leía con un cast manual y una comprobación `typeof === "number"`:
//  aceptaba ids negativos o decimales (acababan guardados en la meta del cliente)
//  y un cuerpo `null` reventaba con un TypeError (500). Ahora lo valida un schema
//  Zod y todo lo inválido es 422 con el detalle, sin tocar WooCommerce.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/security/lock", () => ({
  withLock: vi.fn(async (_key: string, fn: () => Promise<unknown>) => fn()),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const request = (method: "POST" | "DELETE", body: unknown) =>
  new Request("https://tienda.example/api/store/wishlist", {
    method,
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

const wishlistMeta = (ids: number[]) => ({
  meta_data: [{ key: "hwe_wishlist", value: JSON.stringify(ids) }],
});

const puts = () =>
  vi.mocked(wcFetch).mock.calls.filter(([, init]) => init?.method === "PUT");

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(wcFetch).mockResolvedValue(wishlistMeta([1, 2]) as never);
});

describe.each([
  ["POST", POST],
  ["DELETE", DELETE],
] as const)("%s: validación del cuerpo", (method, handler) => {
  it.each([
    ["sin productId", {}],
    ["productId como texto", { productId: "42" }],
    ["productId negativo", { productId: -3 }],
    ["productId cero", { productId: 0 }],
    ["productId decimal", { productId: 1.5 }],
    ["productId nulo", { productId: null }],
  ])("%s: 422 con el detalle y sin tocar WooCommerce", async (_caso, body) => {
    const res = await handler(request(method, body));
    const json = await res.json();

    expect(res.status).toBe(422);
    expect(json.details).toHaveProperty("productId");
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });

  it("un cuerpo `null` es 422 y no revienta con un TypeError", async () => {
    const res = await handler(request(method, null));

    expect(res.status).toBe(422);
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });

  it("un JSON ilegible sigue siendo 400", async () => {
    const res = await handler(
      new Request("https://tienda.example/api/store/wishlist", {
        method,
        headers: { "content-type": "application/json" },
        body: "{no es json",
      }),
    );

    expect(res.status).toBe(400);
  });
});

describe("comportamiento válido", () => {
  it("POST añade el producto a la lista", async () => {
    const res = await POST(request("POST", { productId: 3 }));

    expect(res.status).toBe(200);
    expect(puts()).toHaveLength(1);
    expect(puts()[0]![1]).toMatchObject({
      body: { meta_data: [{ key: "hwe_wishlist", value: "[1,2,3]" }] },
    });
  });

  it("DELETE quita el producto de la lista", async () => {
    const res = await DELETE(request("DELETE", { productId: 2 }));

    expect(res.status).toBe(200);
    expect(puts()[0]![1]).toMatchObject({
      body: { meta_data: [{ key: "hwe_wishlist", value: "[1]" }] },
    });
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await POST(request("POST", { productId: 3 }));

    expect(res.status).toBe(401);
  });
});
