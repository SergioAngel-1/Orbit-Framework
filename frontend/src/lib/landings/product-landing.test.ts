import { beforeEach, describe, expect, it, vi } from "vitest";

const getSiteConfig = vi.hoisted(() => vi.fn());
vi.mock("@/lib/config", () => ({ getSiteConfig }));
const fetchGraphQL = vi.hoisted(() => vi.fn());
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL }));
const fetchLocalizedGraphQL = vi.hoisted(() => vi.fn());
vi.mock("@/lib/i18n/fetch-localized", () => ({ fetchLocalizedGraphQL }));
const logReadFailure = vi.hoisted(() => vi.fn());
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure }));

import {
  getProductLanding,
  getPublishedProductLandingLocales,
  getPublishedProductLandingSlugs,
  normalizeProductLanding,
} from "./product-landing";

/** `Product.landing` mínima publicada; cada test le suma lo que ejercita. */
const landing = (extra: Record<string, unknown> = {}) => ({
  published: true,
  ...extra,
});

describe("normalizeProductLanding", () => {
  it("es nula sin landing o sin publicar", () => {
    expect(normalizeProductLanding(null, "es")).toBeNull();
    expect(
      normalizeProductLanding({ published: false, heroSubtitle: "x" }, "es"),
    ).toBeNull();
    expect(normalizeProductLanding({ heroSubtitle: "x" }, "es")).toBeNull();
  });

  it("devuelve el contenido nativo en el idioma por defecto", () => {
    const result = normalizeProductLanding(
      landing({
        heroSubtitle: "Hola",
        hero_subtitle_en: "Hi",
        seoOgImage: "https://cdn/og.jpg",
        sectionEduEnabled: true,
        eduTitle: "Título",
      }),
      "es",
    );

    expect(result).toMatchObject({
      contentLang: "es",
      locales: ["es", "en"],
      heroSubtitle: "Hola",
      seoOgImage: "https://cdn/og.jpg",
      edu: { enabled: true, title: "Título" },
    });
  });

  it("en un idioma secundario usa la traducción y cae al nativo campo a campo", () => {
    const result = normalizeProductLanding(
      landing({
        heroSubtitle: "Hola",
        hero_subtitle_en: "Hi",
        seoTitle: "Título SEO",
        ctaTitle: "Compra",
      }),
      "en",
    );

    expect(result?.contentLang).toBe("en");
    expect(result?.heroSubtitle).toBe("Hi");
    // Sin traducción del campo: el nativo, nunca el de otro idioma.
    expect(result?.seoTitle).toBe("Título SEO");
    expect(result?.cta.title).toBe("Compra");
  });

  it("un idioma sin traducción de los campos de peso sirve el idioma por defecto", () => {
    const result = normalizeProductLanding(
      landing({ heroSubtitle: "Hola", cta_title_en: "Buy" }),
      "en",
    );

    expect(result?.contentLang).toBe("es");
    expect(result?.locales).toEqual(["es"]);
  });

  it("el idioma por defecto no lee las traducciones", () => {
    const result = normalizeProductLanding(
      landing({ heroSubtitle: "Hola", hero_subtitle_en: "Hi" }),
      "es",
    );
    expect(result?.heroSubtitle).toBe("Hola");
  });

  it("sanea el HTML de los campos con formato", () => {
    const result = normalizeProductLanding(
      landing({
        eduBody: '<p>Texto</p><script>alert(1)</script><img src=x onerror="alert(1)">',
        closingText: '<p><a href="javascript:alert(1)">x</a></p>',
      }),
      "es",
    );

    expect(result?.edu.body).toContain("<p>Texto</p>");
    expect(result?.edu.body).not.toMatch(/script|onerror/i);
    expect(result?.closing.text).not.toMatch(/javascript:/i);
  });

  it("solo admite colores #rrggbb (se inyectan como propiedad CSS)", () => {
    const ok = normalizeProductLanding(
      landing({ accent1: " #AABBCC ", accent2: "red; background:url(x)" }),
      "es",
    );

    expect(ok?.accents).toEqual({ primary: "#aabbcc", secondary: null });
  });

  it("valida la fecha del video y admite solo el mes", () => {
    const month = normalizeProductLanding(landing({ videoDate: "2026-03" }), "es");
    const bad = normalizeProductLanding(landing({ videoDate: "2026-02-31" }), "es");

    expect(month?.video.uploadDate).toBe("2026-03");
    expect(bad?.video.uploadDate).toBeNull();
  });

  it("los interruptores solo valen true si vienen true", () => {
    const result = normalizeProductLanding(
      landing({ sectionQualitiesEnabled: "1", sectionAdviceEnabled: true }),
      "es",
    );

    expect(result?.qualities.enabled).toBe(false);
    expect(result?.advice.enabled).toBe(true);
  });

  it("resuelve las filas de las listas al idioma", () => {
    const result = normalizeProductLanding(
      landing({
        components: [
          {
            image: "https://cdn/c.jpg",
            icon: "leaf",
            name: "Hoja",
            name_en: "Leaf",
            note: null,
          },
          { image: null, icon: null, name: null, note: "solo nota" },
        ],
        qualities: [
          { icon: "sun", image: null, title: "Sol", title_en: "Sun", text: "t" },
        ],
        heroSubtitle: "x",
        hero_subtitle_en: "y",
      }),
      "en",
    );

    expect(result?.about.components).toEqual([
      { image: "https://cdn/c.jpg", icon: "leaf", name: "Leaf", note: null },
      { image: null, icon: null, name: "", note: "solo nota" },
    ]);
    expect(result?.qualities.items).toEqual([
      { icon: "sun", image: null, title: "Sun", text: "t" },
    ]);
  });

  it("descarta los consejos sin cuerpo y traduce el encabezado", () => {
    const result = normalizeProductLanding(
      landing({
        heroSubtitle: "x",
        hero_subtitle_en: "y",
        adviceItems: [
          { heading: "¿Cómo?", heading_en: "How?", body: "Así", body_en: "Like this" },
          { heading: "Sin cuerpo", body: null },
          { heading: null, body: "  Un consejo  " },
        ],
      }),
      "en",
    );

    expect(result?.advice.items).toEqual([
      { heading: "How?", body: "Like this" },
      { heading: null, body: "Un consejo" },
    ]);
  });

  it("ignora filas que no son objetos", () => {
    const result = normalizeProductLanding(
      landing({ components: ["x", null, 3, { name: "Ok" }] }),
      "es",
    );
    expect(result?.about.components.map((c) => c.name)).toEqual(["Ok"]);
  });
});

