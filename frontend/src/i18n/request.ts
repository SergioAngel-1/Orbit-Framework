import { getRequestConfig } from "next-intl/server";
import { routing } from "./routing";
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
  };
});
