import { existsSync } from "node:fs";
import path from "node:path";
import { analyticsCspHosts } from "./csp-analytics.mjs";
import createNextIntlPlugin from "next-intl/plugin";
import {
  staticGenerationOptions,
  staticPageGenerationTimeout,
} from "./scripts/static-generation-options.mjs";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

/** @type {import('next').NextConfig} */

const isProd = process.env.NODE_ENV === "production";

/**
 * Origen público del CMS (WordPress) desde el que se sirven sus imágenes
 * (`/wp-content/uploads`). Se usa para autorizarlo en `img-src` de la CSP y en los
 * `remotePatterns` de next/image.
 *
 * Viene de `WORDPRESS_PUBLIC_URL`, una variable de SERVIDOR (sin `NEXT_PUBLIC_`): el
 * navegador no habla con WordPress —todo pasa por el BFF—, así que ya no hay una URL
 * pública de GraphQL ni `connect-src` hacia el CMS. Esta config se evalúa al construir,
 * así que la variable debe estar presente en el BUILD de la imagen.
 */
function getWordPressOrigin() {
  const raw = process.env.WORDPRESS_PUBLIC_URL ?? "http://localhost:8080";
  try {
    return new URL(raw).origin;
  } catch {
    return "http://localhost:8080";
  }
}

const WP_ORIGIN = getWordPressOrigin();

/**
 * Hosts de analítica que la CSP autoriza (ver `csp-analytics.mjs`): el máximo
 * permitido, que un despliegue estrecha con `CSP_ANALYTICS_PROVIDERS`.
 */
const ANALYTICS = analyticsCspHosts();

/**
 * Construye un `remotePattern` de next/image a partir del origen del CMS,
 * para permitir imágenes alojadas en el dominio de producción sin hardcodearlo.
 */
