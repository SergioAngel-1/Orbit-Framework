import { getRequestConfig } from "next-intl/server";
import { IntlErrorCode, type IntlError } from "next-intl";
import { routing } from "./routing";
import { catalogFor } from "./messages/catalogs";
import { instanceRegistry } from "@/instance/registry";
import { logger } from "@/lib/observability/logger";

// `Locale` sale de `routing.locales` (la instancia decide sus idiomas; una monolingüe
// no tiene `en`), así que este archivo no nombra ningún idioma concreto.
type Locale = (typeof routing.locales)[number];

/**
 * Zona horaria de cada idioma cuando la instancia no la declara: los valores de
 * siempre. `instanceRegistry.timeZone` (una cadena IANA o un mapa por idioma) manda.
 */
const DEFAULT_TIME_ZONES: Record<string, string> = {
  es: "Europe/Madrid",
  en: "America/New_York",
};

/**
 * Zona horaria de `locale`: la que declare la instancia (`instanceRegistry.timeZone`, una
 * cadena IANA o un mapa por idioma) o el valor de siempre si no hay ninguno. Exportada para
 * poder probarla sin montar next-intl.
 */
export function resolveTimeZone(locale: Locale): string {
  const configured = instanceRegistry.timeZone;
  if (typeof configured === "string" && configured.trim() !== "") {
    return configured;
  }
  const byLocale =
    typeof configured === "object" && configured !== null ? configured : {};
  return byLocale[locale] ?? DEFAULT_TIME_ZONES[locale] ?? "Europe/Madrid";
}

export default getRequestConfig(async ({ requestLocale }) => {
  let locale: Locale = routing.defaultLocale;
  const requested = await requestLocale;
  if (requested && routing.locales.includes(requested as Locale)) {
    locale = requested as Locale;
  }

  return {
    locale,
    messages: catalogFor(locale),
    timeZone: resolveTimeZone(locale),
    now: new Date(),
    // Por defecto next-intl solo hace `console.error` y renderiza el path crudo
    // de la clave (`namespace.key`) cuando falta una traducción. Con la paridad
    // verificada por `npm run i18n:check` esto no debería dispararse en CI,
    // pero si ocurre en runtime (una clave leída dinámicamente que no existe)
    // queda registrado de forma estructurada en vez de perderse en stdout.
    onError(error: IntlError) {
      logger.error(
        { event: "i18n.message_error", code: error.code, message: error.message },
        "Fallo resolviendo mensaje de next-intl",
      );
    },
    getMessageFallback({ namespace, key, error }) {
      const path = namespace ? `${namespace}.${key}` : key;
      return error.code === IntlErrorCode.MISSING_MESSAGE ? path : `⚠️ ${path}`;
    },
  };
});
