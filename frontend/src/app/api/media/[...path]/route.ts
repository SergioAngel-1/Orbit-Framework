import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/media/<ruta bajo wp-content/uploads> — medios de WordPress para el
//  optimizador de `next/image`.
//
//  `CmsImage` pide las imágenes como `/_next/image?url=/api/media/…`: una ruta
//  relativa idéntica en el servidor y en el navegador (ver `cmsMediaSrc`). El
//  optimizador la resuelve llamando a esta ruta dentro del propio proceso de
//  Next, y es ella la que descarga el original por la red INTERNA
//  (`WORDPRESS_INTERNAL_API_URL`), sin dar la vuelta por internet ni llevar el
//  host interno al HTML.
//
//  Superficie mínima por ser pública:
//   · el destino es `wpBaseUrl()` + `/wp-content/uploads/` + la ruta; el host
//     sale SOLO de la configuración, nunca de la petición (sin SSRF);
//   · se rechaza cualquier segmento vacío, `.`/`..` o con separador;
//   · solo se devuelven imágenes raster: ni SVG (script en nuestro origen si se
//     abre directo) ni otro tipo de archivo; `nosniff` es global.
// ============================================================================

const UPLOADS_PATH = "/wp-content/uploads/";
const UPSTREAM_TIMEOUT_MS = 10_000;
/** Igual que el `Cache-Control` que Caddy pone a `/wp-content/uploads/*`. */
const CACHE_CONTROL = "public, max-age=31536000, stale-while-revalidate=86400";

function notFound(): Response {
  return new Response("Not found", {
    status: 404,
    headers: { "Cache-Control": "no-store" },
  });
}

/** Segmentos válidos o null: nada que escape de `uploads` ni que cambie de host. */
function safeSegments(path: string[] | undefined): string[] | null {
  if (!path || path.length === 0) return null;
  for (const segment of path) {
    if (!segment || segment === "." || segment === ".." || /[/\\\0?#]/.test(segment)) {
      return null;
    }
  }
  return path;
}

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ path?: string[] }> },
): Promise<Response> {
  const segments = safeSegments((await params).path);
  if (!segments) return notFound();

  let upstream: Response;
  try {
    upstream = await wpFetch(
      `${UPLOADS_PATH}${segments.map(encodeURIComponent).join("/")}`,
      {
        signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
        redirect: "error",
        cache: "no-store",
      },
    );
  } catch {
    return new Response("Bad gateway", {
      status: 502,
      headers: { "Cache-Control": "no-store" },
    });
  }

  if (upstream.status === 404 || upstream.status === 403) return notFound();
  const type =
    upstream.headers.get("content-type")?.split(";")[0]?.trim().toLowerCase() ?? "";
  if (
    !upstream.ok ||
    !upstream.body ||
    !type.startsWith("image/") ||
    type.includes("svg")
  ) {
    await upstream.body?.cancel().catch(() => undefined);
    return upstream.ok
      ? notFound()
      : new Response("Bad gateway", {
          status: 502,
          headers: { "Cache-Control": "no-store" },
        });
  }

  const headers = new Headers({
    "Content-Type": type,
    "Cache-Control": CACHE_CONTROL,
  });
  const length = upstream.headers.get("content-length");
  if (length) headers.set("Content-Length", length);
  return new Response(upstream.body, { status: 200, headers });
}
