import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  Política de cookies compartida entre el BFF y el proxy.
//
//  `lib/security/cookies.ts` importa `server-only` y no puede entrar en el
//  bundle de edge de `proxy.ts`. La política base vive ahora en
//  `cookie-policy.ts` (pura): estos tests fijan que las cookies que ya existían
//  conservan EXACTAMENTE las mismas opciones después de la extracción, y que la
//  base es la que se espera en desarrollo y en producción.
// ============================================================================

describe("opciones base de las cookies", () => {
  beforeEach(() => {
    vi.resetModules();
    vi.stubEnv("COOKIE_DOMAIN", "");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("en desarrollo: httpOnly, SameSite=Lax, path=/ y sin Secure", async () => {
    const { baseCookieOptions } = await import("./cookie-policy");

    expect(baseCookieOptions()).toEqual({
      httpOnly: true,
      secure: false,
      sameSite: "lax",
      path: "/",
    });
  });

  it("en producción: Secure", async () => {
    vi.stubEnv("NODE_ENV", "production");
    const { baseCookieOptions } = await import("./cookie-policy");

    expect(baseCookieOptions()).toEqual({
      httpOnly: true,
      secure: true,
      sameSite: "lax",
      path: "/",
    });
  });

  it("con COOKIE_DOMAIN, las cookies se comparten entre subdominios", async () => {
    vi.stubEnv("COOKIE_DOMAIN", ".tienda.example");
    const { baseCookieOptions } = await import("./cookie-policy");

    expect(baseCookieOptions().domain).toBe(".tienda.example");
  });
});

describe("las cookies existentes conservan sus opciones", () => {
  beforeEach(() => {
    vi.resetModules();
    vi.stubEnv("COOKIE_DOMAIN", "");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("sesión y refresh: httpOnly, Lax, path raíz y su maxAge", async () => {
    const { sessionCookieOptions, refreshCookieOptions } = await import("./cookies");

    expect(sessionCookieOptions(300)).toEqual({
      httpOnly: true,
      secure: false,
      sameSite: "lax",
      path: "/",
      maxAge: 300,
    });
    expect(refreshCookieOptions(600)).toEqual({
      httpOnly: true,
      secure: false,
      sameSite: "lax",
      path: "/",
      maxAge: 600,
    });
  });

  it("CSRF: la única que NO es httpOnly (el cliente la reenvía)", async () => {
    const { csrfCookieOptions } = await import("./cookies");

    expect(csrfCookieOptions(120)).toEqual({
      httpOnly: false,
      secure: false,
      sameSite: "lax",
      path: "/",
      maxAge: 120,
    });
  });

  it("comprobante de consentimiento: httpOnly con su maxAge", async () => {
    const { consentReceiptCookieOptions } = await import("./cookies");

    expect(consentReceiptCookieOptions(90)).toEqual({
      httpOnly: true,
      secure: false,
      sameSite: "lax",
      path: "/",
      maxAge: 90,
    });
  });

  it("borrado: maxAge 0 y path configurable", async () => {
    const { expiredCookieOptions } = await import("./cookies");

    expect(expiredCookieOptions()).toEqual({
      httpOnly: true,
      secure: false,
      sameSite: "lax",
      path: "/",
      maxAge: 0,
    });
    expect(expiredCookieOptions("/checkout").path).toBe("/checkout");
  });

  it("en producción todas son Secure", async () => {
    vi.stubEnv("NODE_ENV", "production");
    const { sessionCookieOptions, csrfCookieOptions, consentReceiptCookieOptions } =
      await import("./cookies");

    expect(sessionCookieOptions(300).secure).toBe(true);
    expect(csrfCookieOptions(120).secure).toBe(true);
    expect(consentReceiptCookieOptions(90).secure).toBe(true);
  });

  it("con COOKIE_DOMAIN se propaga a todas las cookies", async () => {
    vi.stubEnv("COOKIE_DOMAIN", ".tienda.example");
    const { sessionCookieOptions, csrfCookieOptions, expiredCookieOptions } =
      await import("./cookies");

    expect(sessionCookieOptions(300).domain).toBe(".tienda.example");
    expect(csrfCookieOptions(120).domain).toBe(".tienda.example");
    expect(expiredCookieOptions().domain).toBe(".tienda.example");
  });
});
