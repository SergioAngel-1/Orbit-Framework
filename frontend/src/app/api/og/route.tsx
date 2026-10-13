import { getSiteConfig } from "@/lib/config";
import { renderOgCard } from "@/lib/seo/brand-image";
import { routing } from "@/i18n/routing";

// Tarjeta Open Graph por defecto, generada dinámicamente desde la marca
// (Control Center). Sin assets estáticos. ISR: se refresca con la config.
//
// `?locale=` elige el idioma del lema. Sin parámetro (o con uno que no sea un
// locale del sitio) devuelve la del locale canónico: la URL sin query sigue
// siendo válida y estable para quien ya la tuviera cacheada.
export const runtime = "nodejs";
export const revalidate = 300;

export async function GET(request: Request): Promise<Response> {
  const requested = new URL(request.url).searchParams.get("locale") ?? "";
  const locale = (routing.locales as readonly string[]).includes(requested)
    ? requested
    : routing.defaultLocale;

  const config = await getSiteConfig();
  return renderOgCard(config, locale);
}
