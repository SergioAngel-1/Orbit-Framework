import type { SiteConfig } from "./types";

// ============================================================================
//  Generador de CSS custom properties desde la configuración de diseño.
//
//  TOKEN_MAP declara la correspondencia entre rutas de config y variables CSS.
//  buildCssVars recorre el mapa y devuelve las declaraciones CSS como string.
//  La función es pura (sin side-effects) para facilitar los tests.
// ============================================================================

interface TokenEntry {
  /** Ruta de acceso en el objeto SiteConfig. */
  path: readonly string[];
  /** Nombre de la custom property CSS. */
  cssVar: string;
  /** Transformación opcional del valor antes de aplicarlo. */
  transform?: (value: string) => string;
}

const TOKEN_MAP: TokenEntry[] = [
  { path: ["design", "colors", "brand"],       cssVar: "--color-brand" },
  { path: ["design", "colors", "brand_dark"],  cssVar: "--color-brand-dark" },
  { path: ["design", "colors", "brand_light"], cssVar: "--color-brand-light" },
  { path: ["design", "colors", "background"],  cssVar: "--background" },
  { path: ["design", "colors", "foreground"],  cssVar: "--foreground" },
  {
    path: ["design", "typography", "font_sans"],
    cssVar: "--font-sans",
    transform: (v) => `"${v}", system-ui, sans-serif`,
  },
];

function getNestedValue(obj: Record<string, unknown>, path: readonly string[]): string | undefined {
  let current: unknown = obj;
  for (const key of path) {
    if (current === null || typeof current !== "object") return undefined;
    current = (current as Record<string, unknown>)[key];
  }
  return typeof current === "string" && current.trim() !== "" ? current : undefined;
}

/**
 * Construye las declaraciones CSS custom properties a partir de la config.
 * Devuelve una cadena lista para inyectar dentro de `:root { … }`.
 *
 * @example
 *   buildCssVars(config)
 *   // "--color-brand: #e11d48;\n  --color-brand-dark: #9f1239;\n  ..."
 */
export function buildCssVars(config: Partial<SiteConfig>): string {
  const declarations: string[] = [];

  for (const token of TOKEN_MAP) {
    const raw = getNestedValue(config as Record<string, unknown>, token.path);
    if (raw === undefined) continue;
    const value = token.transform ? token.transform(raw) : raw;
    declarations.push(`${token.cssVar}: ${value}`);
  }

  return declarations.join(";\n  ");
}
