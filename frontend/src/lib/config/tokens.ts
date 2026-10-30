import { CONFIG_DEFAULTS } from "./defaults";
import type { ColorScheme, SiteConfig } from "./types";

// ============================================================================
//  Generador de CSS custom properties desde la configuración de diseño.
//
//  TOKEN_MAP declara la correspondencia entre rutas de config y variables CSS, y el
//  TIPO de cada token. buildCssVars recorre el mapa y devuelve las declaraciones CSS
//  como string. La función es pura (sin side-effects) para facilitar los tests.
//
//  SEGURIDAD. Ese string se inyecta TAL CUAL dentro de un `<style>`
//  (`dangerouslySetInnerHTML`, ver `ThemeTokens`) y los valores vienen del Control
//  Center. Un valor como `red; } </style><script>…` cierra el `<style>` (el parser
//  HTML busca `</style>` de forma literal, no lo interpreta como CSS) e inyecta
//  HTML/JS. Por eso CADA token declara un `kind` y su valor se valida contra el
//  formato de ese tipo antes de emitirse; no hay forma de añadir un token sin
//  validar. El que no cumple se sustituye por el valor por defecto de
//  `CONFIG_DEFAULTS` (o se omite si no lo tiene) y se informa en `rejected`.
// ============================================================================

/** Tipo de un token: decide el formato que debe cumplir su valor. */
export type TokenKind = "color" | "font" | "length";

const VALIDATORS: Record<TokenKind, RegExp> = {
  // Igual que `AdminPage::sanitizeColor` del Control Center.
  color: /^#[0-9a-fA-F]{6}$/,
  // Un nombre de familia real ("Times New Roman", "Source-Sans 3", "Gill Sans'") solo
  // usa letras, dígitos, espacios, guiones y apóstrofes. Cualquier otro carácter (`<`,
  // `"`, `;`, `{`, `\`, salto de línea…) o un nombre desmesurado no lo es. Espacio
  // literal, no `\s`: un salto de línea rompe la cadena CSS entre comillas.
  font: /^[A-Za-z0-9][A-Za-z0-9 '-]{0,99}$/,
  // Cero o una medida no negativa con unidad.
  length: /^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%))$/,
};

/** ¿Cumple `value` el formato del tipo `kind`? */
export function isValidTokenValue(kind: TokenKind, value: string): boolean {
  return VALIDATORS[kind].test(value);
}

interface TokenEntry {
  /** Ruta de acceso en el objeto SiteConfig. */
  path: readonly string[];
  /** Nombre de la custom property CSS. */
  cssVar: string;
  /** Tipo del token: el formato que debe cumplir su valor. */
  kind: TokenKind;
  /** Transformación opcional del valor (ya validado) antes de aplicarlo. */
  transform?: (value: string) => string;
}

/**
 * Pila de una fuente del panel. El panel solo publica un NOMBRE: si ninguna hoja lo registra, la
 * reserva es la fuente que el layout cargó con `next/font` (`--font-inter` en el starter). Sin
 * ella, `var()` cae a `system-ui` (una variable sin definir invalidaría toda la declaración).
 */
const fontStack = (family: string) =>
  `"${family}", var(--font-inter, system-ui), sans-serif`;

const TOKEN_MAP: TokenEntry[] = [
  { path: ["design", "colors", "brand"], cssVar: "--color-brand", kind: "color" },
  {
    path: ["design", "colors", "brand_dark"],
    cssVar: "--color-brand-dark",
    kind: "color",
  },
  {
    path: ["design", "colors", "brand_light"],
    cssVar: "--color-brand-light",
    kind: "color",
  },
  {
    path: ["design", "colors", "secondary"],
    cssVar: "--color-secondary",
    kind: "color",
  },
  {
    path: ["design", "colors", "secondary_dark"],
    cssVar: "--color-secondary-dark",
    kind: "color",
  },
  { path: ["design", "colors", "accent"], cssVar: "--color-accent", kind: "color" },
  { path: ["design", "colors", "surface"], cssVar: "--color-surface", kind: "color" },
  { path: ["design", "colors", "background"], cssVar: "--background", kind: "color" },
  { path: ["design", "colors", "foreground"], cssVar: "--foreground", kind: "color" },
  {
    path: ["design", "typography", "font_sans"],
    cssVar: "--font-sans",
    kind: "font",
    transform: fontStack,
  },
  {
    path: ["design", "typography", "font_heading"],
    cssVar: "--font-heading",
    kind: "font",
    transform: fontStack,
  },
];

function getNestedValue(
  obj: Record<string, unknown>,
  path: readonly string[],
): string | undefined {
  let current: unknown = obj;
  for (const key of path) {
    if (current === null || typeof current !== "object") return undefined;
    current = (current as Record<string, unknown>)[key];
  }
  return typeof current === "string" && current.trim() !== "" ? current : undefined;
}

/** Tokens cuyo valor por defecto es claro y que `.dark` redefine en `globals.css`. */
const LIGHT_SURFACE_TOKENS = new Set(["--background", "--foreground"]);

/** Un token cuyo valor no cumplió el formato de su tipo. */
export interface RejectedToken {
  cssVar: string;
  kind: TokenKind;
  /** El valor recibido, recortado (puede ser hostil: úsalo solo para diagnóstico). */
  value: string;
}

export interface ResolvedTokens {
  /** Declaraciones `--variable: valor`, todas validadas. */
  declarations: string[];
  /** Tokens cuyo valor se descartó por no cumplir el formato. */
  rejected: RejectedToken[];
}

const MAX_REPORTED_VALUE_LENGTH = 80;

/**
 * Valida cada token de la config por su tipo. Un valor inválido se sustituye por el
 * de `CONFIG_DEFAULTS` si éste es válido (lo mismo que emitiría una config sin
 * tocar) o se omite; en ambos casos queda en `rejected` para que el llamador lo
 * registre. Un valor vacío es «sin definir», no un error.
 *
 * Con el esquema fijado en `dark`, el fondo y el texto que sigan en el valor por defecto (claros) no
 * se publican: este `<style>` va después de `globals.css` con la misma especificidad y taparía
 * el `.dark` de la hoja. Un fondo o un texto elegidos en el panel sí se respetan.
 */
export function resolveDesignTokens(
  config: Partial<SiteConfig>,
  scheme: ColorScheme | undefined = config.design?.color_scheme,
): ResolvedTokens {
  const declarations: string[] = [];
  const rejected: RejectedToken[] = [];

  for (const token of TOKEN_MAP) {
    const raw = getNestedValue(config as Record<string, unknown>, token.path);
    if (raw === undefined) continue;
    if (
      scheme === "dark" &&
      LIGHT_SURFACE_TOKENS.has(token.cssVar) &&
      raw === getNestedValue(CONFIG_DEFAULTS as unknown as Record<string, unknown>, token.path)
    ) {
      continue;
    }

    let value = raw;
    if (!isValidTokenValue(token.kind, raw)) {
      rejected.push({
        cssVar: token.cssVar,
        kind: token.kind,
        value: raw.slice(0, MAX_REPORTED_VALUE_LENGTH),
      });
      const fallback = getNestedValue(
        CONFIG_DEFAULTS as unknown as Record<string, unknown>,
        token.path,
      );
      if (fallback === undefined || !isValidTokenValue(token.kind, fallback)) continue;
      value = fallback;
    }

    declarations.push(
      `${token.cssVar}: ${token.transform ? token.transform(value) : value}`,
    );
  }

  return { declarations, rejected };
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
  return resolveDesignTokens(config).declarations.join(";\n  ");
}
