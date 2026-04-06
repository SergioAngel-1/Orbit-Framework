/**
 * Configuración centralizada de beneficios de membresía
 * 
 * Este archivo unifica la configuración de iconos, títulos, descripciones
 * y características de todos los beneficios disponibles en el sistema.
 * 
 * IMPORTANTE: Mantener sincronizado con los handlers del backend en:
 * backend/app/public/wp-content/plugins/starter-memberships/includes/benefits/handlers/
 */

import { IconType } from 'react-icons';
import { 
  FiGift, FiStar, FiPercent, FiPackage, FiHeart, FiUsers, FiTruck,
  FiZap, FiMessageCircle, FiRefreshCw, FiMusic, FiBox, FiUser,
  FiCheck, FiInfo, FiHome, FiShoppingBag, FiCalendar, FiShield, FiClock
} from 'react-icons/fi';
import i18n from 'i18next';

type TFunc = (key: string, opts?: Record<string, unknown>) => string;

/**
 * Tipo para las claves de beneficios conocidos
 */
export type BenefitKey = 
  | 'points_multiplier'
  | 'category_discount'
  | 'exclusive_products'
  | 'birthday_bonus'
  | 'referral_bonus'
  | 'referral_membership_bonus'
  | 'free_shipping'
  | 'early_access'
  | 'priority_support'
  | 'extended_returns'
  | 'exclusive_events'
  | 'events_discount'
  | 'gift_wrapping'
  | 'personal_advisor'
  | 'delivery_options'
  | 'free_deliveries'
  | 'free_samples'
  | 'exclusive_content'
  | 'security_benefits'
  | 'partner_discount_licorera'
  | 'partner_club_casa_kush';

/**
 * Configuración base de un beneficio
 */
export interface BenefitConfig {
  /** Clave única del beneficio */
  key: BenefitKey;
  /** Icono del beneficio */
  icon: IconType;
  /** Título corto para mostrar */
  title: string;
  /** Descripción breve */
  description: string;
  /** Nivel mínimo requerido para este beneficio */
  minLevel: number;
  /** Indica si el beneficio está próximamente disponible */
  comingSoon?: boolean;
}

/**
 * Característica detallada de un beneficio
 */
export interface BenefitFeature {
  icon: IconType;
  text: string;
}

/**
 * Detalles expandidos de un beneficio (para tarjetas expandibles)
 */
export interface BenefitDetails {
  /** Descripción larga */
  description: string;
  /** Lista de características */
  features: BenefitFeature[];
}

/**
 * Configuración completa de beneficios
 * Incluye icono, título, descripción y nivel mínimo
 */
/**
 * Datos estructurales de beneficios (sin texto traducible)
 */
interface BenefitStructure {
  key: BenefitKey;
  icon: IconType;
  minLevel: number;
  comingSoon?: boolean;
}

const BENEFITS_STRUCTURE: BenefitStructure[] = [
  { key: 'points_multiplier', icon: FiStar, minLevel: 1 },
  { key: 'category_discount', icon: FiPercent, minLevel: 1 },
  { key: 'exclusive_products', icon: FiPackage, minLevel: 1 },
  { key: 'birthday_bonus', icon: FiHeart, minLevel: 1 },
  { key: 'referral_bonus', icon: FiUsers, minLevel: 1 },
  { key: 'referral_membership_bonus', icon: FiGift, minLevel: 1 },
  { key: 'free_shipping', icon: FiTruck, minLevel: 1 },
  { key: 'free_deliveries', icon: FiTruck, minLevel: 1 },
  { key: 'free_samples', icon: FiPackage, minLevel: 1 },
  { key: 'delivery_options', icon: FiTruck, minLevel: 1 },
  { key: 'early_access', icon: FiZap, minLevel: 2 },
  { key: 'priority_support', icon: FiMessageCircle, minLevel: 2 },
  { key: 'extended_returns', icon: FiRefreshCw, minLevel: 2 },
  { key: 'events_discount', icon: FiPercent, minLevel: 2 },
  { key: 'exclusive_events', icon: FiMusic, minLevel: 3 },
  { key: 'gift_wrapping', icon: FiBox, minLevel: 3 },
  { key: 'exclusive_content', icon: FiInfo, minLevel: 3 },
  { key: 'security_benefits', icon: FiShield, minLevel: 3 },
  { key: 'partner_discount_licorera', icon: FiShoppingBag, minLevel: 3 },
  { key: 'partner_club_casa_kush', icon: FiHome, minLevel: 3 },
  { key: 'personal_advisor', icon: FiUser, minLevel: 4 },
];

/**
 * Resuelve un BenefitStructure a un BenefitConfig con textos traducidos
 */
const resolveBenefitConfig = (b: BenefitStructure, t?: TFunc): BenefitConfig => ({
  ...b,
  title: (t || ((k: string) => i18n.t(k)))(`benefitsConfig:benefits.${b.key}.title`),
  description: (t || ((k: string) => i18n.t(k)))(`benefitsConfig:benefits.${b.key}.description`),
});

/**
 * Configuración completa de beneficios (con textos traducidos)
 * Usa i18n.t() por defecto, o acepta una función t() explícita
 */
export const getBenefitsConfig = (t?: TFunc): BenefitConfig[] => 
  BENEFITS_STRUCTURE.map(b => resolveBenefitConfig(b, t));

