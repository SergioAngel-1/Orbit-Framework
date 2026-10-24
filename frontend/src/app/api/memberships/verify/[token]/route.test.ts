import { beforeEach, describe, expect, it, vi } from "vitest";
import { getMembershipLevels, verifyMembershipCard } from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/verify/[token].
//
//  La única ruta pública del módulo (la abre el QR del carné). Rate-limit por
//  IP, formato del token validado antes de tocar WordPress y una respuesta
//  idéntica para CUALQUIER token no válido: no se puede distinguir si no existe,
//  caducó o se revocó. Nunca se cachea una respuesta válida.
// ============================================================================

vi.mock("@/lib/memberships/server", () => ({
  verifyMembershipCard: vi.fn(),
  getMembershipLevels: vi.fn(),
}));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: {
    modules: { memberships: true as boolean },
    memberships: { card_enabled: true as boolean },
  },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const TOKEN = "d".repeat(64);
const VERIFIED = {
  valid: true as const,
  level: { name: "Socio", slug: "socio" },
  member_since: "2026-01-02T03:04:05Z",
  member_number: "SOC-000123",
  member_name: "Ana P.",
};
const LEVEL = {
  id: 101,
  name: "Socia",
  description: "",
  slug: "socio",
  slugs: { en: "member" },
  rank: 1,
  icon: "",
  color: "",
  purchasable: true,
  min_account_age_days: 0,
  benefits: [],
  products: [],
};

const params = (token: string) => ({ params: Promise.resolve({ token }) });
const request = (ip = "203.0.113.9", locale = "es") =>
  new Request(`https://tienda.example/api/memberships/verify/${TOKEN}`, {
    headers: { "x-forwarded-for": ip, "x-hwe-locale": locale },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  state.site.memberships.card_enabled = true;
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 29,
    retryAfter: 0,
    limit: 30,
  });
  vi.mocked(verifyMembershipCard).mockResolvedValue(VERIFIED);
  vi.mocked(getMembershipLevels).mockResolvedValue([LEVEL]);
});

describe("GET /api/memberships/verify/[token]", () => {
  it("devuelve la verificación mínima con el nombre del nivel traducido", async () => {
    const res = await GET(request(), params(TOKEN));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({ ...VERIFIED, level: { name: "Socia", slug: "socio" } });
    expect(verifyMembershipCard).toHaveBeenCalledWith(TOKEN);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("si el nivel no está en la lista, conserva el nombre que manda WordPress", async () => {
    vi.mocked(getMembershipLevels).mockResolvedValue([]);

    const json = await (await GET(request(), params(TOKEN))).json();

    expect(json.level.name).toBe("Socio");
  });

  it("si falla la lectura de niveles (contenido) no rompe la verificación", async () => {
    vi.mocked(getMembershipLevels).mockRejectedValue(
      new MembershipsReadError("unavailable", 503),
    );

    const json = await (await GET(request(), params(TOKEN))).json();

    expect(json.valid).toBe(true);
    expect(json.level.name).toBe("Socio");
    expect(vi.mocked(logger.warn)).toHaveBeenCalledWith(
      expect.objectContaining({ event: "memberships.verify_levels_failed" }),
      expect.any(String),
    );
  });

  it("un token con formato imposible da la misma respuesta que uno inválido", async () => {
    const malformed = await GET(request(), params("no-es-un-token"));
    const malformedBody = await malformed.json();

    expect(malformed.status).toBe(200);
    expect(malformedBody).toEqual({ valid: false });
    expect(verifyMembershipCard).not.toHaveBeenCalled();

    vi.mocked(verifyMembershipCard).mockResolvedValue({ valid: false });
    const unknown = await GET(request(), params(TOKEN));

    expect(await unknown.json()).toEqual(malformedBody);
    expect(verifyMembershipCard).toHaveBeenCalledWith(TOKEN);
  });

  it("aplica rate-limit por IP antes de consultar el carné", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 30,
      limit: 30,
    });

    const res = await GET(request("198.51.100.4"), params(TOKEN));

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("30");
    expect(rateLimit).toHaveBeenCalledWith("memberships-verify:198.51.100.4", 30, 60);
    expect(verifyMembershipCard).not.toHaveBeenCalled();
  });

  it("con el módulo o el carné apagados responde 404", async () => {
    state.site.modules.memberships = false;
    expect((await GET(request(), params(TOKEN))).status).toBe(404);

    state.site.modules.memberships = true;
    state.site.memberships.card_enabled = false;
    expect((await GET(request(), params(TOKEN))).status).toBe(404);

    expect(rateLimit).not.toHaveBeenCalled();
    expect(verifyMembershipCard).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es un 5xx tipado, nunca `{ valid: false }`", async () => {
    vi.mocked(verifyMembershipCard).mockRejectedValue(
      new MembershipsReadError("unavailable", 503),
    );

    const res = await GET(request(), params(TOKEN));
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.valid).toBeUndefined();
    expect(json.code).toBe("unavailable");
  });
});
