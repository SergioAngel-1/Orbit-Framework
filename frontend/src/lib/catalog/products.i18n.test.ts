import { beforeEach, describe, expect, it, vi } from "vitest";

// Tres idiomas: el por defecto y dos secundarios.
vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...args: unknown[]) => fetchGraphQL(...args),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

import {
  getCategories,
  getCategory,
  getProductBySlug,
  getProducts,
  getProductUrls,
} from "./products";

const card = {
  id: "p1",
  databaseId: 1,
  name: "Camiseta",
  slug: "camiseta",
  type: "SIMPLE",
  shortDescription: "Algodón",
  tr_en: { name: "T-shirt", shortDescription: "Cotton", slug: "t-shirt" },
  tr_pt_br: { name: "Camiseta BR", shortDescription: null, slug: "camiseta-br" },
};

beforeEach(() => {
  fetchGraphQL.mockReset();
});

describe("getProducts", () => {
  it("en el idioma por defecto no pide traducciones", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { pageInfo: { hasNextPage: false, endCursor: null }, nodes: [card] },
    });

    const { products } = await getProducts({ locale: "es" });

    expect(fetchGraphQL.mock.calls[0]![0]).not.toContain("translation");
    expect(products[0]).toMatchObject({ name: "Camiseta", urlSlug: "camiseta" });
  });

  it("en un secundario pide solo ese idioma y pinta nombre, resumen y URL traducidos", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { pageInfo: { hasNextPage: false, endCursor: null }, nodes: [card] },
    });

    const { products } = await getProducts({ locale: "en" });
    const query = String(fetchGraphQL.mock.calls[0]![0]);

    expect(query).toContain('translation(locale: "en")');
    expect(query).not.toContain('locale: "pt-BR"');
    expect(products[0]).toMatchObject({
      name: "T-shirt",
      shortDescription: "Cotton",
      urlSlug: "t-shirt",
      slug: "camiseta",
    });
  });

  it("no manda al cliente el texto de los demás idiomas", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { pageInfo: { hasNextPage: false, endCursor: null }, nodes: [card] },
    });

    const { products } = await getProducts({ locale: "en" });

    expect(Object.keys(products[0]!).filter((key) => /_(en|pt_br)$/.test(key))).toEqual(
      [],
    );
  });

  it("sin traducción cae al texto nativo (nunca a otro idioma secundario)", async () => {
    fetchGraphQL.mockResolvedValue({
      products: {
        pageInfo: { hasNextPage: false, endCursor: null },
        nodes: [{ ...card, tr_en: null }],
      },
    });

    const { products } = await getProducts({ locale: "en" });

    expect(products[0]).toMatchObject({ name: "Camiseta", urlSlug: "camiseta" });
  });
});

describe("getProductBySlug", () => {
  const detail = {
    ...card,
    description: "Larga",
    seoTitle: "SEO es",
    seoDescription: "Desc es",
    tr_en: {
      ...card.tr_en,
      description: "Long",
      seoTitle: "SEO en",
      seoDescription: null,
    },
    related: { nodes: [card] },
  };

  it("pide las traducciones de TODOS los idiomas: la ficha declara el hreflang de cada uno", async () => {
    fetchGraphQL.mockResolvedValue({ product: detail });

    await getProductBySlug("camiseta", "en");
    const query = String(fetchGraphQL.mock.calls[0]![0]);

    expect(query).toContain('translation(locale: "en")');
    expect(query).toContain('translation(locale: "pt-BR")');
  });

  it("localiza la ficha y declara los tramos de URL y los idiomas en que existe", async () => {
    fetchGraphQL.mockResolvedValue({ product: detail });

    const product = await getProductBySlug("camiseta", "en");

    expect(product).toMatchObject({
      name: "T-shirt",
      description: "Long",
      seoTitle: "SEO en",
      seoDescription: "Desc es",
      urlSlug: "t-shirt",
      urlSlugs: { es: "camiseta", en: "t-shirt", "pt-BR": "camiseta-br" },
      locales: ["es", "en", "pt-BR"],
    });
    expect(product!.related[0]).toMatchObject({ name: "T-shirt", urlSlug: "t-shirt" });
  });

  it("una ficha sin nombre traducido no se anuncia en ese idioma", async () => {
    fetchGraphQL.mockResolvedValue({
      product: { ...detail, tr_pt_br: { name: null, slug: "camiseta-br" } },
    });

    const product = await getProductBySlug("camiseta", "es");

    expect(product!.locales).toEqual(["es", "en"]);
  });

  it("inexistente es null", async () => {
    fetchGraphQL.mockResolvedValue({ product: null });

    expect(await getProductBySlug("nada", "en")).toBeNull();
  });
});

