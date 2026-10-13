import { beforeEach, describe, expect, it, vi } from "vitest";

const getSiteConfig = vi.hoisted(() => vi.fn());
vi.mock("@/lib/config", () => ({ getSiteConfig }));
const getLandingHeroSlide = vi.hoisted(() => vi.fn());
vi.mock("@/lib/banners", () => ({ getLandingHeroSlide }));
const getProducts = vi.hoisted(() => vi.fn());
const getProductsBySlugs = vi.hoisted(() => vi.fn());
vi.mock("@/lib/catalog/products", () => ({ getProducts, getProductsBySlugs }));

import { getEditorialLanding, normalizeEditorialLanding } from "./editorial-landing";

const product = (slug: string) => ({
  databaseId: 1,
  slug,
  urlSlug: slug,
  name: slug,
  price: "10",
  image: { sourceUrl: "https://cdn/p.jpg", altText: "" },
});

describe("normalizeEditorialLanding", () => {
  it("ordena las secciones por `order` y conserva el SLOT como identidad", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      {
        published: true,
        sections: {
          s1: { enabled: true, order: "3", title: "Tres" },
          s2: { enabled: true, order: "1", title: "Uno" },
          s3: { enabled: false, order: "2", title: "Apagada" },
          s4: { enabled: true, order: "2", title: "Dos", layout: "full" },
        },
      },
      "es",
    );

    expect(landing.sections.map((s) => [s.id, s.title])).toEqual([
      ["s2", "Uno"],
      ["s4", "Dos"],
      ["s1", "Tres"],
    ]);
    expect(landing.sections[1]!.layout).toBe("full");
    // Una disposición desconocida cae a la del panel por defecto.
    expect(landing.sections[0]!.layout).toBe("image-right");
  });

  it("resuelve cada texto al idioma y cae al del idioma por defecto campo a campo", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      {
        published: true,
        sections: {
          s1: {
            enabled: true,
            order: "1",
            title: "Título",
            title_en: "Title",
            kicker: "Kicker",
          },
        },
        seo: { title: "SEO", title_en: "SEO en" },
      },
      "en",
    );

    expect(landing.contentLang).toBe("en");
    expect(landing.locales).toEqual(["es", "en"]);
    expect(landing.sections[0]).toMatchObject({ title: "Title", kicker: "Kicker" });
    expect(landing.seo.title).toBe("SEO en");
  });

  it("sin traducción de los campos de peso sirve el idioma por defecto", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      { published: true, faq: { enabled: true, title: "FAQ", title_en: "FAQ en" } },
      "en",
    );

    expect(landing.contentLang).toBe("es");
    expect(landing.locales).toEqual(["es"]);
  });

  it("sanea el HTML y valida el color de apoyo", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      {
        published: true,
        accent: "red; background:url(x)",
        lead_enabled: true,
        lead_body: '<p>Hola</p><script>alert(1)</script><img src=x onerror="alert(1)">',
        closing: { enabled: true, text: '<p><a href="javascript:alert(1)">x</a></p>' },
      },
      "es",
    );

    expect(landing.accent).toBeNull();
    expect(landing.lead).toContain("<p>Hola</p>");
    expect(landing.lead).not.toMatch(/script|onerror/i);
    expect(landing.closing!.text).not.toMatch(/javascript:/i);
    expect(
      normalizeEditorialLanding("g", { published: true, accent: "#AABBCC" }, "es")
        .accent,
    ).toBe("#AABBCC");
  });

  it("las preguntas sin respuesta real no entran (ni el FAQ vacío)", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      {
        published: true,
        faq: {
          enabled: true,
          q1: { question: "¿Cómo?", answer: "<p>Así</p>" },
          q2: { question: "¿Sin respuesta?", answer: "<p></p>" },
          q3: { question: "", answer: "<p>Sin pregunta</p>" },
        },
      },
      "es",
    );

    expect(landing.faq!.items).toEqual([{ question: "¿Cómo?", answer: "<p>Así</p>" }]);
    expect(
      normalizeEditorialLanding(
        "g",
        { published: true, faq: { enabled: true, q1: { question: "¿?", answer: "" } } },
        "es",
      ).faq,
    ).toBeNull();
  });

  it("la cita necesita texto y el cierre se pinta aunque no tenga botones", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      {
        published: true,
        quote: { enabled: true, text: "", author: "Alguien" },
        closing: { enabled: true, title: "Fin" },
      },
      "es",
    );

    expect(landing.quote).toBeNull();
    expect(landing.closing).toMatchObject({ title: "Fin", ctaHref: null });
  });

  it("firma y fechas: solo valen las válidas, y la firma exige nombre", () => {
    const landing = normalizeEditorialLanding(
      "guia",
      {
        published: true,
        seo: {
          date_published: "2026-02-31",
          date_modified: "2026-03-01",
          author: "Ana",
          author_landing: "perfil-ana",
        },
      },
      "es",
    );

    expect(landing.seo.datePublished).toBeNull();
    expect(landing.seo.dateModified).toBe("2026-03-01");
    expect(landing.seo.author).toEqual({ name: "Ana", landingSlug: "perfil-ana" });
    expect(
      normalizeEditorialLanding(
        "g",
        { published: true, seo: { author_landing: "x" } },
        "es",
      ).seo.author,
    ).toBeNull();
  });
});

