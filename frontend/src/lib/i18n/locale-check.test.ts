import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

const isFallback = vi.fn();
vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(),
  isFallbackSiteConfig: (config: unknown) => isFallback(config),
}));

const logger = vi.hoisted(() => ({ error: vi.fn(), warn: vi.fn(), info: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({ logger }));

import type { SiteConfig } from "@/lib/config/types";
import {
  checkLocalesAgainstConfig,
  localeDivergence,
  parseConfigLocales,
} from "./locale-check";

const configWith = (locales: string) => ({ i18n: { locales } }) as SiteConfig;

beforeEach(() => {
  isFallback.mockReset().mockReturnValue(false);
  logger.error.mockReset();
  logger.warn.mockReset();
});

describe("parseConfigLocales", () => {
  it("separa por comas, normaliza y descarta vacíos y repetidos", () => {
    expect(parseConfigLocales(" en, PT_br ,,en")).toEqual(["en", "pt-br"]);
    expect(parseConfigLocales("")).toEqual([]);
    expect(parseConfigLocales(undefined)).toEqual([]);
  });
});

describe("localeDivergence", () => {
  it("sin diferencias no devuelve nada, aunque el código lleve otra grafía", () => {
    expect(localeDivergence(["es", "en", "pt-BR"], "es", ["en", "pt_br"])).toEqual({
      missingInWordPress: [],
      missingInRouting: [],
    });
  });

  it("detecta los idiomas que faltan a cada lado", () => {
    expect(localeDivergence(["es", "en", "pt-BR"], "es", ["en", "fr"])).toEqual({
      missingInWordPress: ["pt-br"],
      missingInRouting: ["fr"],
    });
  });

  it("un sitio monolingüe coincide con un Control Center sin idiomas", () => {
    expect(localeDivergence(["es"], "es", [])).toEqual({
      missingInWordPress: [],
      missingInRouting: [],
    });
  });
});

describe("checkLocalesAgainstConfig", () => {
  it("coinciden: no registra nada", async () => {
    await checkLocalesAgainstConfig(async () => configWith("en,pt-BR"));

    expect(logger.error).not.toHaveBeenCalled();
    expect(logger.warn).not.toHaveBeenCalled();
  });

  it("divergen: lo registra como ERROR con las diferencias", async () => {
    await checkLocalesAgainstConfig(async () => configWith("en"));

    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "i18n.locales_mismatch",
        missingInWordPress: ["pt-br"],
      }),
      expect.any(String),
    );
  });

  it("con la config degradada avisa de que no pudo comprobar y no da error", async () => {
    isFallback.mockReturnValue(true);

    expect(await checkLocalesAgainstConfig(async () => configWith(""))).toBeNull();
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "i18n.locales_unchecked" }),
      expect.any(String),
    );
    expect(logger.error).not.toHaveBeenCalled();
  });

  it("un fallo al leer la config no lanza: queda en el log", async () => {
    const result = await checkLocalesAgainstConfig(async () => {
      throw new Error("caído");
    });

    expect(result).toBeNull();
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "i18n.locales_check_failed" }),
      expect.any(String),
    );
  });
});
