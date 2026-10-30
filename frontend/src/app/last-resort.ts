import type { CSSProperties } from "react";
import { routing } from "@/i18n/routing";

// ============================================================================
//  Pantallas de último recurso (`app/error.tsx`, `app/not-found.tsx`).
//
//  Se pintan cuando el layout de `[locale]` no llegó a montarse: no hay `globals.css`
//  (se importa ahí), ni contexto de next-intl, ni config. Por eso los estilos van en
//  línea y los textos aquí: cualquier dependencia más podría ser justo lo que falló.
//  Sin más idioma que el del sitio: la URL puede no haberse resuelto todavía.
// ============================================================================

const COPY = {
  es: {
    errorTitle: "No pudimos cargar la página",
    errorBody: "Algo falló de nuestro lado. Inténtalo de nuevo en unos minutos.",
    retry: "Reintentar",
    reference: "Referencia",
    notFoundTitle: "Página no encontrada",
    notFoundBody: "La dirección no existe o ya no está disponible.",
    home: "Ir al inicio",
  },
  en: {
    errorTitle: "We couldn't load this page",
    errorBody: "Something went wrong on our side. Please try again in a few minutes.",
    retry: "Try again",
    reference: "Reference",
    notFoundTitle: "Page not found",
    notFoundBody: "This address doesn't exist or is no longer available.",
    home: "Go to the home page",
  },
} as const;

/** Textos en el idioma del sitio; un idioma sin traducción cae al canónico (`es`). */
export function lastResortCopy(locale: string = routing.defaultLocale) {
  return locale in COPY
    ? { lang: locale, ...COPY[locale as keyof typeof COPY] }
    : { lang: "es", ...COPY.es };
}

export const lastResortStyles = {
  body: {
    margin: 0,
    minHeight: "100vh",
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    padding: "2rem",
    boxSizing: "border-box",
    background: "#ffffff",
    color: "#0a0a0a",
    fontFamily: "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif",
  },
  panel: { maxWidth: "28rem", textAlign: "center" },
  title: { margin: 0, fontSize: "1.75rem", lineHeight: 1.2 },
  text: { margin: "0.75rem 0 1.5rem", color: "#4a4a4a", lineHeight: 1.5 },
  action: {
    display: "inline-block",
    padding: "0.625rem 1.25rem",
    border: 0,
    borderRadius: "0.5rem",
    background: "#111111",
    color: "#ffffff",
    font: "inherit",
    fontWeight: 600,
    textDecoration: "none",
    cursor: "pointer",
  },
  reference: { margin: "1.5rem 0 0", fontSize: "0.75rem", color: "#6b6b6b" },
} satisfies Record<string, CSSProperties>;
