import { routing } from "@/i18n/routing";
import { buildLlmsTxt, llmsResponse } from "@/app/_llms/build";

// ============================================================================
//  /<idioma>/llms.txt — el mismo mapa que `/llms.txt`, en cada idioma que no es
//  el canónico (`/en/llms.txt`).
//
//  Vive junto a las páginas `[locale]` y no como una carpeta por idioma: una
//  ruta con punto en el nombre queda fuera del matcher del proxy (`.*\..*`), así
//  que next-intl no la toca, y Next la resuelve con este handler sin desviar
//  el resto de `/en/...` a otro sitio. Un idioma que no es de `routing.locales`
//  (o el canónico, que tiene su propia ruta en la raíz) es un 404, no un fichero
//  inventado: lo comprueba el propio handler.
// ============================================================================
export const runtime = "nodejs";
// Dinámico a propósito: un ISR se prerenderiza en el build de la imagen, que corre SIN
// WordPress, y servía el resultado degradado (vacío / 404) hasta una hora tras cada
// despliegue. Los datos van por la Data Cache con sus tags y el CDN cachea la respuesta
// (`s-maxage`), así que pedirlo en cada petición es barato.
export const dynamic = "force-dynamic";

const SECONDARY_LOCALES = routing.locales.filter(
  (locale) => locale !== routing.defaultLocale,
);

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ locale: string }> },
): Promise<Response> {
  const { locale } = await params;
  if (!SECONDARY_LOCALES.includes(locale as (typeof SECONDARY_LOCALES)[number])) {
    return new Response("Not found", { status: 404 });
  }
  const body = await buildLlmsTxt(locale);
  if (body === null) return new Response("Not found", { status: 404 });
  return llmsResponse(body);
}
