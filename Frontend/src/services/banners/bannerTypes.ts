/**
 * Tipos e interfaces para el sistema de banners
 */

/**
 * Información de membresía asociada a un elemento
 * Consistente con la estructura usada en MembershipContext
 */
export interface MembershipInfo {
  level: number;
  name: string;
  icon: string;
  color: string;
  mode?: 'cascade' | 'exact';
}

export interface CarouselImage {
  url: string;
  mobile_url?: string;
  order: number;
  title?: string;
  subtitle?: string;
  description?: string;
  cta?: string;
  link?: string;
  banner_url?: string;
  hideInfoBox?: boolean;
  /** Nivel mínimo de membresía requerido (estandarizado) */
  min_membership_level?: number;
  /** Información detallada del nivel de membresía (estandarizado) */
  min_membership_info?: MembershipInfo | null;
  // Propiedades legacy para compatibilidad (deprecated)
  /** @deprecated Usar min_membership_level */
  min_membership?: number;
  /** @deprecated Usar min_membership_info */
  membershipInfo?: MembershipInfo;
}

export interface SocialNetwork {
  name: string;
  username: string;
  url: string;
  color: string;
  icon: string;
}

export interface Banner {
  id: number;
  title: string;
  subtitle?: string;
  description?: string;
  cta?: string;
  link?: string;
  image?: string;
  imageMobile?: string;
  order: number;
  type: 'main' | 'middle' | 'bottom' | 'landing_toures' | 'experience_toures';
  socialNetworks?: SocialNetwork[];
  carouselImages?: CarouselImage[];
  /** Nivel mínimo de membresía requerido (estandarizado) */
  min_membership_level?: number;
  /** Información detallada del nivel de membresía (estandarizado) */
  min_membership_info?: MembershipInfo | null;
  // Propiedades legacy para compatibilidad (deprecated)
  /** @deprecated Usar min_membership_level */
  minMembership?: number;
  /** @deprecated Usar min_membership_info */
  membershipInfo?: MembershipInfo;
}

export interface BannersResponse {
  success?: boolean;
  data?: Banner[];
}

export type BannerType = 'main' | 'middle' | 'bottom' | 'landing_toures' | 'experience_toures';
