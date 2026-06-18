import "server-only";
import crypto from "node:crypto";
import { cookies } from "next/headers";
import { csrfCookieOptions } from "./cookies";

// ============================================================================
//  CSRF — patrón "signed double-submit cookie" (recomendado por OWASP).
//
//  El token se emite en una cookie NO httpOnly (el cliente la lee) y se exige
//  reenviado en la cabecera `X-CSRF-Token`. El servidor comprueba que:
//    1) cabecera y cookie coinciden, y
//    2) el token está firmado con CSRF_SECRET (no falsificable sin el secreto).
//  La política de mismo origen impide que un tercero lea/escriba nuestra cookie.
// ============================================================================

export const CSRF_COOKIE = process.env.CSRF_COOKIE_NAME || "hwe_csrf";
export const CSRF_HEADER = "x-csrf-token";
export const CSRF_TOKEN_MAX_AGE = 60 * 60 * 2; // 2 horas

function getSecret(): string {
  return (
    process.env.CSRF_SECRET ||
    process.env.GRAPHQL_JWT_AUTH_SECRET_KEY ||
    "insecure-dev-csrf-secret"
  );
}

function sign(value: string): string {
  return crypto.createHmac("sha256", getSecret()).update(value).digest("hex");
}

/** Compara dos cadenas hex en tiempo constante. */
function timingSafeEqual(a: string, b: string): boolean {
  const bufA = Buffer.from(a);
  const bufB = Buffer.from(b);
  if (bufA.length !== bufB.length) return false;
  return crypto.timingSafeEqual(bufA, bufB);
}

/** Genera un token firmado `<random>.<hmac(random)>`. */
export function generateCsrfToken(): string {
  const random = crypto.randomBytes(32).toString("hex");
  return `${random}.${sign(random)}`;
}

/** Comprueba que el token está bien formado y firmado por nosotros. */
function isAuthenticToken(token: string): boolean {
  const [random, signature] = token.split(".");
  if (!random || !signature) return false;
  return timingSafeEqual(signature, sign(random));
}

/** Emite un token CSRF y lo fija en la cookie. Devuelve el token. */
export async function issueCsrfToken(): Promise<string> {
  const token = generateCsrfToken();
  const store = await cookies();
  store.set(CSRF_COOKIE, token, csrfCookieOptions(CSRF_TOKEN_MAX_AGE));
  return token;
}

/**
 * Verifica el CSRF de una petición de escritura.
 * @returns true si la cabecera coincide con la cookie y el token es auténtico.
 */
export async function verifyCsrf(request: Request): Promise<boolean> {
  const headerToken = request.headers.get(CSRF_HEADER);
  if (!headerToken) return false;

  const store = await cookies();
  const cookieToken = store.get(CSRF_COOKIE)?.value;
  if (!cookieToken) return false;

  // Double-submit: cabecera y cookie deben coincidir...
  if (!timingSafeEqual(headerToken, cookieToken)) return false;
  // ...y el token debe estar firmado por nosotros.
  return isAuthenticToken(cookieToken);
}
