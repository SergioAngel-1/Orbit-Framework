import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "./defaults";
import { buildCssVars, isValidTokenValue, resolveDesignTokens } from "./tokens";
import type { SiteConfig } from "./types";

// ============================================================================
//  Tokens de diseño → CSS custom properties.
//
//  `buildCssVars` produce texto que se inyecta TAL CUAL dentro de un `<style>`
//  (`dangerouslySetInnerHTML` en `ThemeTokens`). Los valores vienen del Control
//  Center: sin validar, uno como `red; } </style><script>…` cierra el `<style>`
//  (el parser HTML busca `</style>` de forma literal, no lo interpreta como CSS)
//  e inyecta HTML/JS. Cada token se valida por su TIPO antes de emitirse; el que
//  no cumple se sustituye por el valor por defecto y se informa.
// ============================================================================

const PAYLOAD = "red; } </style><script>alert(1)</script>";

function withColor(name: keyof SiteConfig["design"]["colors"], value: string) {
  return {
    design: {
      ...CONFIG_DEFAULTS.design,
      colors: { ...CONFIG_DEFAULTS.design.colors, [name]: value },
    },
  };
}
function withFont(name: "font_sans" | "font_heading", value: string) {
  return {
    design: {
      ...CONFIG_DEFAULTS.design,
      typography: { ...CONFIG_DEFAULTS.design.typography, [name]: value },
    },
  };
}

describe("configuración válida", () => {
  it("con los valores por defecto emite todos los tokens de color y la fuente principal", () => {
    const css = buildCssVars(CONFIG_DEFAULTS);

    expect(css).toContain("--color-brand: #2563eb");
    expect(css).toContain("--background: #ffffff");
    expect(css).toContain('--font-sans: "Inter", system-ui, sans-serif');
  });

  it("aplica un override válido", () => {
    expect(buildCssVars(withColor("brand", "#e11d48"))).toContain(
      "--color-brand: #e11d48",
    );
    expect(buildCssVars(withFont("font_heading", "Playfair Display"))).toContain(
      '--font-heading: "Playfair Display", system-ui, sans-serif',
    );
  });

  it("una fuente sin definir (cadena vacía) no emite el token, y no es un error", () => {
    const { declarations, rejected } = resolveDesignTokens(CONFIG_DEFAULTS);

    expect(declarations.join(";")).not.toContain("--font-heading");
    expect(rejected).toEqual([]);
  });

  it.each([
    "Open Sans",
    "Source-Sans 3",
    "Barlow Semi Condensed",
    "Gill Sans'",
    "Inter",
  ])("acepta el nombre de fuente real %j", (name) => {
    expect(isValidTokenValue("font", name)).toBe(true);
  });
});

describe("colores inválidos", () => {
  it.each([
    ["no es un hex", "not-a-hex"],
    ["hex de 3 dígitos", "#fff"],
    ["hex con alfa", "#e11d48ff"],
    ["sin almohadilla", "e11d48"],
    ["con espacios alrededor", " #e11d48 "],
    ["una función CSS", "rgb(0,0,0)"],
    ["una variable", "var(--x)"],
    ["un payload que escapa del <style>", PAYLOAD],
  ])("%s: cae al valor por defecto y no se interpola", (_caso, bad) => {
    const css = buildCssVars(withColor("accent", bad));

    expect(css).toContain(`--color-accent: ${CONFIG_DEFAULTS.design.colors.accent}`);
    // Se compara la declaración entera: «#fff» es subcadena de `#ffffff` (el fondo).
    expect(css).not.toContain(`--color-accent: ${bad}`);
    expect(css).not.toMatch(/[<>]/);
  });

  it("informa del token rechazado (variable, tipo y valor)", () => {
    const { rejected } = resolveDesignTokens(withColor("accent", "not-a-hex"));

    expect(rejected).toEqual([
      { cssVar: "--color-accent", kind: "color", value: "not-a-hex" },
    ]);
  });
});

describe("fuentes inválidas", () => {
  it.each([
    ["un cierre de <style>", "</style><script>alert(1)</script>"],
    ["comillas que cierran la cadena", 'Inter", system-ui; } body { display:none'],
    ["punto y coma", "Inter;"],
    ["llaves", "Inter{}"],
    ["un salto de línea (rompe la cadena CSS)", "Inter\nSerif"],
    ["una barra invertida", "Inter\\"],
    ["un nombre desmesurado", "A".repeat(101)],
    ["empezar por un espacio", " Inter"],
  ])("%s: cae al valor por defecto y no se interpola", (_caso, bad) => {
    const css = buildCssVars(withFont("font_sans", bad));

    expect(css).toContain('--font-sans: "Inter", system-ui, sans-serif');
    expect(css).not.toContain(bad);
    expect(css).not.toMatch(/[<>]/);
  });

  it("una fuente de titulares inválida (sin valor por defecto) no emite el token", () => {
    const css = buildCssVars(withFont("font_heading", PAYLOAD));

    expect(css).not.toContain("--font-heading");
    expect(css).not.toContain("</style>");
  });
});

describe("validación por tipo", () => {
  it.each(["0", "12px", "1.5rem", "2em", "50%"])("una medida válida: %s", (v) => {
    expect(isValidTokenValue("length", v)).toBe(true);
  });

  it.each(["12", "calc(1px)", "1px; }", "1 px", "-1px", "px", "1e3px", PAYLOAD])(
    "una medida inválida: %s",
    (v) => {
      expect(isValidTokenValue("length", v)).toBe(false);
    },
  );
});

describe("el resultado nunca puede cerrar el <style>", () => {
  it("con todos los tokens hostiles a la vez", () => {
    const hostile = {
      design: {
        colors: Object.fromEntries(
          Object.keys(CONFIG_DEFAULTS.design.colors).map((k) => [k, PAYLOAD]),
        ),
        typography: {
          ...CONFIG_DEFAULTS.design.typography,
          font_sans: PAYLOAD,
          font_heading: PAYLOAD,
        },
      },
    } as unknown as Partial<SiteConfig>;

    const css = buildCssVars(hostile);

    expect(css).not.toMatch(/[<>]/);
    expect(css.toLowerCase()).not.toContain("script");
  });
});
