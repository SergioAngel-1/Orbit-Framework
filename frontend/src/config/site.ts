import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

/**
 * Adaptador de compatibilidad. Los valores viven en CONFIG_DEFAULTS y se
 * actualizan dinámicamente desde el panel via getSiteConfig().
 * Para leer la config en Server Components usa getSiteConfig() de @/lib/config.
 */
export const siteConfig = {
  name:         CONFIG_DEFAULTS.brand.name,
  tagline:      CONFIG_DEFAULTS.brand.tagline,
  description:  CONFIG_DEFAULTS.brand.description,
  url:          CONFIG_DEFAULTS.brand.url,
  locale:       CONFIG_DEFAULTS.brand.locale,
  brand: {
    primary: CONFIG_DEFAULTS.design.colors.brand,
  },
  social: {
    twitter: CONFIG_DEFAULTS.social.twitter,
  },
  legal: {
    email:   CONFIG_DEFAULTS.legal.email,
    company: CONFIG_DEFAULTS.legal.company,
  },
  defaultOgImage: CONFIG_DEFAULTS.brand.og_image,
};
