import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  Captura de `?ref=<código>` en el proxy (spec §9.3).
//
//  El proxy corre en EDGE: no puede consultar WordPress ni la config, así que
//  deja la cookie con cualquier código que tenga el formato correcto. Quien
//  decide si se usa es el registro del BFF (que sí conoce el módulo y los
//  interruptores). Por eso la cookie se deja también con el módulo apagado.
//
//  Reglas que fija este test:
//    · httpOnly, 30 días, SameSite=Lax, Secure en producción y path=/;
//    · el último código reemplaza al anterior;
//    · un formato inválido no deja cookie ni borra la que ya hubiera;
//    · la URL no cambia (sin redirección para quitar el parámetro).
// ============================================================================

const ORIGIN = "https://tienda.example";
const REF_COOKIE = "hwe_ref";
/** 30 días en segundos. */
const THIRTY_DAYS = 30 * 24 * 60 * 60;

async function requestPage(url: string, cookie?: string) {
  const { proxy } = await import("@/proxy");
  const { NextRequest } = await import("next/server");
  const req = new NextRequest(new URL(url, ORIGIN), {
    method: "GET",
    headers: cookie ? { cookie } : {},
  });
  return proxy(req);
}

/** `set-cookie` de la respuesta para esa cookie, si la hay. */
function setCookieFor(res: Response, name: string): string | undefined {
  return res.headers.getSetCookie().find((cookie) => cookie.startsWith(`${name}=`));
}

describe("captura de ?ref= en el proxy", () => {
  beforeEach(() => {
    vi.resetModules();
    vi.stubEnv("ALLOWED_ORIGIN", ORIGIN);
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });

  it("deja hwe_ref httpOnly, 30 días, SameSite=Lax y path=/ con un código válido", async () => {
    const res = await requestPage("/tienda?ref=ABCD2345");

    const cookie = setCookieFor(res, REF_COOKIE);
    expect(cookie).toBeDefined();
    expect(cookie).toContain(`${REF_COOKIE}=ABCD2345`);
    expect(cookie).toContain("HttpOnly");
    expect(cookie).toContain(`Max-Age=${THIRTY_DAYS}`);
    expect(cookie).toMatch(/SameSite=lax/i);
    expect(cookie).toContain("Path=/");
    // En desarrollo no es Secure: la cookie debe viajar por HTTP local.
    expect(cookie).not.toContain("Secure");
  });

  it("en producción la cookie es Secure", async () => {
    vi.stubEnv("NODE_ENV", "production");

    const res = await requestPage("/tienda?ref=ABCD2345");

    expect(setCookieFor(res, REF_COOKIE)).toContain("Secure");
  });

  it("normaliza el código (mayúsculas y sin espacios) antes de guardarlo", async () => {
    const res = await requestPage("/tienda?ref=%20abcd2345%20");

    expect(setCookieFor(res, REF_COOKIE)).toContain(`${REF_COOKIE}=ABCD2345`);
  });

  it("un formato inválido no deja cookie", async () => {
    const res = await requestPage("/tienda?ref=no-es-un-codigo");

    expect(setCookieFor(res, REF_COOKIE)).toBeUndefined();
  });

  it("un formato inválido no borra la cookie que ya hubiera", async () => {
    const res = await requestPage(
      "/tienda?ref=no-es-un-codigo",
      `${REF_COOKIE}=ZZZZ2222`,
    );

    // Sin `set-cookie` de hwe_ref la cookie del navegador queda intacta.
    expect(setCookieFor(res, REF_COOKIE)).toBeUndefined();
  });

  it("el último código reemplaza al anterior", async () => {
    const res = await requestPage("/tienda?ref=QRST3456", `${REF_COOKIE}=ZZZZ2222`);

    const cookie = setCookieFor(res, REF_COOKIE);
    expect(cookie).toContain(`${REF_COOKIE}=QRST3456`);
    expect(cookie).not.toContain("ZZZZ2222");
  });

  it("la URL no cambia: no hay redirección para quitar el parámetro", async () => {
    const res = await requestPage("/tienda?ref=ABCD2345");

    expect(res.status).toBe(200);
    expect(res.headers.get("location")).toBeNull();
  });

  it("una ruta de la API no captura el código", async () => {
    const res = await requestPage("/api/health?ref=ABCD2345");

    expect(setCookieFor(res, REF_COOKIE)).toBeUndefined();
  });

  it("sin ?ref= no toca la cookie", async () => {
    const res = await requestPage("/tienda");

    expect(setCookieFor(res, REF_COOKIE)).toBeUndefined();
  });
});
