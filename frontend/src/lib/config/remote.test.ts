import { afterEach, describe, expect, it, vi } from "vitest";
import { deepMerge, fetchRemoteConfig } from "./remote";
import { CONFIG_DEFAULTS } from "./defaults";
import { logger } from "@/lib/observability/logger";

// Se silencia el logger real y se observa qué se registra.
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn() },
}));

// Regresión: el deepMerge de la v1.0 devolvía el valor por defecto en cada hoja
// escalar (`typeof base !== "object" → return base`), así que se descartaban
// TODOS los valores remotos: ninguna configuración de instancia (marca, paleta,
// moneda, flags) llegaba a aplicarse y el sitio renderizaba los CONFIG_DEFAULTS
// pese a que /wp-json/hwe/v1/config devolvía la config correcta, sin ningún
// error. Estos tests fijan que el override remoto gana en las hojas y que los
// defaults se conservan solo donde el remoto no trae valor.

function mockConfigResponse(body: unknown) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({
      ok: true,
      json: async () => body,
    })) as unknown as typeof fetch,
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("fetchRemoteConfig — merge de config remota", () => {
  it("los valores remotos escalares reemplazan a los defaults", async () => {
    mockConfigResponse({
      brand: { name: "Mi Tienda", tagline: "Lo mejor para ti" },
      design: { colors: { brand: "#AD98C9" } },
      ecommerce: { currency: "COP", reviews_enabled: true },
    });

    const config = await fetchRemoteConfig();

    expect(config.brand.name).toBe("Mi Tienda");
    expect(config.brand.tagline).toBe("Lo mejor para ti");
    expect(config.design.colors.brand).toBe("#AD98C9");
    expect(config.ecommerce.currency).toBe("COP");
    expect(config.ecommerce.reviews_enabled).toBe(true);
  });

  it("conserva los defaults en las claves que el remoto no incluye", async () => {
    mockConfigResponse({ brand: { name: "Mi Tienda" } });

    const config = await fetchRemoteConfig();

    expect(config.brand.name).toBe("Mi Tienda");
    // brand.url no viene en el override → se mantiene el default del framework.
    expect(config.brand.url).toBe(CONFIG_DEFAULTS.brand.url);
    expect(config.design.colors).toEqual(CONFIG_DEFAULTS.design.colors);
  });

  it("un false remoto desactiva un flag que el default tenía activo", async () => {
    // `false` es un valor, no una ausencia: no debe confundirse con null/undefined.
    // search_enabled es `true` por defecto; con un flag que ya valía false el
    // test pasaría aunque el override se descartara.
    expect(CONFIG_DEFAULTS.ecommerce.search_enabled).toBe(true);
    mockConfigResponse({ ecommerce: { search_enabled: false } });

    const config = await fetchRemoteConfig();

    expect(config.ecommerce.search_enabled).toBe(false);
  });

  it("cae a los defaults, y lo registra, si WordPress responde con error", async () => {
    vi.mocked(logger.error).mockClear();
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => ({
        ok: false,
        status: 503,
        json: async () => ({}),
      })) as unknown as typeof fetch,
    );

    const config = await fetchRemoteConfig();

    expect(config.brand.name).toBe(CONFIG_DEFAULTS.brand.name);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "site_config.fallback", reason: "HTTP 503" }),
      expect.any(String),
    );
  });

  it("cae a los defaults, y lo registra, si no hay red", async () => {
    vi.mocked(logger.error).mockClear();
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("fetch failed");
      }) as unknown as typeof fetch,
    );

    const config = await fetchRemoteConfig();

    expect(config).toEqual(CONFIG_DEFAULTS);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "site_config.fallback",
        reason: "TypeError: fetch failed",
      }),
      expect.any(String),
    );
  });
});