describe("lectores", () => {
  beforeEach(() => {
    getSiteConfig
      .mockReset()
      .mockResolvedValue({ modules: { "product-landing": true } });
    fetchGraphQL.mockReset();
    fetchLocalizedGraphQL.mockReset();
    logReadFailure.mockReset();
  });

  it("con el módulo apagado ningún lector llama a WordPress", async () => {
    getSiteConfig.mockResolvedValue({ modules: { "product-landing": false } });

    expect(await getPublishedProductLandingSlugs()).toEqual([]);
    expect(await getPublishedProductLandingLocales()).toEqual({});
    expect(await getProductLanding("apagado-slug", "es")).toBeNull();

    expect(fetchGraphQL).not.toHaveBeenCalled();
    expect(fetchLocalizedGraphQL).not.toHaveBeenCalled();
  });

  it("slugs publicados: filtra lo que no es texto", async () => {
    fetchGraphQL.mockResolvedValue({
      hweProductLandingsPublished: ["uno", null, "", "dos"],
    });

    expect(await getPublishedProductLandingSlugs()).toEqual(["uno", "dos"]);
  });

  it("slugs publicados: un fallo da lista vacía y queda en el log", async () => {
    fetchGraphQL.mockRejectedValue(new Error("WordPress caído"));

    // `cache` de React memoiza por argumentos: un módulo nuevo evita la caché del test anterior.
    vi.resetModules();
    const { getPublishedProductLandingSlugs: fresh } =
      await import("./product-landing");

    expect(await fresh()).toEqual([]);
    expect(logReadFailure).toHaveBeenCalledWith(
      "landings.published_slugs_failed",
      expect.any(Error),
      expect.any(Object),
    );
  });

  it("getProductLanding pide las traducciones de los idiomas del sitio y normaliza", async () => {
    fetchLocalizedGraphQL.mockResolvedValue({
      product: { landing: landing({ heroSubtitle: "Hola", hero_subtitle_en: "Hi" }) },
    });

    const result = await getProductLanding("lector-slug-1", "en");

    expect(fetchLocalizedGraphQL).toHaveBeenCalledWith(
      expect.stringContaining("query ProductLanding"),
      ["en"],
      expect.objectContaining({ variables: { slug: "lector-slug-1" } }),
    );
    expect(result?.heroSubtitle).toBe("Hi");
  });

  it("getProductLanding: producto sin landing da nulo", async () => {
    fetchLocalizedGraphQL.mockResolvedValue({ product: { landing: null } });
    expect(await getProductLanding("lector-slug-2", "es")).toBeNull();

    fetchLocalizedGraphQL.mockResolvedValue({ product: null });
    expect(await getProductLanding("lector-slug-3", "es")).toBeNull();
  });

  it("getProductLanding propaga el fallo de WordPress (no lo convierte en «no hay landing»)", async () => {
    fetchLocalizedGraphQL.mockRejectedValue(new Error("WordPress caído"));

    await expect(getProductLanding("lector-slug-4", "es")).rejects.toThrow(
      "WordPress caído",
    );
  });

  it("idiomas: anuncia solo los que tienen traducción y registra el lote fallido", async () => {
    vi.resetModules();
    const mod = await import("./product-landing");
    fetchGraphQL.mockResolvedValue({ hweProductLandingsPublished: ["a", "b"] });
    fetchLocalizedGraphQL.mockResolvedValue({
      products: {
        nodes: [
          { slug: "a", landing: { seo_title_en: "T" } },
          { slug: "b", landing: { heroSubtitle: "solo es" } },
          { slug: "c", landing: null },
        ],
      },
    });

    expect(await mod.getPublishedProductLandingLocales()).toEqual({
      a: ["es", "en"],
      b: ["es"],
    });

    vi.resetModules();
    const failing = await import("./product-landing");
    fetchLocalizedGraphQL.mockRejectedValue(new Error("caído"));

    expect(await failing.getPublishedProductLandingLocales()).toEqual({});
    expect(logReadFailure).toHaveBeenCalledWith(
      "landings.locales_failed",
      expect.any(Error),
      expect.objectContaining({ context: { count: 2 } }),
    );
  });
});
