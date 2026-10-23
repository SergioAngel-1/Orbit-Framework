// ============================================================================
//  Política base de las cookies, apta para EDGE.
//
//  Vive fuera de `lib/security/cookies.ts` porque ese módulo importa
//  `server-only` y `proxy.ts` —que corre en edge— necesita los MISMOS flags para
//  dejar la cookie del código de referido. Así la política se define una sola
//  vez y ninguna cookie se queda sin `secure`, `sameSite` o `httpOnly` porque
//  alguien copió las opciones a mano.
//
//  Sin imports de Next ni de Node: solo se lee el entorno en cada llamada (no al
//  importar) para que el valor dependa del proceso real; en edge Next sustituye
//  `NODE_ENV` al compilar.
// ============================================================================

/** Tipo de las opciones de cookie de `next/headers` y `NextResponse`. */
export interface CookieOptions {
  httpOnly: boolean;
  secure: boolean;
  sameSite: "lax" | "strict" | "none";
  path: string;
  maxAge?: number;
  domain?: string;
}

/**
 * Opciones base: `httpOnly`, `SameSite=Lax`, `path=/` y `Secure` en producción.
 * `SameSite=Lax` es el equilibrio para sesión + navegación normal; las
 * escrituras se protegen además con CSRF.
 *
 * `COOKIE_DOMAIN` (opcional) permite compartir cookies entre subdominios; vacío
 * = host actual.
 */
export function baseCookieOptions(): CookieOptions {
  const domain = process.env.COOKIE_DOMAIN || undefined;
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    ...(domain ? { domain } : {}),
  };
}
