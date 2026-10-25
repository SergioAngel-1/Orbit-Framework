import { toInternalPath } from "@/lib/instance/public-paths";

/**
 * Ruta interna segura para `?return=` tras iniciar sesión (anti open-redirect).
 * Solo rutas relativas de la app; nunca `/api` ni URLs absolutas.
 *
 * Acepta la ruta en su forma PÚBLICA (`/cuenta`) o interna (`/account`): el valor
 * devuelto es siempre el interno, que es lo que consumen los `Link` (el proxy y la
 * navegación lo vuelven a traducir). El fallback (`/account`) también es interno.
 *
 * Los navegadores QUITAN tabuladores y saltos de línea al interpretar una URL:
 * `/<TAB>/evil.example` llega a ser `//evil.example`, un protocolo relativo. Por
 * eso se rechaza cualquier carácter de control, y no basta con `trim()`.
 */
export function safeInternalPath(
  raw: string | null | undefined,
  fallback = "/account",
): string {
  if (!raw) return fallback;
  const path = raw.trim();
  if (!path) return fallback;
  // Controles C0, DEL y C1: nada de ellos es legítimo en una ruta de la app.
  if (/[\u0000-\u001f\u007f-\u009f]/.test(path)) return fallback;
  if (!path.startsWith("/") || path.startsWith("//")) return fallback;
  if (path.includes("://") || path.includes("\\")) return fallback;
  if (path === "/api" || path.startsWith("/api/") || path.startsWith("/api?")) {
    return fallback;
  }
  // Evita esquemas embebidos (javascript:, data:…)
  if (/^\/[a-z]+:/i.test(path)) return fallback;
  return toInternalPath(path);
}

/**
 * Destino de una vista de acceso (`/login`, `/register`) que vuelve a `returnTo`
 * al terminar. Una ruta que no es segura —o la de por defecto, la cuenta— no
 * viaja en la URL: la vista a secas.
 */
export function withReturn<P extends string>(
  pathname: P,
  returnTo: string | null | undefined,
): P | { pathname: P; query: { return: string } } {
  const safe = safeInternalPath(returnTo, "");
  return safe && safe !== "/account" ? { pathname, query: { return: safe } } : pathname;
}

/** Destino del login que vuelve a `returnTo` al terminar. */
export function loginHref(returnTo: string | null | undefined) {
  return withReturn("/login", returnTo);
}