describe("getCategory / getCategories", () => {
  const category = {
    id: "c1",
    databaseId: 5,
    name: "Amor propio",
    slug: "amor-propio",
    description: "Todo",
    seoTitle: null,
    seoDescription: null,
    tr_en: { name: "Self love", description: "Everything", slug: "self-love" },
    products: { nodes: [card] },
  };

  it("localiza la categoría y sus productos", async () => {
    fetchGraphQL.mockResolvedValue({ productCategory: category });

    const result = await getCategory("amor-propio", "en");

    expect(result!.category).toMatchObject({
      name: "Self love",
      description: "Everything",
      urlSlug: "self-love",
      urlSlugs: { es: "amor-propio", en: "self-love", "pt-BR": "amor-propio" },
      locales: ["es", "en"],
    });
    expect(result!.products[0]).toMatchObject({ name: "T-shirt" });
  });

  it("la lista de categorías lleva el nombre y el tramo de URL del idioma", async () => {
    fetchGraphQL.mockResolvedValue({
      productCategories: { nodes: [{ ...category, count: 2 }] },
    });

    const list = await getCategories("en");

    expect(list[0]).toMatchObject({ name: "Self love", urlSlug: "self-love" });
  });
});

describe("getProductUrls (sitemap)", () => {
  it("da el slug de cada idioma y en cuáles existe, sin importar el idioma de la página", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { nodes: [card, { slug: "solo-es", name: "Solo es" }] },
    });

    const urls = await getProductUrls();

    expect(urls).toEqual([
      {
        slug: "camiseta",
        slugs: { es: "camiseta", en: "t-shirt", "pt-BR": "camiseta-br" },
        locales: ["es", "en", "pt-BR"],
      },
      {
        slug: "solo-es",
        slugs: { es: "solo-es", en: "solo-es", "pt-BR": "solo-es" },
        locales: ["es"],
      },
    ]);
  });

  it("un fallo del catálogo da lista vacía (el sitemap sigue con el resto)", async () => {
    fetchGraphQL.mockRejectedValue(new Error("down"));

    expect(await getProductUrls()).toEqual([]);
  });
});

describe("getProductBySlug: campos de producto declarativos", () => {
  it("pide `hweFields` con el idioma de la página y los entrega como mapa", async () => {
    fetchGraphQL.mockResolvedValue({
      product: {
        ...card,
        hweFields: [
          { key: "dosis", value: "8 drops" },
          { key: "vacio", value: "  " },
        ],
      },
    });

    const product = await getProductBySlug("camiseta", "en");

    expect(fetchGraphQL.mock.calls[0]![0]).toContain("hweFields(locale: $locale)");
    expect(fetchGraphQL.mock.calls[0]![1]).toMatchObject({
      variables: { slug: "camiseta", locale: "en" },
    });
    expect(product!.fields).toEqual({ dosis: "8 drops" });
  });

  it("sin campos declarados la ficha trae un mapa vacío", async () => {
    fetchGraphQL.mockResolvedValue({ product: { ...card } });

    expect((await getProductBySlug("camiseta", "es"))!.fields).toEqual({});
  });
});