function wordpressImagePattern() {
  const patterns = [];
  const seen = new Set();
  const push = (origin) => {
    try {
      const u = new URL(origin);
      const protocol = u.protocol.replace(":", "");
      const key = `${protocol}|${u.hostname}|${u.port}`;
      if (seen.has(key)) return;
      seen.add(key);
      patterns.push({
        protocol,
        hostname: u.hostname,
        port: u.port || "",
        pathname: "/wp-content/uploads/**",
      });
    } catch {
      /* origen no válido: sin patrón */
    }
  };
  push(WP_ORIGIN);
  // El optimizador hace el fetch en el SERVIDOR: `CmsImage` reescribe las URLs
  // de medios al origen interno (`lib/wordpress/media.ts`), que también debe
  // estar en la lista.
  if (process.env.WORDPRESS_INTERNAL_API_URL)
    push(process.env.WORDPRESS_INTERNAL_API_URL);
  return patterns;
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
    `script-src 'self' 'unsafe-inline'${isProd ? "" : " 'unsafe-eval'"}${ANALYTICS.script.length ? " " + ANALYTICS.script.join(" ") : ""}`,
    "style-src 'self' 'unsafe-inline'",
    // Sin generadores de QR externos: el QR del alta de 2FA lo sirve nuestro BFF
    // (/api/auth/2fa/qr) y el secreto TOTP no debe salir del dominio.
    `img-src 'self' data: blob: ${WP_ORIGIN}${ANALYTICS.img.length ? " " + ANALYTICS.img.join(" ") : ""}`,
    "font-src 'self' data:",
    // Sin el CMS: el navegador solo llama al BFF (mismo origen).
    `connect-src 'self'${ANALYTICS.connect.length ? " " + ANALYTICS.connect.join(" ") : ""}${isProd ? "" : " ws: wss:"}`,
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

/**
 * Catálogos OPCIONALES: los que el framework trae pero la instancia puede borrar (una
 * tienda monolingüe borra `messages/en.json` y se queda con `es`). `catalogs.ts` los
 * importa de forma estática, así que, si el archivo no existe, el build fallaría: se
 * resuelve el especificador al catálogo español, que es la caída documentada para
 * cualquier idioma sin catálogo. Lo mismo hacen `tsconfig.json` (tipos) y
 * `vitest.config.ts` (tests).
 */
const OPTIONAL_CATALOGS = [
  { specifier: "@/i18n/messages/en.json", file: "src/i18n/messages/en.json" },
].filter(({ file }) => !existsSync(path.resolve(file)));

const catalogAliases = Object.fromEntries(
  OPTIONAL_CATALOGS.map(({ specifier }) => [
    specifier,
    path.resolve("src/i18n/messages/es.json"),
  ]),
);

// ----------------------------------------------------------------------------
//  DSN público de Sentry para el cliente. Next solo inlinea los accesos
//  literales a `process.env.NEXT_PUBLIC_*`; si la instancia solo define
//  `SENTRY_DSN` (como hasta ahora), se reexpone con el nombre público para que
//  el navegador lo vea. Un DSN NO es un secreto: el SDK de navegador lo publica
//  por diseño. `NEXT_PUBLIC_SENTRY_DSN` tiene prioridad.
// ----------------------------------------------------------------------------
const publicSentryDsn =
  process.env.NEXT_PUBLIC_SENTRY_DSN || process.env.SENTRY_DSN || "";
const clientSentryEnv = {
  ...(publicSentryDsn ? { NEXT_PUBLIC_SENTRY_DSN: publicSentryDsn } : {}),
  ...(process.env.NEXT_PUBLIC_SENTRY_REPLAY
    ? { NEXT_PUBLIC_SENTRY_REPLAY: process.env.NEXT_PUBLIC_SENTRY_REPLAY }
    : {}),
};

// Sin DSN no hay nada que instrumentar EN EL NAVEGADOR: el SDK de cliente se
// resuelve a un no-op y no viaja en el build. La condición `browser` deja el
// SDK real en servidor/edge (ahí no se inicializa sin DSN, pero un despliegue
// puede activarlo sin reconstruir la imagen).
const sentryAliases = publicSentryDsn
  ? {}
  : {
      "@sentry/nextjs": {
        browser: "./src/lib/observability/sentry-noop.ts",
      },
    };
const turbopackAliases = {
  ...Object.fromEntries(
    OPTIONAL_CATALOGS.map(({ specifier }) => [specifier, "./src/i18n/messages/es.json"]),
  ),
  ...sentryAliases,
};

const nextConfig = withNextIntl({
  // Congeladas en el bundle al construir (ver `clientSentryEnv` arriba).
  env: clientSentryEnv,
  // Catálogos opcionales y, sin DSN, el stub de Sentry (ver arriba).
  ...(Object.keys(turbopackAliases).length > 0
    ? {
        turbopack: { resolveAlias: turbopackAliases },
        ...(OPTIONAL_CATALOGS.length > 0
          ? {
              webpack: (config) => {
                config.resolve.alias = { ...config.resolve.alias, ...catalogAliases };
                return config;
              },
            }
          : {}),
      }
    : {}),

  reactStrictMode: true,
  poweredByHeader: false, // No revelar "X-Powered-By: Next.js".

  // Salida autocontenida para la imagen de producción (Dockerfile target `prod`):
  // copia solo el server.js + node_modules mínimos trazados. Inocuo en dev.
  output: "standalone",

  // El build type-chequea el código de la app, no las pruebas (ver el propio
  // archivo): `npm run type-check` las cubre con tsconfig.json.
  typescript: { tsconfigPath: "tsconfig.build.json" },

  // Ritmo del prerender por variable de entorno: sin ellas, la config queda
  // como estaba (ver `scripts/static-generation-options.mjs`).
  experimental: staticGenerationOptions(process.env),
  ...(staticPageGenerationTimeout(process.env) !== undefined
    ? { staticPageGenerationTimeout: staticPageGenerationTimeout(process.env) }
    : {}),

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
      // Dominio de producción (WORDPRESS_PUBLIC_URL) y origen interno.
      ...wordpressImagePattern(),
    ],
    // Next 16 solo sirve las calidades declaradas aquí: un `quality` fuera de la
    // lista responde 400. 75 es el defecto de `next/image`; 90 para donde la
    // compresión se VE (degradados amplios, ilustración plana).
    qualities: [75, 90],
    // Next 16+ bloquea por defecto el fetch a IPs privadas (defensa SSRF). El
    // origen interno (`http://wordpress`) y `localhost` en desarrollo resuelven a
    // una IP privada: sin esto, `_next/image` responde 400. `remotePatterns`
    // sigue siendo la allowlist de hosts y rutas.
    dangerouslyAllowLocalIP: !isProd || Boolean(process.env.WORDPRESS_INTERNAL_API_URL),
    // Cuánto vive una imagen YA optimizada antes de que sharp la rehaga. Los
    // uploads de WordPress son inmutables (al resubir con el mismo nombre WP
    // versiona el archivo), así que un valor largo es correcto; el origen interno
    // no manda `Cache-Control` y Next aplicaría su defecto de 4 h, reoptimizando
    // cada variante del catálogo seis veces al día.
    minimumCacheTTL: Number(process.env.NEXT_IMAGE_MIN_CACHE_TTL) || 2592000,
  },
});

let finalConfig = nextConfig;

// Envuelve con Sentry si el SDK está disponible y hay DSN (de servidor o
// público). El wrap aporta el túnel `/monitoring` y la subida de source maps.
try {
  const { withSentryConfig } = await import("@sentry/nextjs");
  if (process.env.SENTRY_DSN || process.env.NEXT_PUBLIC_SENTRY_DSN) {
    finalConfig = withSentryConfig(nextConfig, {
      silent: !process.env.CI,
      widenClientFileUpload: true,
      tunnelRoute: "/monitoring",
    });
  }
} catch {
  // @sentry/nextjs no instalado o no disponible — config sin wrap.
}

export default finalConfig;
