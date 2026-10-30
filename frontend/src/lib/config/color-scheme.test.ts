import { describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "./defaults";
import type { ColorScheme, SiteConfig } from "./types";

vi.mock("./remote", () => ({
  isFallbackSiteConfig: (config: { fallback?: boolean }) => config.fallback === true,
}));

// Sin respaldo en el registro: la instancia que declare el suyo no debe cambiar estos casos.
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

import { resolveColorScheme } from "./color-scheme";

const config = (color_scheme: unknown, fallback = false) =>
  ({
    ...CONFIG_DEFAULTS,
    fallback,
    design: { ...CONFIG_DEFAULTS.design, color_scheme },
  }) as unknown as SiteConfig;

describe("resolveColorScheme", () => {
  it("sin configurar, sigue al dispositivo", () => {
    expect(resolveColorScheme(CONFIG_DEFAULTS)).toBe("system");
  });

  it.each<ColorScheme>(["system", "light", "dark"])("respeta `%s`", (scheme) => {
    expect(resolveColorScheme(config(scheme))).toBe(scheme);
  });

  it("un valor desconocido no fija nada", () => {
    expect(resolveColorScheme(config("sepia"))).toBe("system");
  });

  it("el respaldo de la instancia no pisa lo que dice WordPress", () => {
    expect(resolveColorScheme(config("dark"), "light")).toBe("dark");
  });

  it("si WordPress no respondió, manda el respaldo de la instancia", () => {
    expect(resolveColorScheme(config("system", true), "light")).toBe("light");
  });

  it("si WordPress no respondió y no hay respaldo, sigue al dispositivo", () => {
    expect(resolveColorScheme(config("system", true), undefined)).toBe("system");
  });
});
