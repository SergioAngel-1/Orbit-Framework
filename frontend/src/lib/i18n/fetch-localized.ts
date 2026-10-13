import "server-only";
import { fetchGraphQL, type GraphQLRequestOptions } from "@/lib/graphql-client";
import { logger } from "@/lib/observability/logger";
import {
  flattenTranslations,
  secondaryLocales,
  translationLocales,
  withTranslations,
} from "@/lib/i18n/translation";

// ============================================================================
//  Lectura de contenido con traducciones.
//
//  `document` lleva marcadores `# @translation <Tipo>` (ver `translation.ts`).
//  `locale` es el idioma de la página (se piden sus traducciones) o una lista de
//  idiomas (hreflang y sitemap necesitan los slugs de todos).
//
//  Degradación suave: si WordPress no tiene el campo `translation` (el sitio
//  declara un idioma en `routing.ts` que `i18n.locales` no tiene), la lectura se
//  repite sin traducciones y sirve el contenido nativo, y el desajuste queda en
//  el log. Cualquier otro error se propaga: convertir un fallo de red en «sin
//  traducción» hornearía contenido sin traducir en la caché ISR.
// ============================================================================

/** Error de validación/ejecución de GraphQL (no de red ni HTTP). */
export function isGraphQLError(error: unknown): error is Error {
  return error instanceof Error && (error as { kind?: unknown }).kind === "graphql";
}

export async function fetchLocalizedGraphQL<TData>(
  document: string,
  locale: string | readonly string[],
  options: GraphQLRequestOptions = {},
): Promise<TData> {
  const locales =
    typeof locale === "string"
      ? translationLocales(locale)
      : locale.filter((code) => secondaryLocales().includes(code));

  try {
    return flattenTranslations(
      await fetchGraphQL<TData>(withTranslations(document, locales), options),
    );
  } catch (error) {
    if (
      locales.length > 0 &&
      isGraphQLError(error) &&
      /translation/i.test(error.message)
    ) {
      logger.error(
        { event: "i18n.translation_unavailable", locales, err: error.message },
        "WordPress no expone `translation`: se sirve el contenido nativo. Revisa que los idiomas de routing.ts coincidan con i18n.locales del Control Center.",
      );
      return fetchGraphQL<TData>(withTranslations(document, []), options);
    }
    throw error;
  }
}
