/**
 * Hook useSEO - Gestión de SEO dinámico para páginas React
 * Aplica meta tags, Open Graph, Twitter Cards y Schema.org
 */

import { useEffect } from 'react';
import { applySEO, applySEOByPage, buildProductSchema, SEOConfig, SEOPageKey, getBaseUrl } from '../utils/seo';
import i18n from '../config/i18n';

/**
 * Hook para aplicar SEO a una página usando configuración personalizada
 * 
 * @example
 * ```tsx
 * useSEO({
 *   title: 'Mi Página | My Store',
 *   description: 'Descripción de mi página',
 *   image: 'https://example.com/mi-imagen.jpg'
 * });
 * ```
 */
export function useSEO(config: SEOConfig): void {
  useEffect(() => {
    applySEO(config);
  }, [config.title, config.description, config.url, config.image, config.noIndex, config.noCanonical, config.canonicalUrl]);
}

/**
 * Hook para aplicar SEO usando una configuración predefinida
 * 
 * @example
 * ```tsx
 * useSEOPage('home');
 * useSEOPage('catalogo');
 * useSEOPage('membresias');
 * ```
 */
export function useSEOPage(pageName: SEOPageKey): void {
  useEffect(() => {
    applySEOByPage(pageName as string);
  }, [pageName, i18n.language]);
}

/**
 * Hook para SEO de productos dinámicos
 * 
 * @example
 * ```tsx
 * useSEOProduct({
 *   name: 'Producto Cannabis',
 *   description: 'Descripción del producto',
 *   image: 'https://...',
 *   price: 50000,
 *   slug: 'producto-cannabis'
 * });
 * ```
 */
export function useSEOProduct(product: {
  name: string;
  description: string;
  image?: string;
  price?: number;
  slug: string;
  categorySlug?: string;
  /** Prefijo de membresía en la URL (ej: 'plata', 'oro') */
  membershipSlug?: string;
  sku?: string;
  stockStatus?: string;
  averageRating?: string;
  ratingCount?: number;
}): void {
  useEffect(() => {
    if (!product.name) return;
    
    const baseUrl = getBaseUrl();
    const categoryPath = product.membershipSlug && product.categorySlug
      ? `/catalogo/${product.membershipSlug}/${product.categorySlug}/${product.slug}`
      : product.categorySlug
        ? `/catalogo/${product.categorySlug}/${product.slug}`
        : `/catalogo/producto/${product.slug}`;
    const productUrl = `${baseUrl}${categoryPath}`;

    // Limpiar HTML de la descripción para meta tags y Schema.org
    const cleanDesc = product.description
      .replace(/<[^>]*>/g, '').replace(/&[^;]+;/g, ' ').replace(/\s+/g, ' ').trim();

    const schema = buildProductSchema({
      name: product.name,
      description: cleanDesc,
      url: productUrl,
      image: product.image,
      sku: product.sku,
      price: product.price,
      stockStatus: product.stockStatus,
      averageRating: product.averageRating,
      ratingCount: product.ratingCount,
    });

    applySEO({
      title: `${product.name} ${i18n.t('seo:product.titleSuffix')}`,
      description: cleanDesc || i18n.t('seo:product.defaultDescription', { name: product.name }),
      image: product.image,
      url: productUrl,
      type: 'product',
      schema,
    });
  }, [product.name, product.description, product.image, product.price, product.slug, product.categorySlug, product.membershipSlug, product.sku, product.stockStatus, product.averageRating, product.ratingCount]);
}

/**
 * Hook para SEO de categorías dinámicas
 * 
 * @example
 * ```tsx
 * useSEOCategory({
 *   name: 'Flores',
 *   description: 'Categoría de flores cannabis',
 *   slug: 'flores',
 *   image: 'https://...'
 * });
 * ```
 */
export function useSEOCategory(category: {
  name: string;
  description?: string;
  slug: string;
  image?: string;
  /** Prefijo de membresía en la URL (ej: 'plata', 'oro') */
  membershipSlug?: string;
}): void {
  useEffect(() => {
    if (!category.name) return;
    
    const baseUrl = getBaseUrl();
    
    applySEO({
      title: `${category.name} ${i18n.t('seo:category.titleSuffix')}`,
      description: category.description || i18n.t('seo:category.defaultDescription', { name: category.name }),
      image: category.image,
      url: `${baseUrl}${category.membershipSlug ? `/catalogo/${category.membershipSlug}/${category.slug}` : `/catalogo/${category.slug}`}`,
      type: 'website',
      schema: {
        '@context': 'https://schema.org',
        '@type': 'CollectionPage',
        name: category.name,
        description: category.description,
        url: `${baseUrl}${category.membershipSlug ? `/catalogo/${category.membershipSlug}/${category.slug}` : `/catalogo/${category.slug}`}`,
      },
    });
  }, [category.name, category.description, category.slug, category.image, category.membershipSlug]);
}

export default useSEO;
