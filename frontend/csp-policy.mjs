// ============================================================================
//  Content Security Policy del frontend, como función pura.
//
//  Se evalúa al construir/arrancar (`next.config.mjs`), así que NO puede leer lo
//  configurado en Control Center (runtime, por petición): la CSP fija el MÁXIMO
//  permitido y lo que realmente se carga lo decide cada lector según la config y
//  el consentimiento.
//
//  Tres fuentes de hosts, en este orden:
//    1. Los de analítica que el framework sabe cargar (`csp-analytics.mjs`), que
//       un despliegue estrecha con `CSP_ANALYTICS_PROVIDERS`.
//    2. Los que ese despliegue AÑADE con `CSP_EXTRA_*_SRC` (`csp-extra-hosts.mjs`).
//    3. El origen público del CMS en `img-src` (medios de WordPress).
//
//  Sin 2, la cabecera sale idéntica a la de siempre.
//
//  Nota sobre `'unsafe-inline'` en script-src: Next.js inyecta scripts inline
//  para la hidratación. Una CSP basada en *nonce* eliminaría esta directiva,
//  pero obliga a renderizado dinámico (rompe SSG/ISR), que es un pilar de esta
//  plantilla. Mantenemos `'unsafe-inline'` para scripts y bloqueamos con firmeza
//  el resto de vectores (connect/img/frame/object/base/form).
// ============================================================================

/** Une listas de orígenes sin repetir y con un solo espacio entre ellas. */
function sources(...groups) {
  return [...new Set(groups.flat().filter(Boolean))].join(" ");
}

/**
 * Política completa (`Content-Security-Policy`), con `; ` entre directivas.
 *
 * @param {{
 *   isProd: boolean;
 *   wordpressOrigin: string;
 *   analytics?: { script?: string[]; connect?: string[]; img?: string[] };
 *   extra?: { script?: string[]; connect?: string[]; img?: string[] };
 * }} options
 * @returns {string}
 */
export function buildContentSecurityPolicy({
  isProd,
  wordpressOrigin,
  analytics = {},
  extra = {},
}) {
  const script = sources(analytics.script ?? [], extra.script ?? []);
  const connect = sources(analytics.connect ?? [], extra.connect ?? []);
  const img = sources(analytics.img ?? [], extra.img ?? []);

  const directives = [
    "default-src 'self'",
    `script-src 'self' 'unsafe-inline'${isProd ? "" : " 'unsafe-eval'"}${script ? ` ${script}` : ""}`,
    "style-src 'self' 'unsafe-inline'",
    // Sin generadores de QR externos: el QR del alta de 2FA lo sirve nuestro BFF
    // (/api/auth/2fa/qr) y el secreto TOTP no debe salir del dominio.
    `img-src 'self' data: blob: ${wordpressOrigin}${img ? ` ${img}` : ""}`,
    "font-src 'self' data:",
    // Sin el CMS: el navegador solo llama al BFF (mismo origen).
    `connect-src 'self'${connect ? ` ${connect}` : ""}${isProd ? "" : " ws: wss:"}`,
    "frame-ancestors 'none'",
    "frame-src 'none'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "manifest-src 'self'",
    "worker-src 'self' blob:",
    ...(isProd ? ["upgrade-insecure-requests"] : []),
  ];
  return directives.join("; ");
}
