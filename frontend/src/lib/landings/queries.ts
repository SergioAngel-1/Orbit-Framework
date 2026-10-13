import { LANDING_PROBE_FIELDS } from "@/lib/landings/localized";

// ============================================================================
//  Consultas de la landing de producto (módulo `product-landing`).
//
//  El esquema vive escrito a mano en cuatro sitios (`Fields.php` del plugin,
//  esta lista, `product-landing.ts` y `types.ts`): `tests/unit/product-landing-parity.test.ts`
//  los compara con el plugin para que un campo nuevo no se pierda en silencio.
//
//  Las traducciones no se escriben a mano: cada selección lleva el marcador
//  `# @translation <Tipo>: campos` de `lib/i18n/translation.ts`, que
//  `fetchLocalizedGraphQL` sustituye por `tr_<idioma>: translation(locale: …)`.
// ============================================================================

/** Campos escalares de `ProductLanding` (nombre de GraphQL), en el orden del plugin. */
export const LANDING_SCALAR_FIELDS = [
  "published",
  "seoTitle",
  "seoDescription",
  "seoOgImage",
  "accent1",
  "accent2",
  "heroSubtitle",
  "heroImage",
  "sectionEduEnabled",
  "eduKicker",
  "eduTitle",
  "eduBody",
  "eduImage",
  "aboutTitle",
  "aboutBody",
  "sectionQualitiesEnabled",
  "qualitiesTitle",
  "sectionAdviceEnabled",
  "adviceTitle",
  "adviceIntro",
  "ctaTitle",
  "ctaText",
  "sectionVideoEnabled",
  "videoUrl",
  "videoCaption",
  "videoPoster",
  "videoDate",
  "closingTitle",
  "closingText",
  "closingCtaLabel",
  "closingCtaHref",
] as const;

/** De los anteriores, los que tienen versión por idioma. */
export const LANDING_TRANSLATABLE_SCALARS = [
  "seoTitle",
  "seoDescription",
  "heroSubtitle",
  "eduKicker",
  "eduTitle",
  "eduBody",
  "aboutTitle",
  "aboutBody",
  "qualitiesTitle",
  "adviceTitle",
  "adviceIntro",
  "ctaTitle",
  "ctaText",
  "videoCaption",
  "closingTitle",
  "closingText",
  "closingCtaLabel",
  "closingCtaHref",
] as const;

/** Campos de cada lista (nombre de GraphQL) y cuáles se traducen. */
export const LANDING_ROW_FIELDS = {
  components: {
    fields: ["image", "icon", "name", "note"],
    translatable: ["name", "note"],
  },
  qualities: {
    fields: ["icon", "image", "title", "text"],
    translatable: ["title", "text"],
  },
  advice: { fields: ["heading", "body"], translatable: ["heading", "body"] },
} as const;

/** Nombre del campo de GraphQL de cada lista y de su tipo. */
const ROW_GRAPHQL = {
  components: { field: "components", type: "ProductLandingComponent" },
  qualities: { field: "qualities", type: "ProductLandingQuality" },
  advice: { field: "adviceItems", type: "ProductLandingAdvice" },
} as const;

function rowSelection(key: keyof typeof ROW_GRAPHQL): string {
  const { fields, translatable } = LANDING_ROW_FIELDS[key];
  const { field, type } = ROW_GRAPHQL[key];
  return `${field} {
    ${fields.join("\n    ")}
    # @translation ${type}: ${translatable.join(" ")}
  }`;
}

/**
 * Selección completa de `Product.landing`: los escalares, las traducciones de
 * los traducibles y las tres listas con las suyas.
 */
export const PRODUCT_LANDING_SELECTION = /* GraphQL */ `
  ${LANDING_SCALAR_FIELDS.join("\n  ")}
  # @translation ProductLanding: ${LANDING_TRANSLATABLE_SCALARS.join(" ")}
  ${rowSelection("components")}
  ${rowSelection("qualities")}
  ${rowSelection("advice")}
`;

export const PRODUCT_LANDING_QUERY = /* GraphQL */ `
  query ProductLanding($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      landing {
        ${PRODUCT_LANDING_SELECTION}
      }
    }
  }
`;

/**
 * Slugs de producto con landing publicada. Para `generateStaticParams`, el
 * sitemap y el anillo de «Sigue aprendiendo»: 1 fetch en vez de pedir
 * `Product.landing` entera producto a producto solo para el booleano.
 */
export const PRODUCT_LANDINGS_PUBLISHED_QUERY = /* GraphQL */ `
  query ProductLandingsPublished {
    hweProductLandingsPublished
  }
`;

/**
 * Productos con landing publicada por lote de slugs: lo que hace falta para las
 * tarjetas de «Sigue aprendiendo» (nombre, imagen, resumen) en el idioma pedido.
 */
export const RELATED_LANDING_PRODUCTS_QUERY = /* GraphQL */ `
  query RelatedLandingProducts($slugs: [String]) {
    products(first: 100, where: { slugIn: $slugs, status: "publish" }) {
      nodes {
        slug
        name
        # @translation Product: name shortDescription slug
        image {
          sourceUrl
        }
        landing {
          heroSubtitle
          # @translation ProductLanding: heroSubtitle
        }
        ... on SimpleProduct {
          shortDescription
        }
        ... on VariableProduct {
          shortDescription
        }
      }
    }
  }
`;

/**
 * Idiomas en los que existe la landing de cada producto publicado (solo las
 * traducciones de los campos de peso): lo usa el sitemap para anunciar un
 * idioma únicamente si la landing está traducida.
 */
export const LANDING_LOCALES_QUERY = /* GraphQL */ `
  query ProductLandingLocales($slugs: [String]) {
    products(first: 100, where: { slugIn: $slugs, status: "publish" }) {
      nodes {
        slug
        landing {
          # @translation ProductLanding: ${LANDING_PROBE_FIELDS.join(" ")}
          heroSubtitle
        }
      }
    }
  }
`;
