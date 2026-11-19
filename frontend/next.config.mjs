import { existsSync } from "node:fs";
import path from "node:path";
import { analyticsCspHosts } from "./csp-analytics.mjs";
import { cspExtraHosts } from "./csp-extra-hosts.mjs";
import { buildContentSecurityPolicy } from "./csp-policy.mjs";
import createNextIntlPlugin from "next-intl/plugin";
import { imageFormats } from "./scripts/image-formats.mjs";
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
 * Orígenes que el despliegue AÑADE a la CSP (ver `csp-extra-hosts.mjs`): los de
 * audiencias de Google Ads, una pasarela de Conversions API o una CDN propia.
 * Aditivos y validados; sin variables, no cambian la cabecera ni un byte.
 */
const EXTRA_CSP = cspExtraHosts(process.env);

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
 * Cabeceras de seguridad aplicadas a todas las rutas.
 * HSTS solo se emite en producción (en local rompería el acceso por http).
 */
const securityHeaders = [
  {
    // La arma `csp-policy.mjs` (función pura, con pruebas): hosts de analítica
    // (estrechables) + los que añade el despliegue con `CSP_EXTRA_*_SRC`.
    key: "Content-Security-Policy",
    value: buildContentSecurityPolicy({
      isProd,
      wordpressOrigin: WP_ORIGIN,
      analytics: ANALYTICS,
      extra: EXTRA_CSP,
    }),
  },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  // Solo features que Chrome reconoce hoy. `browsing-topics=()` se quitó: Google retiró la
  // API de Topics y el resto de Privacy Sandbox (anuncio de oct. de 2025), y un navegador
  // actual responde «Unrecognized feature» en consola a cada carga sin que el header haga
  // nada. Los avisos de `attribution-reporting`, `private-aggregation`,
  // `join-ad-interest-group` y `run-ad-auction` NO salen de aquí ni del Caddyfile: los
  // emiten los iframes de terceros (Google Ads, Meta) con su propio `allow`.
  {
    key: "Permissions-Policy",
    value: "camera=(), microphone=(), geolocation=()",
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

  // La compresión la hace Caddy (`encode` en el Caddyfile), no Next. Con la de Next
  // activa, Next gzipea ANTES de llegar al proxy y Caddy no recomprime una respuesta
  // ya codificada: ningún navegador recibía zstd, que en el HTML de la portada de una
  // tienda (866 KB sin comprimir) pesa 87 KB frente a 147 KB del gzip de Next. Caddy
  // negocia zstd o gzip según el cliente y añade `Vary: Accept-Encoding`. Sin proxy
  // delante (p. ej. `next start` a pelo) las respuestas salen sin comprimir.
  compress: false,

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

  // Imágenes de WordPress (medios de wp-content/uploads). `CmsImage` no pide al optimizador
  // la URL de WP sino la ruta `/api/media/…` (una imagen LOCAL, sin `remotePatterns`), que
  // descarga el original por el origen interno. Los `remotePatterns` de abajo cubren las
  // URLs absolutas de WordPress que otro componente pase a `next/image` tal cual.
  images: {
    // Next 16 solo deja a `next/image` pedir imágenes locales SIN cadena de consulta. `/api/media`
    // lleva la `?ver=` del medio (`cmsMediaSrc`: cambia la clave de caché si el archivo se
    // reemplaza), así que la ruta la admite; el resto de rutas locales conserva el defecto.
    localPatterns: [{ pathname: "/api/media/**" }, { pathname: "**", search: "" }],
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
    // AVIF si el navegador lo acepta (`Accept: image/avif`), WebP si no. Con sharp y la
    // calidad 75 por defecto (Next la baja a ~47 en AVIF), 18 casos reales (6 fotos del
    // catálogo × 640/828/1080 px): -27 a -41 % de bytes (mediana -33 %, p. ej. 21,9 → 16,1 KB
    // a 1080 px) con menos distorsión SSIM que el WebP. El coste es de CPU, una vez por
    // variante, y después la caché (`minimumCacheTTL`) la sirve sin tocar sharp. Cuánto
    // cuesta depende de los núcleos: ~3x el de WebP con 4 vCPU (130-435 ms frente a
    // 46-135 ms) pero ≈5x con 2 (0,3-1,1 s frente a 66-235 ms; 6 variantes a la vez: 3,3 s
    // frente a 0,6 s). Mientras sharp ocupa los hilos de libuv, el resto de la E/S del
    // proceso (DNS de la descarga del origen incluido) espera en la misma cola, y la
    // descarga del origen tiene un plazo fijo de 7 s (no configurable en Next 16): una
    // ráfaga de AVIF en frío puede acabar en 504. Un despliegue pequeño que lo note fija
    // `NEXT_IMAGE_FORMATS=webp` (ver `scripts/image-formats.mjs`; se lee al construir).
    formats: imageFormats(process.env),
    // Next 16+ bloquea por defecto el fetch a IPs privadas (defensa SSRF). Ya no lo necesita
    // `CmsImage` (su descarga la hace `/api/media`, que no pasa por esta comprobación), pero
    // sí una URL absoluta a `http://wordpress` o a `localhost` en desarrollo, que resuelven a
    // una IP privada: sin esto, `_next/image` responde 400. `remotePatterns` sigue siendo la
    // allowlist de hosts y rutas.
    dangerouslyAllowLocalIP: !isProd || Boolean(process.env.WORDPRESS_INTERNAL_API_URL),
    // Cuánto vive una imagen YA optimizada antes de que sharp la rehaga. Los
    // uploads de WordPress son inmutables (al resubir con el mismo nombre WP
    // versiona el archivo y `cmsMediaSrc` conserva su `?ver=` en la clave de caché), así que
    // un valor largo es correcto. Es el suelo: `/api/media` manda `max-age=2592000` (el mismo
    // valor), pero una fuente que no mande `Cache-Control` aplicaría el defecto de Next de
    // 4 h y reoptimizaría cada variante del catálogo seis veces al día.
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