/** @deprecated Usar getBenefitsConfig(t) en su lugar para soporte i18n */
export const BENEFITS_CONFIG: BenefitConfig[] = new Proxy([] as BenefitConfig[], {
  get(_, prop) {
    const resolved = BENEFITS_STRUCTURE.map(b => resolveBenefitConfig(b));
    return (resolved as any)[prop];
  }
});

/**
 * Iconos de features por beneficio (datos estructurales, no traducibles)
 */
const DETAIL_FEATURE_ICONS: Record<string, IconType[]> = {
  delivery_options: [FiTruck, FiHome, FiClock],
  free_deliveries: [FiTruck, FiCalendar, FiCheck],
  free_samples: [FiPackage, FiStar, FiCalendar],
  referral_bonus: [FiUsers, FiUsers, FiGift],
  events_discount: [FiPercent, FiCalendar, FiStar],
  partner_discount_licorera: [FiPercent, FiShoppingBag, FiCheck],
  partner_club_casa_kush: [FiHome, FiStar, FiCalendar],
  exclusive_products: [FiPackage, FiStar, FiZap],
  exclusive_content: [FiInfo, FiStar, FiZap],
  early_access: [FiZap, FiClock, FiStar],
  security_benefits: [FiShield, FiMessageCircle, FiHeart],
  priority_support: [FiMessageCircle, FiClock, FiStar],
  points_multiplier: [FiStar, FiGift, FiZap],
  category_discount: [FiPercent, FiPackage, FiCheck],
  birthday_bonus: [FiHeart, FiGift, FiCalendar],
  referral_membership_bonus: [FiGift, FiUsers, FiCheck],
  free_shipping: [FiTruck, FiCheck, FiStar],
  extended_returns: [FiRefreshCw, FiCheck, FiShield],
  exclusive_events: [FiMusic, FiCalendar, FiStar],
  gift_wrapping: [FiBox, FiGift, FiCheck],
  personal_advisor: [FiUser, FiMessageCircle, FiStar],
};

/**
 * Detalles expandidos de beneficios (para tarjetas colapsables)
 * Resuelve textos desde i18n
 */
export const getBenefitDetailsTranslated = (key: string, t?: TFunc): BenefitDetails | undefined => {
  const icons = DETAIL_FEATURE_ICONS[key];
  if (!icons) return undefined;
  const translate = t || ((k: string) => i18n.t(k));
  const featureTexts = translate(`benefitsConfig:details.${key}.features`, { returnObjects: true } as any) as unknown as string[];
  return {
    description: translate(`benefitsConfig:details.${key}.description`),
    features: icons.map((icon, idx) => ({
      icon,
      text: Array.isArray(featureTexts) ? featureTexts[idx] : '',
    })),
  };
};

/** @deprecated Usar getBenefitDetailsTranslated(key, t) en su lugar para soporte i18n */
export const BENEFIT_DETAILS: Record<string, BenefitDetails> = new Proxy({} as Record<string, BenefitDetails>, {
  get(_, prop: string) {
    return getBenefitDetailsTranslated(prop);
  },
  has(_, prop: string) {
    return prop in DETAIL_FEATURE_ICONS;
  },
  ownKeys() {
    return Object.keys(DETAIL_FEATURE_ICONS);
  },
  getOwnPropertyDescriptor(_, prop: string) {
    if (prop in DETAIL_FEATURE_ICONS) {
      return { configurable: true, enumerable: true, value: getBenefitDetailsTranslated(prop) };
    }
    return undefined;
  }
});

/**
 * Mapa de iconos por clave de beneficio (para acceso rápido)
 */
export const BENEFIT_ICONS: Record<string, IconType> = BENEFITS_STRUCTURE.reduce(
  (acc, config) => {
    acc[config.key] = config.icon;
    return acc;
  },
  {} as Record<string, IconType>
);

/**
 * Obtener configuración de un beneficio por clave
 */
export const getBenefitConfig = (key: string, t?: TFunc): BenefitConfig | undefined => {
  const found = BENEFITS_STRUCTURE.find(b => b.key === key);
  return found ? resolveBenefitConfig(found, t) : undefined;
};

/**
 * Obtener icono de un beneficio por clave
 */
export const getBenefitIcon = (key: string): IconType => {
  return BENEFIT_ICONS[key] || FiGift;
};

/**
 * Obtener detalles expandidos de un beneficio
 */
export const getBenefitDetails = (key: string): BenefitDetails | undefined => {
  return BENEFIT_DETAILS[key];
};

/**
 * Filtrar beneficios por nivel mínimo
 */
export const getBenefitsForLevel = (level: number, t?: TFunc): BenefitConfig[] => {
  return BENEFITS_STRUCTURE.filter(b => b.minLevel <= level).map(b => resolveBenefitConfig(b, t));
};

/**
 * Obtener beneficios que se desbloquean en un nivel superior
 */
export const getUpcomingBenefits = (currentLevel: number, maxCount?: number, t?: TFunc): BenefitConfig[] => {
  const upcoming = BENEFITS_STRUCTURE.filter(b => b.minLevel > currentLevel).map(b => resolveBenefitConfig(b, t));
  return maxCount ? upcoming.slice(0, maxCount) : upcoming;
};
