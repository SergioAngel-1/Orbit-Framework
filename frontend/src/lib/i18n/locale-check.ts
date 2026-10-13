import "server-only";
import { routing } from "@/i18n/routing";
import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { logger } from "@/lib/observability/logger";
import type { SiteConfig } from "@/lib/config/types";

// ============================================================================
//  Idiomas del sitio: `routing.ts` (frontend) frente a `i18n.locales` (Control
//  Center). Son dos fuentes que deben coincidir: la primera decide qué rutas
//  existen; la segunda, qué campos traducibles ofrece WordPress. Si divergen, un
//  idioma sin campos en WordPress sirve siempre el contenido nativo, o un campo
//  traducido en WordPress nunca se muestra. Se comprueba al arrancar y se
//  registra como ERROR (no se aborta: el sitio funciona, degradado).
// ============================================================================

/** Un código de idioma comparable: minúsculas y guion (`pt_BR` → `pt-br`). */
function normalize(locale: string): string {
  return locale.trim().toLowerCase().replace(/_/g, "-");
}

/** Los idiomas secundarios de `i18n.locales` (códigos separados por comas). */
export function parseConfigLocales(raw: string | null | undefined): string[] {
  return (raw ?? "")
    .split(",")
    .map(normalize)
    .filter((locale, index, all) => locale !== "" && all.indexOf(locale) === index);
}

export interface LocaleDivergence {
  /** Idiomas de `routing.ts` que el Control Center no declara. */
  missingInWordPress: string[];
  /** Idiomas del Control Center que `routing.ts` no declara. */
  missingInRouting: string[];
}

/**
 * Diferencias entre los idiomas secundarios de `routing.ts` y los del Control
 * Center. Sin diferencias, ambas listas vacías.
 */
export function localeDivergence(
  routingLocales: readonly string[],
  defaultLocale: string,
  configLocales: readonly string[],
): LocaleDivergence {
  const fromRouting = routingLocales
    .map(normalize)
    .filter((locale) => locale !== normalize(defaultLocale));
  const fromConfig = configLocales.map(normalize);
  return {
    missingInWordPress: fromRouting.filter((locale) => !fromConfig.includes(locale)),
    missingInRouting: fromConfig.filter((locale) => !fromRouting.includes(locale)),
  };
}

/**
 * Compara `routing.locales` con `i18n.locales` de WordPress y registra un error
 * si divergen. Con la config degradada (WordPress aún no responde) no puede
 * comprobar nada: lo avisa y sigue. Nunca lanza.
 */
export async function checkLocalesAgainstConfig(
  load: () => Promise<SiteConfig> = getSiteConfig,
): Promise<LocaleDivergence | null> {
  try {
    const config = await load();
    if (isFallbackSiteConfig(config)) {
      logger.warn(
        { event: "i18n.locales_unchecked" },
        "No se pudo comprobar que routing.ts e i18n.locales coinciden: la config de WordPress no está disponible.",
      );
      return null;
    }
    const divergence = localeDivergence(
      routing.locales,
      routing.defaultLocale,
      parseConfigLocales(config.i18n.locales),
    );
    if (
      divergence.missingInWordPress.length > 0 ||
      divergence.missingInRouting.length > 0
    ) {
      logger.error(
        { event: "i18n.locales_mismatch", ...divergence },
        "Los idiomas de routing.ts no coinciden con i18n.locales del Control Center: los textos traducidos no se verán en los idiomas que falten.",
      );
    }
    return divergence;
  } catch (error) {
    logger.warn(
      {
        event: "i18n.locales_check_failed",
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo comprobar los idiomas del sitio",
    );
    return null;
  }
}
