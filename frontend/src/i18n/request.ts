import { getRequestConfig } from "next-intl/server";
import { IntlErrorCode, type IntlError } from "next-intl";
import { routing } from "./routing";
import { logger } from "@/lib/observability/logger";
import es from "./messages/es.json";
import en from "./messages/en.json";

type Locale = "es" | "en";

const messagesMap: Record<Locale, typeof es> = { es, en };

export default getRequestConfig(async ({ requestLocale }) => {
  let locale: Locale = routing.defaultLocale;
  const requested = await requestLocale;
  if (requested && routing.locales.includes(requested as Locale)) {
    locale = requested as Locale;
  }

  return {
    locale,
    messages: messagesMap[locale],
    timeZone: locale === "en" ? "America/New_York" : "Europe/Madrid",
    now: new Date(),
    // Por defecto next-intl solo hace `console.error` y renderiza el path crudo
    // de la clave (`namespace.key`) cuando falta una traducción. Con paridad
    // es/en verificada por `npm run i18n:check` esto no debería dispararse en CI,
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
