import createNextIntlPlugin from "next-intl/plugin";
import path from "path";
import fs from "fs";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

/** @type {import('next').NextConfig} */

const isProd = process.env.NODE_ENV === "production";

/**
 * Origen público del CMS (WordPress), derivado del endpoint de GraphQL.
 * Se usa para autorizarlo en `connect-src` / `img-src` de la CSP y en
 * los `remotePatterns` de next/image.
 */
function getWordPressOrigin() {
  const raw =
    process.env.NEXT_PUBLIC_WORDPRESS_API_URL ?? "http://localhost:8080/graphql";
  try {
    return new URL(raw).origin;
  } catch {
    return "http://localhost:8080";
  }
}

const WP_ORIGIN = getWordPressOrigin();

/**
 * Construye un `remotePattern` de next/image a partir del origen del CMS,
 * para permitir imágenes alojadas en el dominio de producción sin hardcodearlo.
 */
function wordpressImagePattern() {
  try {
    const u = new URL(WP_ORIGIN);
    return [
      {
        protocol: u.protocol.replace(":", ""),
        hostname: u.hostname,
        port: u.port || "",
        pathname: "/wp-content/uploads/**",
      },
    ];
  } catch {
    return [];
  }
}

/**
 * Content Security Policy.
 *
 * Nota sobre `'unsafe-inline'` en script-src: Next.js inyecta scripts inline
 * para la hidratación. Una CSP basada en *nonce* eliminaría esta directiva,
 * pero obliga a renderizado dinámico (rompe SSG/ISR), que es un pilar de esta
 * plantilla. Mantenemos `'unsafe-inline'` para scripts y bloqueamos con firmeza
 * el resto de vectores (connect/img/frame/object/base/form). Endurecer a nonce
 * es una mejora opcional documentada en la Fase 7 del plan.
 */
function buildContentSecurityPolicy() {
  const directives = [
    "default-src 'self'",
    `script-src 'self' 'unsafe-inline'${isProd ? "" : " 'unsafe-eval'"}`,
    "style-src 'self' 'unsafe-inline'",
    `img-src 'self' data: blob: ${WP_ORIGIN}`,
    "font-src 'self' data:",
    `connect-src 'self' ${WP_ORIGIN}${isProd ? "" : " ws: wss:"}`,
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

/**
 * Cabeceras de seguridad aplicadas a todas las rutas.
 * HSTS solo se emite en producción (en local rompería el acceso por http).
 */
const securityHeaders = [
  { key: "Content-Security-Policy", value: buildContentSecurityPolicy() },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  {
    key: "Permissions-Policy",
    value: "camera=(), microphone=(), geolocation=(), browsing-topics=()",
  },
  { key: "X-DNS-Prefetch-Control", value: "off" },
  { key: "X-Permitted-Cross-Domain-Policies", value: "none" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
  { key: "Cross-Origin-Resource-Policy", value: "same-origin" },
  ...(isProd
    ? [
        {
          key: "Strict-Transport-Security",
          value: "max-age=63072000; includeSubDomains; preload",
        },
      ]
    : []),
];

const nextConfig = withNextIntl({
  reactStrictMode: true,
  poweredByHeader: false, // No revelar "X-Powered-By: Next.js".

  async headers() {
    return [
      {
        source: "/:path*",
        headers: securityHeaders,
      },
    ];
  },

  // Permite servir imágenes alojadas en WordPress (medios de wp-content/uploads).
  images: {
    remotePatterns: [
      {
        protocol: "http",
        hostname: "localhost",
        port: "8080",
        pathname: "/wp-content/uploads/**",
      },
      {
        protocol: "http",
        hostname: "wordpress",
        port: "80",
        pathname: "/wp-content/uploads/**",
      },
      // Dominio de producción derivado de NEXT_PUBLIC_WORDPRESS_API_URL.
      ...wordpressImagePattern(),
    ],
  },
});

export default nextConfig;
