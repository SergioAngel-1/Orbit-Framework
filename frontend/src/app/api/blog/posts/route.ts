import { NextResponse } from "next/server";
import { getPosts } from "@/lib/blog/posts";
import { routing } from "@/i18n/routing";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";

// Sin `dynamic = "force-dynamic"`: este handler lee la query string, así que ya
// se resuelve por petición. Declararlo además impondría `fetchCache:
// "force-no-store"` a TODO el archivo, que desactiva el Data Cache
// (`revalidate` + `tags` de `getPosts`) del que depende: cada scroll del lector
// sería una consulta real a WPGraphQL.

/** Entradas por petición: una tanda de tarjetas, no un volcado del blog. */
const DEFAULT_FIRST = 9;
const MAX_FIRST = 24;
const MAX_SEARCH_LENGTH = 100;
const MAX_TAGS = 10;
const MAX_SLUG_LENGTH = 200;
/** Un cursor de WPGraphQL es base64 (`arrayconnection:NN`); nada más entra en la consulta. */
const CURSOR_RE = /^[A-Za-z0-9+/=_-]{1,200}$/;

function slugParam(raw: string | null): string | undefined {
  const value = raw?.trim();
  return value && value.length <= MAX_SLUG_LENGTH ? value : undefined;
}

/** Siguiente página del índice del blog (scroll infinito) — ver `InfinitePostGrid`. */
export async function GET(request: Request) {
  const { searchParams } = new URL(request.url);

  const search = searchParams.get("search")?.trim().slice(0, MAX_SEARCH_LENGTH) ?? "";

  // La búsqueda es la parte cara y abusable de este endpoint público (una
  // consulta a WordPress por pulsación); el listado normal es cacheable y no se
  // limita. Fail-open si Redis no responde.
  if (search) {
    const rl = await rateLimit(`blog-search:${getClientIp(request)}`, 30, 60);
    if (!rl.success) {
      logger.warn(
        { event: "api.blog_posts.search_rate_limited" },
        "Rate-limit en la búsqueda del blog",
      );
      return NextResponse.json(
        { error: "Demasiadas búsquedas." },
        { status: 429, headers: { "Retry-After": String(rl.retryAfter) } },
      );
    }
  }

  const after = searchParams.get("after");
  const requestedFirst = Number(searchParams.get("first"));
  const first =
    Number.isInteger(requestedFirst) && requestedFirst > 0
      ? Math.min(requestedFirst, MAX_FIRST)
      : DEFAULT_FIRST;
  const requestedLocale = searchParams.get("locale") ?? "";
  const locale = (routing.locales as readonly string[]).includes(requestedLocale)
    ? requestedLocale
    : routing.defaultLocale;
  // Slugs de WordPress separados por coma: un filtro puede agrupar etiquetas.
  const tags = (searchParams.get("tag") ?? "")
    .split(",")
    .map((slug) => slug.trim())
    .filter((slug) => slug && slug.length <= MAX_SLUG_LENGTH)
    .slice(0, MAX_TAGS);

  try {
    const result = await getPosts({
      after: after && CURSOR_RE.test(after) ? after : undefined,
      tag: tags.length > 0 ? tags : undefined,
      author: slugParam(searchParams.get("author")),
      category: slugParam(searchParams.get("category")),
      search: search || undefined,
      first,
      locale,
    });

    // La frescura la gobierna el Data Cache de Next (revalidate + tags:["posts"]
    // en `getPosts`), invalidado por el webhook de WordPress
    // (hwe-post-revalidate.php): una entrada nueva aparece sin esperar. Encima va
    // una ventana HTTP corta; la respuesta es idéntica para todo el mundo
    // (entradas publicadas, nada del visitante), así que es `public`; 60 s acota
    // cuánto puede sobrevivir a una invalidación por tag, y
    // `stale-while-revalidate` da vuelta atrás instantánea sin pedir de nuevo.
    return NextResponse.json(result, {
      headers: {
        "Cache-Control": "public, max-age=60, s-maxage=60, stale-while-revalidate=300",
      },
    });
  } catch (error) {
    logger.error({
      event: "api.blog_posts.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json(
      { error: "No se pudieron cargar las entradas." },
      { status: 502 },
    );
  }
}
