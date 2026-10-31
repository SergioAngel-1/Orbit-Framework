import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "./defaults";
import { resolveDesignTokens, themeTokensCss } from "./tokens";
import type { ColorScheme, SiteConfig } from "./types";

// ============================================================================
//  La cascada real: `globals.css` seguido de lo que emite `ThemeTokens`.
//
//  Cada caso resuelve una variable como lo haría el navegador sobre `<html>`: gana el
//  selector que casa con mayor especificidad y, a igualdad, el último. Con los dos a la
//  vez se ve lo que un test de cada pieza por separado no veía: el `:root` de los tokens
//  tapaba a `.dark` y el tema oscuro nunca llegaba a pintar el lienzo.
// ============================================================================

const GLOBALS = readFileSync(join(process.cwd(), "src/app/globals.css"), "utf8");

interface Rule {
  selector: string;
  declarations: Map<string, string>;
}

function rules(css: string): Rule[] {
  const clean = css
    .replace(/\/\*[\s\S]*?\*\//g, "")
    // `@theme` va en una capa de Tailwind: pierde con cualquier regla sin capa.
    .replace(/@theme\s*\{[\s\S]*?\n\}/, "");
  return [...clean.matchAll(/(^|\n)([^{}@\n][^{}\n]*)\{([^{}]*)\}/g)].map((match) => ({
    selector: match[2]!.trim(),
    declarations: new Map(
      match[3]!
        .split(";")
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => {
          const colon = part.indexOf(":");
          return [part.slice(0, colon).trim(), part.slice(colon + 1).trim()];
        }),
    ),
  }));
}

/** Especificidad de los selectores que aplican a `<html>`; `null` si no casa con él. */
function specificity(selector: string, htmlClass: string): number | null {
  const table: Record<string, () => number | null> = {
    ":root": () => 10,
    ".dark": () => (htmlClass === "dark" ? 10 : null),
    ":root:not(.dark)": () => (htmlClass === "dark" ? null : 20),
  };
  return table[selector]?.() ?? null;
}

function computed(
  variable: string,
  css: string,
  htmlClass: string,
): string | undefined {
  let best: { weight: number; value: string } | undefined;
  for (const rule of rules(css)) {
    const value = rule.declarations.get(variable);
    const weight = specificity(rule.selector, htmlClass);
    if (value === undefined || weight === null) continue;
    if (!best || weight >= best.weight) best = { weight, value };
  }
  return best?.value;
}

function config(
  scheme: ColorScheme,
  colors: Partial<SiteConfig["design"]["colors"]> = {},
): SiteConfig {
  return {
    ...CONFIG_DEFAULTS,
    design: {
      ...CONFIG_DEFAULTS.design,
      color_scheme: scheme,
      colors: { ...CONFIG_DEFAULTS.design.colors, ...colors },
    },
  };
}

const page = (cfg: SiteConfig, scheme: ColorScheme) =>
  `${GLOBALS}\n${themeTokensCss(resolveDesignTokens(cfg, scheme))}`;

describe("cascada de globals.css + ThemeTokens", () => {
  describe("`system` con `.dark` (dispositivo en oscuro o selector)", () => {
    const css = page(CONFIG_DEFAULTS, "system");

    it("el lienzo, el texto y las superficies son los del tema oscuro", () => {
      expect(computed("--background", css, "dark")).toBe("#0a0a0a");
      expect(computed("--foreground", css, "dark")).toBe("#ededed");
      expect(computed("--color-surface", css, "dark")).toBe("#111827");
    });

    it("los colores claros del panel no tapan al tema oscuro", () => {
      const custom = page(
        config("system", { background: "#fffbe6", foreground: "#222222" }),
        "system",
      );

      expect(computed("--background", custom, "dark")).toBe("#0a0a0a");
      expect(computed("--foreground", custom, "dark")).toBe("#ededed");
    });

    it("el color de marca del panel vale en los dos modos", () => {
      const custom = page(config("system", { brand: "#e11d48" }), "system");

      expect(computed("--color-brand", custom, "dark")).toBe("#e11d48");
      expect(computed("--color-brand", custom, "")).toBe("#e11d48");
    });
  });

  describe("`system` en claro (sin `.dark`): nada cambia", () => {
    it("mandan los valores del panel", () => {
      const css = page(
        config("system", {
          background: "#fffbe6",
          foreground: "#222222",
          surface: "#f0f0f0",
        }),
        "system",
      );

      expect(computed("--background", css, "")).toBe("#fffbe6");
      expect(computed("--foreground", css, "")).toBe("#222222");
      expect(computed("--color-surface", css, "")).toBe("#f0f0f0");
    });
  });

  it("`light` fijo sigue mandando el panel", () => {
    const css = page(config("light", { background: "#fffbe6" }), "light");

    expect(computed("--background", css, "light")).toBe("#fffbe6");
  });

  describe("`dark` fijo", () => {
    it("con los valores por defecto gana el tema oscuro", () => {
      const css = page(CONFIG_DEFAULTS, "dark");

      expect(computed("--background", css, "dark")).toBe("#0a0a0a");
      expect(computed("--color-surface", css, "dark")).toBe("#111827");
    });

    it("un fondo elegido en el panel se respeta: ahí el panel describe el modo oscuro", () => {
      const css = page(config("dark", { background: "#101020" }), "dark");

      expect(computed("--background", css, "dark")).toBe("#101020");
    });
  });

  it("los controles nativos siguen al tema oscuro", () => {
    expect(computed("color-scheme", GLOBALS, "dark")).toBe("dark");
  });

  // Sin JS o con el script fallido no hay `.dark`, así que `dark:` no aplica: un lienzo
  // oscuro por `@media` dejaría texto claro sobre tarjetas claras.
  it("el lienzo no se oscurece por `@media` sin la clase `.dark`", () => {
    const code = GLOBALS.replace(/\/\*[\s\S]*?\*\//g, "");

    expect(code).not.toContain("prefers-color-scheme");
  });
});
