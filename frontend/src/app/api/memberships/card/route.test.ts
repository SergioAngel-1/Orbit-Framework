import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getMembershipCard } from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/card.
//
//  Emite el carné del socio de la sesión y compone la URL absoluta del QR con
//  `config.brand.url` (nunca con WordPress). Exige el interruptor del carné y un
//  nivel efectivo: sin él, 403 tipado.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/memberships/server", () => ({
  getMembershipCard: vi.fn(),
}));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  fallback: false,
  site: {
    modules: { memberships: true as boolean },
    memberships: {
      card_enabled: true as boolean,
      card_verify_path: "/verify",
      member_number_prefix: "",
    },
    brand: { url: "https://tienda.example" },
  },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
  isFallbackSiteConfig: vi.fn(() => state.fallback),
}));

const ISSUED = {
  token: "a".repeat(64),
  expires_at: "2026-01-02T00:00:00Z",
  verify_path: "/verify",
};

const request = () => new Request("https://tienda.example/api/memberships/card");

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  state.site.memberships.card_enabled = true;
  state.site.memberships.card_verify_path = "/verify";
  state.fallback = false;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getMembershipCard).mockResolvedValue(ISSUED);
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 9,
    retryAfter: 0,
    limit: 10,
  });
});

describe("GET /api/memberships/card", () => {
  it("compone la URL del QR con la marca del frontend", async () => {
    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({
      token: ISSUED.token,
      expires_at: ISSUED.expires_at,
      verify_url: `https://tienda.example/verify/${ISSUED.token}`,
    });
    expect(getMembershipCard).toHaveBeenCalledWith(7);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("usa la ruta del carné de la configuración si WordPress no la manda", async () => {
    vi.mocked(getMembershipCard).mockResolvedValue({ ...ISSUED, verify_path: "" });
    state.site.memberships.card_verify_path = "/socio/verificar";

    const res = await GET(request());
    const json = await res.json();

    expect(json.verify_url).toBe(
      `https://tienda.example/socio/verificar/${ISSUED.token}`,
    );
  });

  it("limita por socio cuántos carnés se emiten: cada petición inserta un token", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 42,
      limit: 10,
    });

    const res = await GET(request());

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("42");
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(getMembershipCard).not.toHaveBeenCalled();
    expect(vi.mocked(rateLimit).mock.calls[0]?.[0]).toBe("memberships-card:7");
  });

  it("sin sesión responde 401 y no emite el carné", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    expect((await GET(request())).status).toBe(401);
    expect(getMembershipCard).not.toHaveBeenCalled();
  });

  it("con el módulo o el carné apagados responde 404", async () => {
    state.site.modules.memberships = false;
    expect((await GET(request())).status).toBe(404);

    state.site.modules.memberships = true;
    state.site.memberships.card_enabled = false;
    expect((await GET(request())).status).toBe(404);

    expect(getMembershipCard).not.toHaveBeenCalled();
  });

  it("sin nivel efectivo propaga el 403 tipado", async () => {
    vi.mocked(getMembershipCard).mockRejectedValue(
      new MembershipsReadError("forbidden", 403, "membership_card_unavailable"),
    );

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(403);
    expect(json.code).toBe("membership_card_unavailable");
  });

  it("una marca sin URL absoluta válida falla tipado y queda en el log", async () => {
    state.site.brand.url = "tienda.example";

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(500);
    expect(json.code).toBe("invalid_config");
    expect(vi.mocked(logger.error)).toHaveBeenCalledWith(
      expect.objectContaining({ event: "memberships.card_invalid_base_url" }),
      expect.any(String),
    );
  });

  it("una configuración de respaldo tampoco publica una URL falsa", async () => {
    state.fallback = true;

    expect((await GET(request())).status).toBe(500);
  });
});