describe("getEditorialLanding", () => {
  beforeEach(() => {
    getSiteConfig.mockReset();
    getLandingHeroSlide.mockReset().mockResolvedValue(null);
    getProducts.mockReset().mockResolvedValue({ products: [], pageInfo: {} });
    getProductsBySlugs.mockReset().mockResolvedValue([]);
  });

  const config = (
    editorial: Record<string, unknown>,
    enabled = true,
    registry = "g | G | article",
  ) => ({
    modules: { landings: enabled },
    landings: { registry, editorial },
  });

  it("con el módulo apagado es nula y no consulta nada", async () => {
    getSiteConfig.mockResolvedValue(config({ g: { published: true } }, false));

    expect(await getEditorialLanding("g", "es")).toBeNull();
    expect(getLandingHeroSlide).not.toHaveBeenCalled();
  });

  it.each([
    ["no registrada", config({ x: { published: true } }, true, "g | G | article")],
    ["sin contenido", config({}, true)],
    ["sin publicar", config({ g: { published: false } }, true)],
  ])("una landing %s es nula", async (_name, cfg) => {
    getSiteConfig.mockResolvedValue(cfg);

    expect(await getEditorialLanding("g", "es")).toBeNull();
  });

  it("publicada pide el hero a la locación `landing-<id>` y resuelve la banda de productos", async () => {
    getSiteConfig.mockResolvedValue(
      config({
        g: {
          published: true,
          products: {
            enabled: true,
            mode: "manual",
            manual: "a | b",
            after: "s2",
            title: "Productos",
          },
        },
      }),
    );
    getLandingHeroSlide.mockResolvedValue({
      title: "Hero",
      image: "https://cdn/h.jpg",
    });
    getProductsBySlugs.mockResolvedValue([product("a"), product("b")]);

    const landing = await getEditorialLanding("g", "es");

    expect(getLandingHeroSlide).toHaveBeenCalledWith("landing-g", "es");
    expect(getProductsBySlugs).toHaveBeenCalledWith(["a", "b"], "es");
    expect(landing!.hero).toMatchObject({ title: "Hero" });
    expect(landing!.products).toMatchObject({
      title: "Productos",
      after: "s2",
      categorySlug: null,
    });
    expect(landing!.products!.items.map((i) => i.slug)).toEqual(["a", "b"]);
  });

  it("en modo «por categoría» consulta la categoría y una ancla inválida cae a `faq`", async () => {
    getSiteConfig.mockResolvedValue(
      config({
        g: {
          published: true,
          products: {
            enabled: true,
            mode: "category",
            category: "ropa",
            after: "hero",
          },
        },
      }),
    );
    getProducts.mockResolvedValue({ products: [product("a")], pageInfo: {} });

    const landing = await getEditorialLanding("g", "es");

    expect(getProducts).toHaveBeenCalledWith(
      expect.objectContaining({ category: "ropa", locale: "es" }),
    );
    expect(landing!.products).toMatchObject({ after: "faq", categorySlug: "ropa" });
  });

  it("propaga el fallo de la consulta de productos (no lo convierte en «no hay landing»)", async () => {
    getSiteConfig.mockResolvedValue(
      config({
        g: {
          published: true,
          products: { enabled: true, mode: "manual", manual: "a" },
        },
      }),
    );
    getProductsBySlugs.mockRejectedValue(new Error("WordPress caído"));

    await expect(getEditorialLanding("g", "es")).rejects.toThrow("WordPress caído");
  });
});
