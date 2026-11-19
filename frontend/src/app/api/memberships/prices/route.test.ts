import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { logger } from "@/lib/observability/logger";
import {
  getMembershipPrices,
  getPublicMembershipPrices,
} from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { rateLimit } from "@/lib/security/rate-limit";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/prices?ids=…
//
//  «Precio para ti» de una lista acotada de productos. Con sesión pregunta a la
//  misma función que el carrito; sin sesión devuelve los precios públicos. El
//  tope de 50 ids se valida también aquí (antes de tocar WordPress) y la ruta,
//  que es anónima, va acotada por IP.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/memberships/server", () => ({
  getMembershipPrices: vi.fn(),
  getPublicMembershipPrices: vi.fn(),
}));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: { modules: { memberships: true as boolean } },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const MEMBER_QUOTE = {
  id: 601,
  regular: "25.00",
  price: "20.00",
  member_price: "18.00",
  percent: 10,
};
const PUBLIC_QUOTE = {
  id: 601,
  regular: "25.00",
  price: "20.00",
  member_price: "20.00",
  percent: 0,
};

const request = (query = "", ip = "203.0.113.9") =>
  new Request(`https://tienda.example/api/memberships/prices${query}`, {
    headers: { "x-forwarded-for": ip },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  vi.mocked(getSession).mockResolvedValue(null);
  vi.mocked(getMembershipPrices).mockResolvedValue([MEMBER_QUOTE]);
  vi.mocked(getPublicMembershipPrices).mockResolvedValue([PUBLIC_QUOTE]);
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 119,
    retryAfter: 0,
    limit: 120,
  });
});

describe("GET /api/memberships/prices", () => {
  it("sin sesión devuelve los precios públicos", async () => {
    const res = await GET(request("?ids=601"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({ items: [PUBLIC_QUOTE] });
    expect(getPublicMembershipPrices).toHaveBeenCalledWith([601]);
    expect(getMembershipPrices).not.toHaveBeenCalled();
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("con sesión usa el user_id verificado, nunca el de la query", async () => {
    vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });

    await GET(request("?ids=601&user_id=999"));

    expect(getMembershipPrices).toHaveBeenCalledWith(7, [601]);
    expect(getPublicMembershipPrices).not.toHaveBeenCalled();
  });

  it("acepta ids separados por comas o espacios, los deduplica y conserva el orden", async () => {
    await GET(request("?ids=602,%20601%20602&ids=abc,603"));

    expect(getPublicMembershipPrices).toHaveBeenCalledWith([602, 601, 603]);
  });

  it("sin ids responde una lista vacía sin consultar a WordPress", async () => {
    const res = await GET(request());

    expect(res.status).toBe(200);
    await expect(res.json()).resolves.toEqual({ items: [] });
    expect(getPublicMembershipPrices).not.toHaveBeenCalled();
  });

  it("más de 50 ids es un 400 tipado", async () => {
    const ids = Array.from({ length: 51 }, (_, index) => index + 1).join(",");

    const res = await GET(request(`?ids=${ids}`));
    const json = await res.json();

    expect(res.status).toBe(400);
    expect(json.code).toBe("invalid_request");
    expect(getPublicMembershipPrices).not.toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404", async () => {
    state.site.modules.memberships = false;

    expect((await GET(request("?ids=601"))).status).toBe(404);
    expect(getPublicMembershipPrices).not.toHaveBeenCalled();
    expect(rateLimit).not.toHaveBeenCalled();
  });

  it("aplica un rate-limit no estricto por IP antes de leer precios", async () => {
    await GET(request("?ids=601", "198.51.100.4"));

    expect(rateLimit).toHaveBeenCalledWith("memberships-prices:198.51.100.4", 120, 60);
    // No estricto: sin Redis la ruta degrada a servir, no a bloquear.
    expect(vi.mocked(rateLimit).mock.calls[0]?.[3]).toBeUndefined();
  });

  it("con el presupuesto agotado responde 429 con Retry-After y sin tocar WordPress", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 42,
      limit: 120,
    });

    const res = await GET(request("?ids=601"));
    const json = await res.json();

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("42");
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(json.code).toBeUndefined();
    expect(getPublicMembershipPrices).not.toHaveBeenCalled();
    expect(getMembershipPrices).not.toHaveBeenCalled();
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "memberships.prices_rate_limited" }),
      expect.any(String),
    );
  });

  it("el rate-limit también se aplica a la rama con sesión", async () => {
    vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 10,
      limit: 120,
    });

    expect((await GET(request("?ids=601"))).status).toBe(429);
    expect(getMembershipPrices).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es un 5xx tipado", async () => {
    vi.mocked(getPublicMembershipPrices).mockRejectedValue(
      new MembershipsReadError("unavailable", 503),
    );

    const res = await GET(request("?ids=601"));
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.items).toBeUndefined();
  });
});
