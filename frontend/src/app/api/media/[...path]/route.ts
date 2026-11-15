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
//     abre directo) ni otro tipo de archivo; `nosniff` es global;
//   · tope de 25 MB (`MAX_BYTES`): se lee con cuenta y, si WordPress declara más
//     o el cuerpo lo supera, 413 sin servir nada. Es el único límite de carga
//     de la ruta: un límite por IP daría falsos 429, porque el optimizador pide
//     las decenas de imágenes de una página desde la misma dirección.
// ============================================================================

const UPLOADS_PATH = "/wp-content/uploads/";
const UPSTREAM_TIMEOUT_MS = 10_000;
/** Tamaño máximo de un original. Más es un fallo del contenido, no una foto de catálogo. */
const MAX_BYTES = 25 * 1024 * 1024;
/**
 * 30 días, igual que `images.minimumCacheTTL` de `next.config.mjs`: lo que
 * una caché intermedia ve de esta ruta no vive más que lo que el optimizador
 * guarda de ella. Un archivo resubido con el mismo nombre cambia la URL por su
 * `?ver=` (`cmsMediaSrc`), no por caducidad.
 */
const CACHE_CONTROL = "public, max-age=2592000";

function plain(status: number, text: string): Response {
  return new Response(text, { status, headers: { "Cache-Control": "no-store" } });
}

const notFound = () => plain(404, "Not found");
const tooLarge = () => plain(413, "Payload too large");
const badGateway = () => plain(502, "Bad gateway");

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

/**
 * Lee el cuerpo contando bytes (ya descomprimidos: `fetch` deshace `content-encoding`,
 * así que ni `content-length` ni la cuenta de la red valen). null si supera `MAX_BYTES`.
 */
async function readCapped(
  body: ReadableStream<Uint8Array>,
): Promise<Uint8Array | null> {
  const reader = body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > MAX_BYTES) {
      await reader.cancel().catch(() => undefined);
      return null;
    }
    chunks.push(value);
  }
  const out = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    out.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return out;
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
        // Nunca se sigue una redirección (podría sacar la petición del origen
        // interno): un medio que redirige no existe como tal → 404.
        redirect: "manual",
        cache: "no-store",
      },
    );
  } catch {
    return badGateway();
  }

  const discard = () => upstream.body?.cancel().catch(() => undefined);
  if (upstream.status >= 300 && upstream.status < 400) {
    await discard();
    return notFound();
  }
  if (upstream.status === 404 || upstream.status === 403) {
    await discard();
    return notFound();
  }
  if (!upstream.ok) {
    await discard();
    return badGateway();
  }
  const type =
    upstream.headers.get("content-type")?.split(";")[0]?.trim().toLowerCase() ?? "";
  if (!upstream.body || !type.startsWith("image/") || type.includes("svg")) {
    await discard();
    return notFound();
  }
  const declared = Number(upstream.headers.get("content-length"));
  if (Number.isFinite(declared) && declared > MAX_BYTES) {
    await discard();
    return tooLarge();
  }

  let bytes: Uint8Array | null;
  try {
    bytes = await readCapped(upstream.body);
  } catch {
    return badGateway();
  }
  if (!bytes) return tooLarge();

  // `Content-Length` es el del cuerpo que se envía (el ya descomprimido), nunca el
  // de WordPress: con `content-encoding` no coincide y cortaría la respuesta.
  return new Response(bytes as BodyInit, {
    status: 200,
    headers: {
      "Content-Type": type,
      "Content-Length": String(bytes.byteLength),
      "Cache-Control": CACHE_CONTROL,
    },
  });
}
