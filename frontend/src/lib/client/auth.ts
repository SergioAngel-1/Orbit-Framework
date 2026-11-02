"use client";
import { csrfFetch } from "./csrf";
import { readBff } from "./bff-error";

// ============================================================================
//  Cliente de autenticación (lado navegador) sobre las rutas `/api/auth/*`.
//
//  Capa de datos del núcleo: cualquier vista (la heredada o una nueva) llama
//  aquí en vez de montar `fetch` a mano. La sesión vive en cookies httpOnly
//  que pone el BFF; nada de esto toca el JWT. Los errores salen como
//  `BffError` (mensaje de la ruta, `details` por campo); el texto de reserva
//  lo pasa la vista, que es quien conoce el idioma.
// ============================================================================

export type LoginResult =
  | { status: "signed-in" }
  /** La cuenta tiene 2FA: hay que llamar a `verifyTwoFactor` con este token y el código. */
  | { status: "two-factor"; ephemeralToken: string };

export interface RegisterInput {
  username: string;
  email: string;
  password: string;
  /** E.164; solo con `auth.phone_login_enabled`. */
  phone?: string;
  /** Módulo `customer-identity`. */
  identity?: { documentType: string; documentNumber: string };
  terms_accepted?: boolean;
  /** Idioma del correo de verificación (uno de `routing.locales`). */
  locale?: string;
}

const DEFAULT_ERROR = "No se pudo completar la operación.";

async function post<T>(url: string, body: unknown, fallback: string): Promise<T> {
  return readBff<T>(await csrfFetch(url, { method: "POST", body }), fallback);
}

export const authApi = {
  async login(
    credentials: { username: string; password: string },
    fallback = DEFAULT_ERROR,
  ): Promise<LoginResult> {
    const data = await post<{ requires_2fa?: boolean; ephemeralToken?: string }>(
      "/api/auth/login",
      credentials,
      fallback,
    );
    return data.requires_2fa && data.ephemeralToken
      ? { status: "two-factor", ephemeralToken: data.ephemeralToken }
      : { status: "signed-in" };
  },

  /**
   * `code`: los 6 dígitos del TOTP, o `{ recoveryCode }` si el usuario perdió el dispositivo
   * (cada código de recuperación vale una sola vez).
   */
  async verifyTwoFactor(
    ephemeralToken: string,
    code: string | { recoveryCode: string },
    fallback = DEFAULT_ERROR,
  ): Promise<void> {
    const proof = typeof code === "string" ? { code } : { recoveryCode: code.recoveryCode };
    await post("/api/auth/verify-2fa", { ephemeralToken, ...proof }, fallback);
  },

  async register(input: RegisterInput, fallback = DEFAULT_ERROR): Promise<void> {
    await post("/api/auth/register", input, fallback);
  },

  async logout(): Promise<void> {
    await csrfFetch("/api/auth/logout", { method: "POST" });
  },

  /** Responde igual exista o no la cuenta (no revela correos registrados). */
  async forgotPassword(
    email: string,
    locale?: string,
    fallback = DEFAULT_ERROR,
  ): Promise<void> {
    await post("/api/auth/forgot-password", { email, locale }, fallback);
  },

  async resetPassword(
    input: { key: string; login: string; password: string },
    fallback = DEFAULT_ERROR,
  ): Promise<void> {
    await post("/api/auth/reset-password", input, fallback);
  },
};
