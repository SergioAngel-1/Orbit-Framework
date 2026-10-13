import { routing } from "@/i18n/routing";
import { localizedPath } from "@/lib/seo/urls";

// ============================================================================
//  Idioma y enlaces de los correos de auth (recuperar contraseña, verificar
//  email). Los redacta WordPress (hwe-auth.php + hwe-email-chrome.php), pero el
//  idioma y la URL del enlace los decide el BFF: WordPress no sabe en qué idioma
//  navega el visitante.
// ============================================================================

/** Rutas a las que apuntan los enlaces de los correos de auth. */
export type AuthEmailRoute = "/reset-password" | "/verify-email";

/** Idioma del correo: el que mande el cliente si es uno del sitio; si no, el por defecto. */
export function authEmailLocale(value: unknown): string {
  return typeof value === "string" &&
    (routing.locales as readonly string[]).includes(value)
    ? value
    : routing.defaultLocale;
}

/** Origen público del frontend (sin barra final). */
export function frontendOrigin(): string {
  return (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(
    /\/+$/,
    "",
  );
}

/** URL absoluta y YA localizada del enlace (`/reset-password` o `/en/reset-password`). */
export function authEmailUrl(route: AuthEmailRoute, locale: string): string {
  return `${frontendOrigin()}${localizedPath(route, locale)}`;
}
