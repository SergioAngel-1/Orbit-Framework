import { MembershipLevel } from '../services/membership/membershipTypes';
import { getCachedMembershipLevels } from '../hooks/useMembershipLevels';

/**
 * Obtiene los niveles de membresía, usando el caché sincrónico como fallback.
 * Esto evita race conditions cuando el array de levels pasado está vacío
 * pero el caché global ya tiene datos de una carga anterior.
 * 
 * @param levels - Array de niveles pasado por el componente
 * @returns Array de niveles (del parámetro si tiene datos, del caché si no)
 */
function getLevelsWithFallback(levels: MembershipLevel[]): MembershipLevel[] {
  if (levels && levels.length > 0) {
    return levels;
  }
  // Fallback al caché sincrónico global
  return getCachedMembershipLevels();
}

/**
 * Determina si un slug corresponde a un nivel de membresía.
 * Requiere que los niveles ya estén cargados desde la API.
 */
export function isKnownMembershipSlug(slug: string, levels: MembershipLevel[]): boolean {
  const effectiveLevels = getLevelsWithFallback(levels);
  return effectiveLevels.some(l => (l.level ?? l.id) > 0 && (l.slug === slug || l.slug_en === slug));
}

/**
 * Devuelve el slug de membresía para un nivel dado en el idioma especificado.
 * Retorna undefined si el nivel es 0 (público) o no se encuentra.
 * 
 * Usa fallback al caché sincrónico si levels está vacío para evitar
 * generar URLs incorrectas durante la carga inicial.
 */
export function getMembershipSlugForLevel(
  level: number,
  lang: string,
  levels: MembershipLevel[]
): string | undefined {
  if (level <= 0) return undefined;
  const effectiveLevels = getLevelsWithFallback(levels);
  const found = effectiveLevels.find(l => (l.level ?? l.id) === level);
  if (!found) return undefined;
  return lang === 'en' ? (found.slug_en || found.slug) : found.slug;
}

/**
 * Devuelve el nivel de membresía (numérico) que corresponde a un slug dado.
 * Retorna undefined si el slug no coincide con ningún nivel > 0.
 */
export function getMembershipLevelForSlug(
  slug: string,
  levels: MembershipLevel[]
): number | undefined {
  const effectiveLevels = getLevelsWithFallback(levels);
  const found = effectiveLevels.find(l => (l.level ?? l.id) > 0 && (l.slug === slug || l.slug_en === slug));
  return found ? (found.level ?? found.id) : undefined;
}

/**
 * Devuelve la información completa del nivel de membresía para un slug dado.
 * Retorna undefined si el slug no coincide con ningún nivel > 0.
 */
export function getMembershipInfoForSlug(
  slug: string,
  levels: MembershipLevel[]
): MembershipLevel | undefined {
  const effectiveLevels = getLevelsWithFallback(levels);
  return effectiveLevels.find(l => (l.level ?? l.id) > 0 && (l.slug === slug || l.slug_en === slug));
}

/**
 * Traduce un slug de membresía de un idioma a otro.
 * Ej: translateMembershipSlug('membresia-bronce', 'en', levels) → 'bronze-membership'
 * Retorna el slug original si no se encuentra correspondencia.
 */
export function translateMembershipSlug(
  slug: string,
  targetLang: string,
  levels: MembershipLevel[]
): string {
  const effectiveLevels = getLevelsWithFallback(levels);
  const found = effectiveLevels.find(l => (l.level ?? l.id) > 0 && (l.slug === slug || l.slug_en === slug));
  if (!found) return slug;
  return targetLang === 'en' ? (found.slug_en || found.slug) : found.slug;
}

/**
 * Verifica si un slug corresponde a un nivel de membresía en cualquier idioma.
 * Útil para distinguir slugs de membresía sin traducir durante cambio de idioma.
 * Retorna el nivel numérico si es conocido, undefined si no.
 */
export function resolveMembershipSlug(
  slug: string,
  levels: MembershipLevel[]
): { level: number; expectedSlug: (lang: string) => string | undefined } | undefined {
  const effectiveLevels = getLevelsWithFallback(levels);
  const found = effectiveLevels.find(l => (l.level ?? l.id) > 0 && (l.slug === slug || l.slug_en === slug));
  if (!found) return undefined;
  const level = found.level ?? found.id;
  return {
    level,
    expectedSlug: (lang: string) => lang === 'en' ? (found.slug_en || found.slug) : found.slug
  };
}

/**
 * Construye la URL de catálogo para una categoría, incluyendo el segmento de
 * membresía si la categoría requiere un nivel > 0.
 *
 * Genera siempre en ES canónico (`/catalogo` + slug ES de membresía).
 * El LanguageContext (`localizedPath`) se encarga de traducir tanto
 * `/catalogo` → `/catalog` como los slugs de membresía al idioma destino.
 */
export function buildCatalogUrl(
  categorySlug: string,
  membershipLevel: number,
  levels: MembershipLevel[]
): string {
  const mSlug = getMembershipSlugForLevel(membershipLevel, 'es', levels);
  return mSlug ? `/catalogo/${mSlug}/${categorySlug}` : `/catalogo/${categorySlug}`;
}

/**
 * Construye la URL de detalle de producto, incluyendo el segmento de
 * membresía si el producto requiere un nivel > 0.
 *
 * Genera siempre en ES canónico (`/catalogo` + slug ES de membresía).
 * El LanguageContext (`localizedPath`) se encarga de traducir tanto
 * `/catalogo` → `/catalog` como los slugs de membresía al idioma destino.
 * 
 * IMPORTANTE: Si categorySlug está vacío o es inválido, genera una URL
 * de fallback `/catalogo/producto/{productSlug}` que el router maneja
 * buscando la categoría real del producto y redirigiendo.
 */
export function buildProductUrl(
  categorySlug: string | undefined | null,
  productSlug: string,
  membershipLevel: number,
  levels: MembershipLevel[]
): string {
  // Si no hay categoría válida, usar ruta de fallback que el router resolverá
  if (!categorySlug || categorySlug === 'productos' || categorySlug.trim() === '') {
    return `/catalogo/producto/${productSlug}`;
  }
  
  const mSlug = getMembershipSlugForLevel(membershipLevel, 'es', levels);
  return mSlug
    ? `/catalogo/${mSlug}/${categorySlug}/${productSlug}`
    : `/catalogo/${categorySlug}/${productSlug}`;
}
