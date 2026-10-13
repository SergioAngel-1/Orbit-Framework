import "server-only";
import { cache } from "react";
import { routing } from "@/i18n/routing";
import { getLandingHeroSlide } from "@/lib/banners";
import { getProducts, getProductsBySlugs } from "@/lib/catalog/products";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import type { EditorialLandingConfig } from "@/lib/config/types";
import { htmlToPlainText } from "@/lib/format";
import { pickLocalized } from "@/lib/i18n/pick-localized";
import { editorialTranslatedLocales } from "@/lib/landings/editorial-locales";
import { isoDate } from "@/lib/landings/iso-date";
import { buildEditorialRegistry, type BaseSectionId } from "@/lib/landings/registry";
import type {
  EditorialLanding,
  LandingContentSection,
  LandingFaqEntry,
  LandingProductCard,
} from "@/lib/landings/types";
import { sanitizeHtml } from "@/lib/security/sanitize";
import { COLLECTION_ITEM_LIST_LIMIT } from "@/lib/seo/jsonld";
import type { CatalogProduct } from "@/types/catalog";

// ============================================================================
//  Lector de una landing editorial (módulo `landings`): del contenido de
//  `config.landings.editorial.<id>` al modelo `EditorialLanding`, resuelto al
//  idioma y saneado.
//
//  Con `modules.landings = false`, o con una landing que no está en el registro
//  o no está publicada, devuelve `null`. El contenido sale de la config pública
//  (que el Control Center revalida en cada guardado); lo único que consulta
//  aparte es el hero (banner `landing-<id>`) y los productos de la banda.
// ============================================================================

const SLOTS = ["s1", "s2", "s3", "s4", "s5"] as const;
const FAQ_SLOTS = ["q1", "q2", "q3", "q4", "q5", "q6"] as const;

const LAYOUTS: readonly LandingContentSection["layout"][] = [
  "image-left",
  "image-right",
  "full",
  "text-only",
  "prose-blocks",
  "divider",
  "mirror-pair",
];

/** Anclas válidas de la banda de productos; fuera de la lista cae a `faq` (el valor del panel). */
const PRODUCTS_ANCHORS: readonly BaseSectionId[] = [
  "lead",
  "s1",
  "s2",
  "s3",
  "s4",
  "s5",
  "quote",
  "faq",
];

const text = (value: unknown): string | null =>
  typeof value === "string" && value.trim() !== "" ? value : null;

/** `#rrggbb` del selector de color del panel. Nada más entra en un `style`. */
const HEX_COLOR = /^#[0-9a-fA-F]{6}$/;

function toCard(product: CatalogProduct): LandingProductCard {
  return {
    databaseId: product.databaseId,
    slug: product.slug,
    urlSlug: product.urlSlug ?? product.slug,
    name: product.name,
    price: product.price,
    image: product.image
      ? { sourceUrl: product.image.sourceUrl, altText: product.image.altText }
      : null,
  };
}

/**
 * Normaliza la config de una landing al modelo `EditorialLanding`. Pura salvo por el saneado:
 * `hero` y `products` los resuelve quien llama (`getEditorialLanding`).
 */
