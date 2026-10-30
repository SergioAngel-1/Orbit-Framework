import type { CSSProperties } from "react";
import { DarkModeScript } from "./dark-mode-toggle";
import type { ColorScheme } from "@/lib/config/types";

// ============================================================================
//  Aplica `design.color_scheme` al documento desde el servidor.
//
//  Con un esquema fijo todo sale en el HTML (sin FOUC ni diferencias al hidratar) y nadie lee
//  `localStorage` ni `prefers-color-scheme`:
//    · la clase apaga la rama `:root:not(.light):not(.dark)` de `globals.css`;
//    · `color-scheme` y su `<meta>` ajustan los controles nativos y las barras;
//    · `data-color-scheme` retira `DarkModeToggle` y hace inerte `DarkModeScript` si un layout
//      propio los sigue montando.
//  Con `system` no se añade nada: el documento es el de siempre.
// ============================================================================

export interface ColorSchemeHtmlProps {
  className?: string;
  style?: CSSProperties;
  "data-color-scheme"?: "light" | "dark";
}

/** Atributos de `<html>` para el esquema. */
export function colorSchemeHtmlProps(scheme: ColorScheme): ColorSchemeHtmlProps {
  if (scheme === "system") return {};
  return {
    className: scheme,
    style: { colorScheme: scheme },
    "data-color-scheme": scheme,
  };
}

/** Lo que va en `<head>`: el `<meta>` del esquema fijo, o el script que sigue al dispositivo. */
export function ColorSchemeHead({ scheme }: { scheme: ColorScheme }) {
  return scheme === "system" ? (
    <DarkModeScript />
  ) : (
    <meta name="color-scheme" content={scheme} />
  );
}
