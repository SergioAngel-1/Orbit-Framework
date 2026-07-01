import { routing } from "@/i18n/routing";
import { getSiteConfig } from "@/lib/config";

// /robots.txt como route handler de texto (en vez de la convención metadata) para
// poder emitir la directiva Content-Signal (borrador IETF), no soportada por
// MetadataRoute.Robots. Reglas y política de IA gobernadas por el Control Center → GEO.
export const runtime = "nodejs";
export const revalidate = 300;

const PRIVATE_PATHS = [
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
      const prefix = locale === routing.defaultLocale ? "" : `/${locale}`;
      paths.add(`${prefix}${path}`);
    }
  }
  return [...paths];
}

/** Valores de Content-Signal coherentes con la política de crawlers. */
function contentSignal(policy: string): string {
  switch (policy) {
    case "block":
      return "ai-train=no, search=no, ai-retrieval=no";
    case "search_only":
      return "ai-train=no, search=yes, ai-retrieval=yes";
    default:
      return "ai-train=yes, search=yes, ai-retrieval=yes";
  }
}

export async function GET(): Promise<Response> {
  if (process.env.NODE_ENV === "development") {
    return text("User-agent: *\nDisallow: /\n");
  }

  const config = await getSiteConfig();
  const policy = config.geo.ai_crawlers;
  const lines: string[] = [];

  // Grupo principal.
  lines.push("User-agent: *", "Allow: /");
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

  lines.push("", `Sitemap: ${config.brand.url}/sitemap.xml`, "");
  return text(lines.join("\n"));
}

function text(body: string): Response {
  return new Response(body, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": "public, max-age=0, s-maxage=300, stale-while-revalidate=86400",
    },
  });
}
