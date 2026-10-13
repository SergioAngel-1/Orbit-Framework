import type { ProductFieldValues } from "@/lib/instance/types";
// ============================================================================
//  Tipos del catálogo (forma de WooGraphQL ya normalizada para la UI).
// ============================================================================

export interface ProductImage {
  sourceUrl: string;
  altText: string;
  /**
   * Foto genérica compartida por varios productos, no la del producto
   * (`MediaItem.isReferenceImage`, mu-plugin hwe-media-reference). Solo la trae
   * la ficha de producto.
   */
  isReference?: boolean;
}

export interface CatalogProduct {
  id: string;
  databaseId: number;
  name: string;
  slug: string;
  type: string;
  onSale: boolean;
  price: string | null;
  regularPrice: string | null;
  salePrice: string | null;
  stockStatus: string | null;
  shortDescription: string | null;
  image: ProductImage | null;
  /**
   * Tramo de URL en el idioma de la página: el slug traducido si lo hay, si no
   * el nativo (ver `lib/i18n/url-slug.ts`). Los enlaces lo usan vía `productHref`.
   */
  urlSlug?: string;
}

export interface ProductAttributeOption {
  id: string;
  name: string;
  label: string;
  variation: boolean;
  options: string[];
}

export interface ProductVariationData {
  databaseId: number;
  price: string | null;
  regularPrice: string | null;
  salePrice: string | null;
  stockStatus: string | null;
  attributes: { name: string; value: string }[];
}

export interface ProductReview {
  id: string;
  content: string;
  date: string;
  rating: number;
  authorName: string;
}

export interface ProductDetail extends CatalogProduct {
  description: string | null;
  /** Override de la caja SEO del editor (hwe-seo). Null = usar nombre/resumen. */
  seoTitle: string | null;
  seoDescription: string | null;
  attributes: ProductAttributeOption[];
  variations: ProductVariationData[];
  reviews: { averageRating: number; items: ProductReview[] };
  related: CatalogProduct[];
  /** Tramo de URL por idioma del sitio (hreflang y canónica). */
  urlSlugs?: Record<string, string>;
  /** Idiomas del sitio en los que la ficha existe de verdad (el por defecto, siempre). */
  locales?: string[];
  /**
   * Campos de producto declarativos con valor, resueltos al idioma (`Product.hweFields`).
   * Tipados por la instancia con `ProductFieldValues`; vacío si no declara ninguno.
   */
  fields: ProductFieldValues & Record<string, string | undefined>;
}

export interface ProductCategory {
  id: string;
  databaseId: number;
  name: string;
  slug: string;
  description: string | null;
  /** Override de la caja SEO del editor (hwe-seo). Null = usar nombre/descripción. */
  seoTitle?: string | null;
  seoDescription?: string | null;
  /** Tramo de URL en el idioma de la página (ver `CatalogProduct.urlSlug`). */
  urlSlug?: string;
  /** Tramo de URL por idioma del sitio. */
  urlSlugs?: Record<string, string>;
  /** Idiomas del sitio en los que la categoría existe de verdad. */
  locales?: string[];
}

export interface PageInfo {
  hasNextPage: boolean;
  endCursor: string | null;
}

export interface ProductsPage {
  products: CatalogProduct[];
  pageInfo: PageInfo;
}
