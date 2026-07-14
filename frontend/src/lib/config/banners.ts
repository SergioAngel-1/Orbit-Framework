// ============================================================================
//  Banner Manager — parseo de `config.banners.slides` (HWE Control Center).
//  Formato: una línea por banner, campos separados por "|":
//    imagen | título | subtítulo | texto CTA | URL CTA | badge
//  Solo imagen y título son obligatorios. Patrón hermano de lib/seo/faq.ts.
//
//  BannerSlide es estructuralmente compatible con la prop HeroSlide del
//  carrusel heredado — definido aquí (y no importado de components/**) para
//  respetar la frontera núcleo/instancia.
// ============================================================================

export interface BannerSlide {
  id: number;
  image: string;
  title: string;
  subtitle?: string;
  cta?: string;
  ctaHref?: string;
  badge?: string;
}

export function parseBanners(raw: string): BannerSlide[] {
  return raw
    .split(/\r?\n/)
    .map((line, index) => {
      const [image = "", title = "", subtitle = "", cta = "", ctaHref = "", badge = ""] = line
        .split("|")
        .map((part) => part.trim());

      if (!image || !title) return null;

      return {
        id: index,
        image,
        title,
        ...(subtitle ? { subtitle } : {}),
        ...(cta ? { cta } : {}),
        ...(ctaHref ? { ctaHref } : {}),
        ...(badge ? { badge } : {}),
      };
    })
    .filter((slide): slide is BannerSlide => slide !== null);
}
