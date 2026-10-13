import { routing } from "@/i18n/routing";
import { buildLlmsTxt, llmsResponse } from "@/app/_llms/build";

// ============================================================================
//  /<idioma>/llms.txt — el mismo mapa que `/llms.txt`, en cada idioma que no es
//  el canónico (`/en/llms.txt`).
//
//  Vive junto a las páginas `[locale]` y no como una carpeta por idioma: una
//  ruta con punto en el nombre queda fuera del matcher del proxy (`.*\..*`), así
//  que next-intl no la toca, y Next la resuelve con este handler sin desviar
//  el resto de `/en/...` a otro sitio. `dynamicParams = false`: un idioma que
//  no es de `routing.locales` (o el canónico, que tiene su propia ruta en la
//  raíz) es un 404, no un fichero inventado.
// ============================================================================
export const runtime = "nodejs";
export const revalidate = 3600;
export const dynamicParams = false;

const SECONDARY_LOCALES = routing.locales.filter(
  (locale) => locale !== routing.defaultLocale,
);

export function generateStaticParams() {
  return SECONDARY_LOCALES.map((locale) => ({ locale }));
}

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