describe("deepMerge", () => {
  it("un override escalar reemplaza al valor por defecto", () => {
    expect(deepMerge("por-defecto", "remoto")).toBe("remoto");
    expect(deepMerge(12, 0)).toBe(0);
    expect(deepMerge(true, false)).toBe(false);
    expect(deepMerge("", "remoto")).toBe("remoto");
  });

  it("un override array reemplaza al valor por defecto, no se concatena", () => {
    expect(deepMerge(["a", "b", "c"], ["x"])).toEqual(["x"]);
    expect(deepMerge(["a", "b"], [])).toEqual([]);
    expect(deepMerge({ lista: ["a", "b"] }, { lista: ["x"] })).toEqual({
      lista: ["x"],
    });
  });

  it("null y undefined en el override conservan el valor por defecto", () => {
    expect(deepMerge("por-defecto", null)).toBe("por-defecto");
    expect(deepMerge("por-defecto", undefined)).toBe("por-defecto");
    expect(deepMerge({ a: "x", b: "y" }, { a: null, b: undefined })).toEqual({
      a: "x",
      b: "y",
    });
  });

  it("los objetos anidados se fusionan en profundidad", () => {
    const base = { a: { b: { c: 1, d: 2 }, e: 3 }, f: 4 };
    const override = { a: { b: { c: 10 } } };

    expect(deepMerge(base, override)).toEqual({
      a: { b: { c: 10, d: 2 }, e: 3 },
      f: 4,
    });
  });

  it("las claves que solo trae el remoto se adoptan", () => {
    expect(deepMerge({ a: 1 }, { a: 1, nueva: { x: "y" } })).toEqual({
      a: 1,
      nueva: { x: "y" },
    });
  });

  it("una clave que los defaults no conocen se adopta tal cual, sea de la forma que sea", () => {
    expect(deepMerge(undefined, "x")).toBe("x");
    expect(deepMerge(undefined, ["x"])).toEqual(["x"]);
    expect(deepMerge(undefined, { a: 1 })).toEqual({ a: 1 });
  });

  describe("forma distinta entre el default y el remoto: manda el default (falla suave)", () => {
    // PHP serializa un array vacío como `[]`, no como `{}`: un campo tipo mapa
    // que llegue vacío no debe destruir el objeto por defecto ni su forma.
    it("un array remoto sobre un objeto por defecto lo conserva", () => {
      expect(deepMerge({ a: 1 }, [])).toEqual({ a: 1 });
    });

    it("un escalar remoto sobre un objeto por defecto lo conserva", () => {
      expect(deepMerge({ a: 1 }, "roto")).toEqual({ a: 1 });
    });

    it("un objeto remoto sobre un escalar o un array por defecto los conserva", () => {
      expect(deepMerge("texto", { a: 1 })).toBe("texto");
      expect(deepMerge(["a"], { a: 1 })).toEqual(["a"]);
    });

    it("un escalar remoto sobre un array por defecto lo conserva", () => {
      expect(deepMerge(["a"], "x")).toEqual(["a"]);
    });

    it("la divergencia se registra con la ruta, para no perder la config en silencio", () => {
      vi.mocked(logger.warn).mockClear();

      deepMerge({ social: { x: "https://x.com/a" } }, { social: [] });

      expect(logger.warn).toHaveBeenCalledWith(
        expect.objectContaining({
          event: "site_config.shape_mismatch",
          path: "social",
        }),
        expect.any(String),
      );
    });

    it("el efecto llega a fetchRemoteConfig: un mapa vacío no borra los defaults", async () => {
      mockConfigResponse({ brand: { name: "Mi Tienda" }, social: [] });

      const config = await fetchRemoteConfig();

      expect(config.brand.name).toBe("Mi Tienda");
      expect(config.social).toEqual(CONFIG_DEFAULTS.social);
    });
  });

  it("no muta el default ni el override", () => {
    const base = { a: { b: 1 }, l: [1, 2] };
    const override = { a: { b: 2 }, l: [3] };
    const baseCopy = structuredClone(base);
    const overrideCopy = structuredClone(override);

    deepMerge(base, override);

    expect(base).toEqual(baseCopy);
    expect(override).toEqual(overrideCopy);
  });
});
