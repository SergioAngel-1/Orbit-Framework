// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from "vitest";
import { authApi } from "./auth";
import { BffError } from "./bff-error";
import { csrfFetch } from "./csrf";

// ============================================================================
//  Cliente de autenticación del núcleo: forma de las peticiones y lectura de
//  las respuestas del BFF (2FA, errores con detalle por campo).
// ============================================================================

vi.mock("./csrf", () => ({ csrfFetch: vi.fn() }));

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });

beforeEach(() => {
  vi.mocked(csrfFetch).mockReset();
});

describe("authApi", () => {
  it("login: sesión iniciada", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(json(200, { user: { id: "1" } }));
    await expect(authApi.login({ username: "a", password: "b" })).resolves.toEqual({
      status: "signed-in",
    });
    expect(csrfFetch).toHaveBeenCalledWith("/api/auth/login", {
      method: "POST",
      body: { username: "a", password: "b" },
    });
  });

  it("login: la cuenta pide 2FA y se verifica con el token efímero", async () => {
    vi.mocked(csrfFetch).mockResolvedValueOnce(
      json(200, { requires_2fa: true, ephemeralToken: "eph" }),
    );
    await expect(authApi.login({ username: "a", password: "b" })).resolves.toEqual({
      status: "two-factor",
      ephemeralToken: "eph",
    });

    vi.mocked(csrfFetch).mockResolvedValueOnce(json(200, { ok: true }));
    await authApi.verifyTwoFactor("eph", "123456");
    expect(csrfFetch).toHaveBeenLastCalledWith("/api/auth/verify-2fa", {
      method: "POST",
      body: { ephemeralToken: "eph", code: "123456" },
    });
  });

  it("verifyTwoFactor admite un código de recuperación en vez del TOTP", async () => {
    vi.mocked(csrfFetch).mockResolvedValueOnce(json(200, { ok: true }));
    await authApi.verifyTwoFactor("eph", { recoveryCode: "abcd-efgh" });
    expect(csrfFetch).toHaveBeenLastCalledWith("/api/auth/verify-2fa", {
      method: "POST",
      body: { ephemeralToken: "eph", recoveryCode: "abcd-efgh" },
    });

    vi.mocked(csrfFetch).mockResolvedValueOnce(
      json(401, { error: "Código no válido." }),
    );
    await expect(
      authApi.verifyTwoFactor("eph", { recoveryCode: "mal" }),
    ).rejects.toThrow("Código no válido.");
  });

  it("logout dice si cerró la sesión", async () => {
    vi.mocked(csrfFetch).mockResolvedValueOnce(json(200, { ok: true }));
    await expect(authApi.logout()).resolves.toBe(true);
    expect(csrfFetch).toHaveBeenLastCalledWith("/api/auth/logout", { method: "POST" });

    // 403 de Origin/CSRF o 429: las cookies siguen puestas, la sesión no se cerró.
    vi.mocked(csrfFetch).mockResolvedValueOnce(
      json(403, { error: "Origen no permitido." }),
    );
    await expect(authApi.logout()).resolves.toBe(false);
  });

  it("un error del BFF sale como BffError con su mensaje, estado y campos", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json(422, { error: "Documento en uso.", details: { identity: ["Ya existe."] } }),
    );
    const error = await authApi
      .register({ username: "a", email: "a@example.com", password: "x" })
      .catch((e: unknown) => e);

    expect(error).toBeInstanceOf(BffError);
    expect((error as BffError).message).toBe("Documento en uso.");
    expect((error as BffError).status).toBe(422);
    expect((error as BffError).field("identity")).toBe("Ya existe.");
    expect((error as BffError).field("terms_accepted")).toBeNull();
  });

  it("sin mensaje del BFF usa el texto de reserva de la vista", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(new Response("oops", { status: 502 }));
    await expect(
      authApi.forgotPassword("a@example.com", "es", "Inténtalo luego."),
    ).rejects.toThrow("Inténtalo luego.");
    expect(csrfFetch).toHaveBeenCalledWith("/api/auth/forgot-password", {
      method: "POST",
      body: { email: "a@example.com", locale: "es" },
    });
  });
});
