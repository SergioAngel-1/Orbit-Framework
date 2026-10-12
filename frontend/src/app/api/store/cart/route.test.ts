import { beforeEach, describe, expect, it, vi } from "vitest";
import { rateLimit } from "@/lib/security/rate-limit";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { GET } from "./route";

// ============================================================================
//  GET /api/store/cart — rate-limit de la lectura.
//
//  La lectura no pasa por `guardMutation` (no es una escritura) y cada una acaba
//  en la Store API de WordPress, sin caché: PHP pesado que ocupa un worker de
//  Apache. Un navegador en bucle la pidió ~70 veces por minuto y dejó a WordPress
//  sin workers para el resto del sitio.
// ============================================================================

vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  readCartToken: vi.fn(),
  writeCartToken: vi.fn(),
}));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const OK = { success: true, remaining: 10, retryAfter: 0, limit: 30 };
const CART = { items: [], items_count: 0, totals: { total_price: "0" } };

const request = (ip = "203.0.113.9") =>
  new Request("https://tienda.example/api/store/cart", {
    headers: { "x-forwarded-for": ip },
  });

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(rateLimit).mockResolvedValue(OK);
  vi.mocked(readCartToken).mockResolvedValue(undefined as never);
  vi.mocked(storeFetch).mockResolvedValue({ data: CART, cartToken: "nuevo" } as never);
});

describe("GET /api/store/cart", () => {
  it("una lectura normal pasa y devuelve el carrito", async () => {
    const res = await GET(request());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(writeCartToken).toHaveBeenCalledWith("nuevo");
  });

  it("superar el límite da 429 con Retry-After ANTES de tocar WordPress", async () => {
    vi.mocked(rateLimit).mockResolvedValue({ ...OK, success: false, retryAfter: 17 });

    const res = await GET(request());

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("17");
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("sin carrito, la clave es por IP y «anon»", async () => {
    await GET(request("198.51.100.4"));

    expect(rateLimit).toHaveBeenCalledWith("cart_read:198.51.100.4:anon", 30, 60);
  });

  it("con carrito, la clave lleva el token HASHEADO: la credencial no queda en claro en Redis", async () => {
    vi.mocked(readCartToken).mockResolvedValue("TOKEN-SECRETO-DEL-CARRITO" as never);

    await GET(request("198.51.100.4"));

    const key = vi.mocked(rateLimit).mock.calls[0]![0];
    expect(key).toMatch(/^cart_read:198\.51\.100\.4:[0-9a-f]{16}$/);
    expect(key).not.toContain("TOKEN-SECRETO-DEL-CARRITO");
  });

  it("dos carritos distintos tras la misma IP (CGNAT) no comparten límite", async () => {
    vi.mocked(readCartToken).mockResolvedValueOnce("carrito-A" as never);
    await GET(request());
    vi.mocked(readCartToken).mockResolvedValueOnce("carrito-B" as never);
    await GET(request());

    const [a, b] = vi.mocked(rateLimit).mock.calls.map((c) => c[0]);
    expect(a).not.toBe(b);
  });
});
