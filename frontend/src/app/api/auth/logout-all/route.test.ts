import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { clearAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { revokeToken } from "@/lib/auth/revocation";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/logout-all: cierra TODAS las sesiones. El carrito de la cuenta
//  vive en WooCommerce, ligado al cliente: se vacía solo si WordPress confirmó
//  el cierre, y la cookie del carrito de este dispositivo se retira.
// ============================================================================

const cookieSet = vi.fn();
vi.mock("next/headers", () => ({ cookies: async () => ({ set: cookieSet }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({
  requireSession: vi.fn(async () => ({ token: "jwt-del-cliente", userId: "7" })),
}));
vi.mock("@/lib/auth/revocation", () => ({ revokeToken: vi.fn(async () => undefined) }));
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  clearAuthenticatedCart: vi.fn(async () => undefined),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const request = () =>
  new Request("https://tienda.example/api/auth/logout-all", { method: "POST" });

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(wpFetch).mockResolvedValue(new Response("{}", { status: 200 }));
});

describe("logout-all y el carrito", () => {
  it("con el cierre confirmado, vacía el carrito de la cuenta con su JWT", async () => {
    const res = await POST(request());

    expect(res.status).toBe(200);
    expect(clearAuthenticatedCart).toHaveBeenCalledWith("jwt-del-cliente");
    expect(revokeToken).toHaveBeenCalledWith("jwt-del-cliente");
  });

  it("si WordPress no cierra las sesiones (502), no se toca el carrito", async () => {
    vi.mocked(wpFetch).mockResolvedValue(new Response("{}", { status: 500 }));

    const res = await POST(request());

    expect(res.status).toBe(502);
    expect(clearAuthenticatedCart).not.toHaveBeenCalled();
  });
});
