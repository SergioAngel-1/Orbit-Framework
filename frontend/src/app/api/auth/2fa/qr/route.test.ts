import { beforeEach, describe, expect, it, vi, type Mock } from "vitest";
import QRCode from "qrcode";
import { FakeRedis } from "../../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { requireSession } from "@/lib/auth/session";
import { getCustomer } from "@/lib/account/data";
import { getSiteConfig } from "@/lib/config";
import { setPending2faSecret } from "@/lib/auth/two-factor-pending";
import { logger } from "@/lib/observability/logger";
import { GET } from "./route";

// ============================================================================
//  GET /api/auth/2fa/qr — el QR del alta de 2FA, generado en NUESTRO servidor.
//
//  Antes el secreto TOTP compartido viajaba a api.qrserver.com dentro de la URL
//  (queda en sus logs y en el historial del navegador) y, después, en el query
//  string de este mismo endpoint (logs de servidor y de proxy, Referer). Ahora
//  el endpoint exige sesión, lee el secreto pendiente que `POST /2fa/setup` ya
//  guardó en el servidor y no acepta NADA del cliente.
// ============================================================================

const SECRET = "JBSWY3DPEHPK3PXP";

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/account/data", () => ({ getCustomer: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
// Se espía la generación del QR pero se deja la implementación real.
// (qrcode es CommonJS con `export =`: sus tipos no declaran `default`, pero en
// ejecución el módulo se entrega como `default`.)
vi.mock("qrcode", async (importOriginal) => {
  const { default: actual } = await importOriginal<{
    default: typeof import("qrcode");
  }>();
  return { default: { ...actual, toString: vi.fn(actual.toString) } };
});

let redis: FakeRedis;

/** URI que el servidor codificó en el último QR. */
function encodedUri(): URL {
  const calls = (QRCode.toString as unknown as Mock).mock.calls;
  return new URL(String(calls[calls.length - 1]![0]));
}

beforeEach(async () => {
  vi.clearAllMocks();
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getCustomer).mockResolvedValue({ email: "ana@example.com" } as never);
  vi.mocked(getSiteConfig).mockResolvedValue({ brand: { name: "Mi Tienda" } } as never);
  await setPending2faSecret("7", SECRET);
});

describe("GET /api/auth/2fa/qr", () => {
  it("sin sesión responde 401 y no lee el secreto", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await GET();

    expect(res.status).toBe(401);
    expect(QRCode.toString).not.toHaveBeenCalled();
  });

  it("sin secreto pendiente responde 404", async () => {
    redis.expireNow("2fa_pending:7");

    const res = await GET();

    expect(res.status).toBe(404);
    expect(QRCode.toString).not.toHaveBeenCalled();
  });

  it("sin Redis responde 404: falla cerrado, no inventa un secreto", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    const res = await GET();

    expect(res.status).toBe(404);
  });

  it("devuelve un SVG real con Cache-Control no-store (la imagen contiene el secreto)", async () => {
    const res = await GET();
    const body = await res.text();

    expect(res.status).toBe(200);
    expect(res.headers.get("content-type")).toContain("image/svg+xml");
    expect(res.headers.get("cache-control")).toBe("no-store");
    expect(body).toMatch(/^<svg\b/);
  });

  it("codifica el secreto PENDIENTE del servidor con el email y el nombre del sitio", async () => {
    await GET();

    const uri = encodedUri();
    expect(uri.protocol).toBe("otpauth:");
    expect(uri.host).toBe("totp");
    expect(uri.searchParams.get("secret")).toBe(SECRET);
    expect(uri.searchParams.get("issuer")).toBe("Mi Tienda");
    expect(decodeURIComponent(uri.pathname)).toBe("/Mi Tienda:ana@example.com");
  });

  it("ignora por completo un ?otpauth= del cliente: no es un generador de QR arbitrarios", async () => {
    const evil = "otpauth://totp/Evil:x?secret=SECRETOAJENO&issuer=Evil";
    const handler = GET as unknown as (request: Request) => Promise<Response>;

    await handler(
      new Request(
        `https://tienda.example/api/auth/2fa/qr?otpauth=${encodeURIComponent(evil)}`,
      ),
    );

    expect(encodedUri().searchParams.get("secret")).toBe(SECRET);
    expect(
      String((QRCode.toString as unknown as Mock).mock.calls[0]![0]),
    ).not.toContain("SECRETOAJENO");
  });

  it("cada usuario obtiene el QR de SU secreto, no el de otro", async () => {
    await setPending2faSecret("8", "SECRETODEOTRO");

    await GET();

    expect(encodedUri().searchParams.get("secret")).toBe(SECRET);
  });

  it("si no se puede resolver el email, etiqueta con 'user' en lugar de fallar", async () => {
    vi.mocked(getCustomer).mockRejectedValue(new Error("WP caído"));

    const res = await GET();

    expect(res.status).toBe(200);
    expect(decodeURIComponent(encodedUri().pathname)).toBe("/Mi Tienda:user");
  });

  it("sin nombre de marca usa el del framework, nunca una variable de entorno", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({ brand: { name: "  " } } as never);

    await GET();

    expect(encodedUri().searchParams.get("issuer")).toBeTruthy();
    expect(encodedUri().searchParams.get("issuer")?.trim()).not.toBe("");
  });

  it("si falla el render responde 500 sin registrar el secreto ni la URI", async () => {
    (QRCode.toString as unknown as Mock).mockRejectedValueOnce(
      new Error("render roto"),
    );

    const res = await GET();

    expect(res.status).toBe(500);
    const logged = JSON.stringify(vi.mocked(logger.error).mock.calls);
    expect(logged).toContain("auth.2fa_qr.render_error");
    expect(logged).not.toContain(SECRET);
    expect(logged).not.toContain("otpauth");
  });
});
