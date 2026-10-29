import type { SiteConfig } from "@/lib/config/types";
import { parseMoneyAmount } from "@/lib/format";
import type { ProductDetail } from "@/types/catalog";

// ============================================================================
//  JSON-LD `Product` con grado merchant listing: oferta, envío, devoluciones y
//  reseñas. Funciones puras.
// ============================================================================

const RETURN_CATEGORY_MAP: Record<string, string> = {
  finite: "https://schema.org/MerchantReturnFiniteReturnWindow",
  unlimited: "https://schema.org/MerchantReturnUnlimitedWindow",
  none: "https://schema.org/MerchantReturnNotPermitted",
};

/**
 * Importes de un precio de WooGraphQL: uno («&#36;12.000», «&euro;19,99») o dos
 * si es el rango de un producto variable («$10 – $20»). El `price` llega
 * FORMATEADO, con entidades HTML y separadores de la tienda: se parte por el
 * guion del rango y cada lado lo lee `parseMoneyAmount`, el mismo lector que usa
 * el resto del sitio. Vacío si no se entiende: un precio dudoso no se declara.
 */
export function parsePriceAmounts(raw?: string | null): number[] {
  const text = (raw ?? "").replace(/&ndash;|&#8211;|&mdash;|&#8212;/g, "–");
  return text
    .split(/\s[-–—]\s|[–—]/)
    .map((part) => parseMoneyAmount(part))
    .filter((amount): amount is number => amount !== null && amount >= 0);
}

/** Importe con punto decimal y sin ceros de relleno, como lo pide schema.org. */
function decimal(amount: number): string {
  return String(Number(amount.toFixed(6)));
}

/** `stockStatus` de WooGraphQL a disponibilidad de schema.org. */
const AVAILABILITY_MAP: Record<string, string> = {
  IN_STOCK: "https://schema.org/InStock",
  OUT_OF_STOCK: "https://schema.org/OutOfStock",
  ON_BACKORDER: "https://schema.org/BackOrder",
};

const RETURN_METHOD_MAP: Record<string, string[]> = {
  mail: ["https://schema.org/ReturnByMail"],
  in_store: ["https://schema.org/ReturnInStore"],
  mail_and_store: [
    "https://schema.org/ReturnByMail",
    "https://schema.org/ReturnInStore",
  ],
};

/**
 * «El cliente paga el envío de la devolución» es `ReturnFeesCustomerResponsibility`
 * (el cliente asume su mensajería), NO `ReturnShippingFees`: ese significa que
 * la TIENDA cobra una tarifa de devolución, y Google exige entonces su importe
 * en `returnShippingFeesAmount`. Los términos de venta no fijan ninguna tarifa,
 * así que declararla sería inventarla.
 */
const RETURN_FEES_MAP: Record<string, string> = {
  free: "https://schema.org/FreeReturn",
  customer_pays: "https://schema.org/ReturnFeesCustomerResponsibility",
};

/**
 * Rango de días del panel («0-1», «1 - 5», «3») a `QuantitativeValue`. `null`
 * si no se entiende: un plazo mal escrito no se declara, no se adivina.
 */
export function parseDayRange(raw?: string | null): Record<string, unknown> | null {
  const m = (raw ?? "").trim().match(/^(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?$/);
  if (!m) return null;
  const min = Number(m[1]);
  const max = m[2] === undefined ? min : Number(m[2]);
  if (max < min) return null;
  return {
    "@type": "QuantitativeValue",
    minValue: min,
    maxValue: max,
    unitCode: "DAY",
  };
}

/** Fecha de hoy (YYYY-MM-DD), para offers.validFrom. */
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Fecha (YYYY-MM-DD) a +1 año, para offers.priceValidUntil. */
function priceValidUntil(): string {
  const d = new Date();
  d.setFullYear(d.getFullYear() + 1);
  return d.toISOString().slice(0, 10);
}

export interface ProductJsonLdInput {
  product: ProductDetail;
  config: SiteConfig;
  /** URL canónica absoluta de la ficha. */
  url: string;
  /** Descripción ya en texto plano. */
  description: string;
}

/**
 * Importe o rango de importes del campo de envío del panel. `parseFloat` (no
 * `Number`) porque el campo admite texto suelto: `Number("12 EUR")` da NaN.
 * Un rango se escribe con guion («5-12»); al revés se ordena.
 */
export function parseAmountRange(raw: string): { min: number; max: number } | null {
  const text = (raw || "").trim();
  if (!text) return null;
  const range = /^(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)/.exec(text);
  if (range) {
    const a = Number.parseFloat(range[1].replace(",", "."));
    const b = Number.parseFloat(range[2].replace(",", "."));
    return { min: Math.min(a, b), max: Math.max(a, b) };
  }
  const single = Number.parseFloat(text);
  return Number.isNaN(single) ? null : { min: single, max: single };
}

/** JSON-LD `Product` con grado merchant listing (brand, rating, envío, devoluciones). */
export function buildProductJsonLd({
  product,
  config,
  url,
  description,
}: ProductJsonLdInput) {
  const currency = config.ecommerce.currency || "EUR";
  const country = config.ecommerce.country || "ES";
  const brandName = config.seo.product_brand || config.brand.name;

  // Precio: el de las variaciones si las hay (cada una trae su importe), si no
  // el de la ficha, que en un producto variable es el rango ya formateado.
  const variationAmounts = (product.variations ?? [])
    .map((variation) => parseMoneyAmount(variation.price))
    .filter((amount): amount is number => amount !== null && amount >= 0);
  const amounts =
    variationAmounts.length > 0 ? variationAmounts : parsePriceAmounts(product.price);
  const low = amounts.length > 0 ? Math.min(...amounts) : null;
  const high = amounts.length > 0 ? Math.max(...amounts) : null;

  // Un rango real (variaciones con precios distintos) es un `AggregateOffer`: un
  // `Offer` con un único precio afirmaría que todas las variantes cuestan eso.
  const offer: Record<string, unknown> =
    low !== null && high !== null && low !== high
      ? {
          "@type": "AggregateOffer",
          lowPrice: decimal(low),
          highPrice: decimal(high),
          offerCount: Math.max(variationAmounts.length, 1),
        }
      : { "@type": "Offer", ...(low !== null ? { price: decimal(low) } : {}) };
  Object.assign(offer, {
    url,
    priceCurrency: currency,
    // Mismo criterio que `priceValidUntil`: el precio publicado vale desde el
    // día en que se sirve la ficha (ISR la regenera cada pocos minutos).
    validFrom: today(),
    priceValidUntil: priceValidUntil(),
    itemCondition: "https://schema.org/NewCondition",
    availability:
      AVAILABILITY_MAP[product.stockStatus ?? ""] ?? AVAILABILITY_MAP.IN_STOCK,
  });

  // Precio tachado: solo en una oferta simple rebajada cuyo precio normal se lee
  // y es MAYOR que el que se cobra. Google lo pinta junto al precio de venta.
  const regular = parseMoneyAmount(product.regularPrice);
  if (
    offer["@type"] === "Offer" &&
    product.onSale &&
    low !== null &&
    regular !== null &&
    regular > low
  ) {
    offer.priceSpecification = {
      "@type": "UnitPriceSpecification",
      priceType: "https://schema.org/StrikethroughPrice",
      price: decimal(regular),
      priceCurrency: currency,
    };
  }

  // shippingDetails (opcional, gobernado por la pestaña SEO). El campo del
  // panel admite un importe («5», o con texto suelto: «5 EUR») o un RANGO
  // («5-12»): con tarifas por zona (módulo shipping), declarar un importe único
  // era afirmar un coste que solo paga una parte de los compradores.
  const shipping = parseAmountRange(config.seo.shipping_amount);
  if (shipping) {
    offer.shippingDetails = {
      "@type": "OfferShippingDetails",
      shippingRate: {
        "@type": "MonetaryAmount",
        ...(shipping.min === shipping.max
          ? { value: shipping.min }
          : { minValue: shipping.min, maxValue: shipping.max }),
        currency,
      },
      shippingDestination: {
        "@type": "DefinedRegion",
        addressCountry: country,
      },
    };
    // deliveryTime: solo con los DOS plazos del panel. Google lo pide completo
    // (preparación + tránsito); medio plazo sería un dato inventado.
    const handlingTime = parseDayRange(config.seo.handling_days);
    const transitTime = parseDayRange(config.seo.transit_days);
    if (handlingTime && transitTime) {
      (offer.shippingDetails as Record<string, unknown>).deliveryTime = {
        "@type": "ShippingDeliveryTime",
        handlingTime,
        transitTime,
      };
    }
  }

  // hasMerchantReturnPolicy (opcional).
  // finite → requiere días; unlimited/none se emiten sin merchantReturnDays.
  const returnCategory = config.seo.return_category || "finite";
  const hasReturnDays = config.seo.return_days !== "";
  const emitReturnPolicy =
    returnCategory === "unlimited" ||
    returnCategory === "none" ||
    (returnCategory === "finite" && hasReturnDays);
  if (emitReturnPolicy) {
    const category = RETURN_CATEGORY_MAP[returnCategory] ?? RETURN_CATEGORY_MAP.finite;
    const policy: Record<string, unknown> = {
      "@type": "MerchantReturnPolicy",
      applicableCountry: country,
      returnPolicyCountry: country,
      returnPolicyCategory: category,
    };
    if (returnCategory === "finite") {
      policy.merchantReturnDays = Number(config.seo.return_days) || 0;
    }
    // Cómo y a cargo de quién: hechos de los términos de venta, declarados en
    // el panel. Sin rellenar no se emiten (Google avisa, pero no se inventa).
    if (returnCategory !== "none") {
      const methods = RETURN_METHOD_MAP[config.seo.return_method];
      if (methods) policy.returnMethod = methods.length === 1 ? methods[0] : methods;
      const fees = RETURN_FEES_MAP[config.seo.return_fees];
      if (fees) policy.returnFees = fees;
    }
    offer.hasMerchantReturnPolicy = policy;
  }

  // `image: []` y `description: ""` no son «sin dato»: Google los lee como datos
  // declarados y vacíos (y una ficha sin foto no tiene por qué inventar una de
  // respaldo). Sin valor, la clave no se emite.
  const imageUrl = product.image?.sourceUrl?.trim();
  // La foto principal primero y después la galería, sin repetir: Google elige
  // entre varias proporciones, y una ficha con una sola foto pierde opciones.
  const images = [
    ...new Set([imageUrl, ...(product.gallery ?? [])].filter((u): u is string => !!u)),
  ];
  const hasDescription = description.trim() !== "";
  const sku = product.sku?.trim();
  const gtin = product.gtin?.trim();

  const jsonLd: Record<string, unknown> = {
    "@context": "https://schema.org/",
    "@type": "Product",
    "@id": `${url}#product`,
    name: product.name,
    ...(images.length > 0 ? { image: images } : {}),
    ...(hasDescription ? { description } : {}),
    // El SKU real de WooCommerce. El ID interno NO es un SKU: declararlo como tal
    // lo cruza con el feed de Merchant Center y no coincide.
    ...(sku ? { sku } : {}),
    ...(gtin ? { gtin } : {}),
    brand: { "@type": "Brand", name: brandName },
    // Sin precio legible no hay oferta: un importe inventado (o un 0) es peor
    // que ninguno, y Merchant Center penaliza el precio que no coincide.
    ...(low !== null ? { offers: offer } : {}),
  };

  // aggregateRating + review (solo con reseñas reales — exigido por Google) y
  // solo con `ecommerce.reviews_enabled` encendido: con el flag apagado la
  // ficha no muestra reseñas, así que declararlas aquí sería publicar en el
  // schema estrellas y opiniones que el administrador apagó.
  // `reviewCount` sale de las reseñas que trajo `getProductBySlug` (tope de
  // 100): coincide con `averageRating` salvo en un producto con más de esas
  // 100 reseñas, límite técnico de WPGraphQL que no vale la pena paginar aquí.
  const reviews = config.ecommerce.reviews_enabled
    ? (product.reviews?.items ?? [])
    : [];
  const rated = reviews.filter((r) => r.rating > 0);
  if (rated.length > 0 && product.reviews.averageRating > 0) {
    jsonLd.aggregateRating = {
      "@type": "AggregateRating",
      ratingValue: product.reviews.averageRating,
      reviewCount: rated.length,
    };
    jsonLd.review = rated.slice(0, 5).map((r) => ({
      "@type": "Review",
      reviewRating: { "@type": "Rating", ratingValue: r.rating, bestRating: 5 },
      author: { "@type": "Person", name: r.authorName },
      ...(r.date ? { datePublished: r.date } : {}),
      ...(r.content ? { reviewBody: r.content } : {}),
    }));
  }

  return jsonLd;
}
