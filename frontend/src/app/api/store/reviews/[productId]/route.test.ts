import { beforeEach, describe, expect, it, vi } from "vitest";
import { requireSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { POST } from "./route";

// ============================================================================
//  POST /api/store/reviews/[productId].
//
//  El servidor aceptaba `name` y `email` arbitrarios del cuerpo y los usaba tal
//  cual como autor de la reseña: cualquier petición directa al endpoint podía
//  publicar una reseña atribuida al nombre o al email de un tercero. La UI los
//  mostraba como datos de la cuenta, pero el servidor no lo exigía.
//
//  Ahora el autor SIEMPRE sale del perfil WooCommerce del usuario autenticado.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
// Se conserva `WooCommerceError`: `handleApiError` hace `instanceof` contra él.
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const PROFILE = { first_name: "Ana", last_name: "Pérez", email: "ana@example.com" };
const VALID = { rating: 5, content: "Muy buen producto, lo recomiendo." };

const request = (body: unknown) =>
  new Request("https://tienda.example/api/store/reviews/9", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
const call = (body: unknown, productId = "9") =>
  POST(request(body), { params: Promise.resolve({ productId }) });

/** Llamadas a WooCommerce que NO son la lectura del perfil. */
const reviewPosts = () =>
  vi.mocked(wcFetch).mock.calls.filter(([path]) => path === "/products/reviews");

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(wcFetch).mockImplementation((async (path: string) =>
    path.startsWith("/customers/") ? PROFILE : { id: 55, status: "hold" }) as never);
});

describe("la identidad del autor sale de la cuenta", () => {
  it("ignora el name y el email del cuerpo y usa el perfil del usuario autenticado", async () => {
    const res = await call({
      ...VALID,
      name: "Víctima Suplantada",
      email: "victima@example.com",
    });

    expect(res.status).toBe(201);
    expect(reviewPosts()).toHaveLength(1);
    expect(reviewPosts()[0]![1]).toMatchObject({
      method: "POST",
      body: {
        product_id: 9,
        review: VALID.content,
        reviewer: "Ana Pérez",
        reviewer_email: "ana@example.com",
        rating: 5,
      },
    });
  });

  it("lee el perfil del usuario de la SESIÓN, no uno indicado por el cliente", async () => {
    await call({ ...VALID, userId: 1, customer_id: 1 });

    expect(vi.mocked(wcFetch)).toHaveBeenCalledWith("/customers/7");
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalledWith("/customers/1");
  });

  it("sin nombre en el perfil publica con un texto neutro, nunca con el del cuerpo", async () => {
    vi.mocked(wcFetch).mockImplementation((async (path: string) =>
      path.startsWith("/customers/")
        ? { first_name: "", last_name: "", email: "ana@example.com" }
        : { id: 55, status: "hold" }) as never);

    await call({ ...VALID, name: "Víctima Suplantada" });

    expect(reviewPosts()[0]![1]).toMatchObject({
      body: { reviewer: "Cliente", reviewer_email: "ana@example.com" },
    });
  });

  it("la reseña queda en moderación", async () => {
    await call(VALID);

    expect(reviewPosts()[0]![1]).toMatchObject({ body: { status: "hold" } });
  });

  it("si no se puede leer el perfil, no publica nada", async () => {
    vi.mocked(wcFetch).mockRejectedValue(new Error("woocommerce caído"));

    const res = await call(VALID);

    expect(res.status).toBeGreaterThanOrEqual(500);
    expect(reviewPosts()).toHaveLength(0);
  });
});

describe("validación y acceso", () => {
  it.each([
    ["valoración fuera de rango", { ...VALID, rating: 9 }],
    ["texto demasiado corto", { ...VALID, content: "corto" }],
    ["cuerpo vacío", {}],
  ])("%s: 422 con el detalle y sin tocar WooCommerce", async (_caso, body) => {
    const res = await call(body);
    const json = await res.json();

    expect(res.status).toBe(422);
    expect(json.details).toBeDefined();
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });

  it("un cuerpo `null` es 422 y no revienta", async () => {
    const res = await call(null);

    expect(res.status).toBe(422);
  });

  it("sin sesión responde 401 y no toca WooCommerce", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await call(VALID);

    expect(res.status).toBe(401);
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });

  it("un id de producto inválido responde 400", async () => {
    const res = await call(VALID, "abc");

    expect(res.status).toBe(400);
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });
});
