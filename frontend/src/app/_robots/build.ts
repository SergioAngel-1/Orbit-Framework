import { routing } from "@/i18n/routing";
import { localizedPath } from "@/lib/seo/urls";
import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { indexingBlockedByEnv } from "@/lib/seo/indexing";
import { logger } from "@/lib/observability/logger";

// Contenido de /robots.txt. Es un route handler de texto (en vez de la
// convención metadata) para poder emitir la directiva Content-Signal (borrador
// IETF), no soportada por MetadataRoute.Robots. Reglas y política de IA
// gobernadas por el Control Center → GEO.
//
// Vive en `_robots/` y no en la ruta: un route handler de Next solo admite
// exportar métodos HTTP y config, y las funciones puras se prueban aparte.

export const PRIVATE_PATHS = [
  "/account",
  "/cart",
  "/checkout",
  "/login",
  "/register",
  "/forgot-password",
  "/reset-password",
  "/verify-email",
];

const AI_SEARCH_AGENTS = [
  "OAI-SearchBot",
  "ChatGPT-User",
  "PerplexityBot",
  "Perplexity-User",
  "Claude-User",
  "Claude-SearchBot",
];

const AI_TRAINING_AGENTS = [
  "GPTBot",
  "ClaudeBot",
  "anthropic-ai",
  "Google-Extended",
  "CCBot",
  "Applebot-Extended",
  "Bytespider",
  "Amazonbot",
  "Meta-ExternalAgent",
  "FacebookBot",
];

function localizedDisallow(): string[] {
  const paths = new Set<string>(["/api/"]);
  for (const path of PRIVATE_PATHS) {
    for (const locale of routing.locales) {
      // La ruta interna sigue accesible (el proxy la redirige 308 a la pública): se veta
      // también, junto con la pública que declare la instancia.
      const prefix = locale === routing.defaultLocale ? "" : `/${locale}`;
      paths.add(`${prefix}${path}`);
      paths.add(localizedPath(path, locale));
    }
  }
  return [...paths];
}

/**
 * Valores de Content-Signal coherentes con la política de crawlers. Las tres
 * señales del borrador son `search`, `ai-input` (usar el contenido como
 * entrada de una respuesta de IA) y `ai-train`; `ai-retrieval` NO existe y un
 * parser que lo ve inválido descarta la línea entera.
 */
export function contentSignal(policy: string): string {
  switch (policy) {
    case "block":
      return "ai-train=no, search=no, ai-input=no";
    case "search_only":
      return "ai-train=no, search=yes, ai-input=yes";
    default:
      return "ai-train=yes, search=yes, ai-input=yes";
  }
}

/** Respuesta completa de `/robots.txt`. */
export async function robotsResponse(): Promise<Response> {
  // Desarrollo y entornos con la guarda `HWE_NOINDEX` (staging): todo vetado y sin
  // caché, para que un CDN no sirva esta versión cuando se quite la guarda.
  if (process.env.NODE_ENV === "development" || indexingBlockedByEnv()) {
    return text("User-agent: *\nDisallow: /\n", { cacheable: false });
  }

  const config = await getSiteConfig();
  // Sin la guarda de entorno, un sitio en producción con «Indexación: noindex» en el
  // Control Center casi siempre es una copia de staging que se trajo su ajuste. No se
  // cambia nada (puede ser intencionado), pero no puede pasar en silencio.
  if (config.seo.robots === "noindex,nofollow" && !isFallbackSiteConfig(config)) {
    logger.warn(
      { event: "seo.site_noindex" },
      "El sitio está en noindex por el Control Center (SEO → Indexación) sin HWE_NOINDEX",
    );
  }
  const policy = config.geo.ai_crawlers;
  const lines: string[] = [];

  // Grupo principal.
  lines.push("User-agent: *", "Allow: /");
  // `/api/` está vetado, pero las tarjetas sociales y los iconos viven ahí y
  // los rastreadores de las redes (y de las pestañas) tienen que poder leerlos.
  lines.push("Allow: /api/og", "Allow: /api/icon");
  for (const path of localizedDisallow()) lines.push(`Disallow: ${path}`);

  // Content-Signal (borrador IETF): declara las preferencias de uso por IA.
  if (config.geo.content_signal) {
    lines.push("", `Content-Signal: ${contentSignal(policy)}`);
  }

  // Política por bots de IA.
  const blocked =
    policy === "block"
      ? [...AI_TRAINING_AGENTS, ...AI_SEARCH_AGENTS]
      : policy === "search_only"
        ? AI_TRAINING_AGENTS
        : [];
  for (const agent of blocked) {
    lines.push("", `User-agent: ${agent}`, "Disallow: /");
  }

  // Con la config degradada, `brand.url` es el default de código: anunciar
  // `Sitemap: http://localhost:3000/sitemap.xml` manda al crawler a un dominio
  // que no es el nuestro. Se omite la línea — el resto de reglas (Disallow,
  // Content-Signal) sigue siendo válido, así que servir el fichero recortado es
  // mejor que fallar. `no-store` evita que CDN/navegador fijen la versión
  // recortada.
  const degraded = isFallbackSiteConfig(config);
  if (degraded) {
    lines.push("");
  } else {
    const sitemapBase = (config.brand.url || "").replace(/\/$/, "");
    lines.push("", `Sitemap: ${sitemapBase}/sitemap.xml`, "");
  }
  return text(lines.join("\n"), { cacheable: !degraded });
}

function text(body: string, options: { cacheable?: boolean } = {}): Response {
  const { cacheable = true } = options;
  return new Response(body, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": cacheable
        ? "public, max-age=0, s-maxage=300, stale-while-revalidate=86400"
        : "no-store",
    },
  });
}