export function normalizeEditorialLanding(
  slug: string,
  cfg: EditorialLandingConfig,
  locale: string,
): Omit<EditorialLanding, "hero" | "products"> {
  const t = (entity: object | null | undefined, field: string): string | null =>
    text(pickLocalized(entity, field, locale));
  const html = (entity: object | null | undefined, field: string): string | null => {
    const value = t(entity, field);
    return value ? sanitizeHtml(value) : null;
  };

  const locales = editorialTranslatedLocales(cfg);

  // Slots del CMS en orden de declaración; el ID de salida es el SLOT y el ORDEN lo fija `order`.
  // `cfg.sections` puede faltar en una landing recién creada: se degrada a sin secciones.
  const sections: LandingContentSection[] = SLOTS.flatMap((slot) => {
    const section = cfg.sections?.[slot];
    return section?.enabled ? [{ slot, section }] : [];
  })
    .sort((a, b) => Number(a.section.order) - Number(b.section.order))
    .map(({ slot, section }) => {
      const layout = LAYOUTS.find((candidate) => candidate === section.layout);
      return {
        id: slot,
        layout: layout ?? "image-right",
        kicker: t(section, "kicker"),
        title: t(section, "title"),
        body: html(section, "body"),
        image: text(section.image),
        imageAlt: t(section, "image_alt"),
        image2: text(section.image_2),
        image2Alt: t(section, "image_2_alt"),
      };
    });

  const faqItems: LandingFaqEntry[] = cfg.faq?.enabled
    ? FAQ_SLOTS.flatMap((key) => {
        const row = cfg.faq?.[key] as Record<string, unknown> | undefined;
        const question = t(row, "question");
        if (!question) return [];
        return [{ question, answer: sanitizeHtml(t(row, "answer") ?? "") }];
      })
        // Sin respuesta real (`<p></p>` incluido) la fila rinde un acordeón vacío en la página y
        // un `acceptedAnswer.text` vacío en el JSON-LD (inválido): se descarta entera.
        .filter((item) => htmlToPlainText(item.answer).trim() !== "")
    : [];

  const authorName = text(cfg.seo?.author);

  return {
    slug,
    contentLang: locales.includes(locale) ? locale : routing.defaultLocale,
    locales,
    // Excepción de paleta de esta landing. La forma se valida aquí y no en el componente: un
    // valor corrupto del panel no debe llegar a un `style` inline.
    accent: HEX_COLOR.test(text(cfg.accent) ?? "") ? text(cfg.accent) : null,
    lead: cfg.lead_enabled ? html(cfg, "lead_body") : null,
    sections,
    quote:
      cfg.quote?.enabled && t(cfg.quote, "text")
        ? {
            text: t(cfg.quote, "text")!,
            author: t(cfg.quote, "author"),
            photo: text(cfg.quote.author_photo),
            backgroundImage: text(cfg.quote.background_image),
            logo: text(cfg.quote.logo),
            logoAlt: t(cfg.quote, "logo_alt"),
          }
        : null,
    faq:
      cfg.faq?.enabled && faqItems.length > 0
        ? { title: t(cfg.faq, "title"), items: faqItems }
        : null,
    product: text(cfg.product)?.trim() ?? null,
    productCtaLabel: t(cfg, "product_cta_label"),
    closing: cfg.closing?.enabled
      ? {
          title: t(cfg.closing, "title"),
          text: html(cfg.closing, "text"),
          ctaLabel: t(cfg.closing, "cta_label"),
          ctaHref: t(cfg.closing, "cta_href"),
          cta2Label: t(cfg.closing, "cta2_label"),
          cta2Href: t(cfg.closing, "cta2_href"),
          backgroundImage: text(cfg.closing.background_image),
        }
      : null,
    seo: {
      title: t(cfg.seo, "title"),
      description: t(cfg.seo, "description"),
      ogImage: text(cfg.seo?.og_image),
      datePublished: isoDate(cfg.seo?.date_published),
      dateModified: isoDate(cfg.seo?.date_modified),
      // El NOMBRE decide si hay firma: sin él no hay a quién atribuir el artículo y el JSON-LD
      // vuelve a la organización. La landing enlazada es opcional y es un identificador, no una
      // URL: el tramo público lo elige el editor y cambia.
      author: authorName
        ? { name: authorName, landingSlug: text(cfg.seo?.author_landing) }
        : null,
    },
  };
}

/**
 * La landing `slug` en `locale`, o `null` si el módulo está apagado, no está registrada, no
 * está publicada o aún no tiene contenido. Un fallo de WordPress en el hero o en los productos
 * se propaga: convertirlo en «no hay landing» hornearía un 404 en la caché ISR.
 */
export const getEditorialLanding = cache(
  async (slug: string, locale: string): Promise<EditorialLanding | null> => {
    const config = await getSiteConfig();
    if (!isModuleEnabled(config, "landings")) return null;
    if (!buildEditorialRegistry(config.landings.registry)[slug]) return null;
    const cfg = config.landings.editorial[slug];
    if (!cfg || cfg.published !== true) return null;

    // El hero no depende de nada de abajo: se lanza aquí y se espera al final, en paralelo con
    // la banda de productos. El catch mudo evita un rechazo no manejado si los productos lanzan
    // antes del `await` (el `await heroPromise` sigue viendo su propio rechazo).
    const heroPromise = getLandingHeroSlide(`landing-${slug}`, locale);
    heroPromise.catch(() => {});

    const base = normalizeEditorialLanding(slug, cfg, locale);

    let products: EditorialLanding["products"] = null;
    if (cfg.products?.enabled) {
      let items: CatalogProduct[] = [];
      if (cfg.products.mode === "manual") {
        const slugs = (text(cfg.products.manual) ?? "")
          .split("|")
          .map((item) => item.trim())
          .filter(Boolean);
        items = await getProductsBySlugs(slugs, locale);
      } else if (text(cfg.products.category)) {
        const page = await getProducts({
          category: cfg.products.category as string,
          first: COLLECTION_ITEM_LIST_LIMIT,
          locale,
        });
        items = page.products;
      }
      const after = cfg.products.after as BaseSectionId;
      products = {
        title: pickLocalized(cfg.products, "title", locale),
        items: items.map(toCard),
        after: PRODUCTS_ANCHORS.includes(after) ? after : "faq",
        categorySlug:
          cfg.products.mode === "manual" ? null : text(cfg.products.category),
      };
    }

    return { ...base, hero: await heroPromise, products };
  },
);
