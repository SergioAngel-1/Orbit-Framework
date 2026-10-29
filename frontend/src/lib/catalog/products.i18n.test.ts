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
// El catálogo real consulta la config para elegir la variante con o sin el campo
// del módulo `memberships`; aquí se fija apagado (la variante por defecto).
vi.mock("@/lib/memberships/catalog-flag", () => ({
  membershipsCatalogEnabled: vi.fn(async () => false),
}));

import {
  getCategories,
  getCategory,
  getProductBySlug,
  getProducts,
  getCategoryStaticParams,
  getProductUrls,
} from "./products";
import { logger } from "@/lib/observability/logger";

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
  vi.mocked(logger.warn).mockClear();
});

describe("getProducts: variables que acepta WooGraphQL", () => {
  const empty = {
    products: { pageInfo: { hasNextPage: false, endCursor: null }, nodes: [] },
  };
  const sent = () =>
    (fetchGraphQL.mock.calls[0]![1] as { variables: Record<string, unknown> })
      .variables;

  it("los precios del filtro viajan como número (`Float`), no como texto", async () => {
    fetchGraphQL.mockResolvedValue(empty);

    await getProducts({ minPrice: "10", maxPrice: "99.5" });

    expect(sent().minPrice).toBe(10);
    expect(sent().maxPrice).toBe(99.5);
  });

  it("un precio vacío o ilegible no se envía", async () => {
    fetchGraphQL.mockResolvedValue(empty);

    await getProducts({ minPrice: "", maxPrice: "abc" });

    expect(sent().minPrice).toBeUndefined();
    expect(sent().maxPrice).toBeUndefined();
  });

  it("ordenar por nombre usa NAME (ProductsOrderByEnum no tiene TITLE)", async () => {
    fetchGraphQL.mockResolvedValue(empty);

    await getProducts({ sort: "title-asc" });

    expect(sent().orderbyField).toBe("NAME");
  });
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

  it("WooGraphQL lanza ante un slug desconocido: es «no existe» (null), no un fallo", async () => {
    // `product(idType: SLUG)` responde `errors: [...]` en vez de `product: null`.
    fetchGraphQL.mockRejectedValue(
      Object.assign(
        new Error(
          "Error de GraphQL: No product ID was found corresponding to the slug: nada",
        ),
        { kind: "graphql" },
      ),
    );

    expect(await getProductBySlug("nada", "en")).toBeNull();
  });

  it("un error de GraphQL que no es «no existe» se propaga", async () => {
    fetchGraphQL.mockRejectedValue(
      Object.assign(new Error("Error de GraphQL: Internal server error"), {
        kind: "graphql",
      }),
    );

    await expect(getProductBySlug("camiseta", "en")).rejects.toThrow(/Internal/);
  });

  it("un fallo de red se propaga: no se convierte en «no existe»", async () => {
    fetchGraphQL.mockRejectedValue(
      Object.assign(new Error("fetch failed"), { kind: "network" }),
    );

    await expect(getProductBySlug("camiseta", "en")).rejects.toThrow(/fetch failed/);
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
        names: { es: "Camiseta", en: "T-shirt", "pt-BR": "Camiseta BR" },
        locales: ["es", "en", "pt-BR"],
        hidden: false,
      },
      {
        slug: "solo-es",
        slugs: { es: "solo-es", en: "solo-es", "pt-BR": "solo-es" },
        names: { es: "Solo es", en: "Solo es", "pt-BR": "Solo es" },
        locales: ["es"],
        hidden: false,
      },
    ]);
  });

  it("conserva la fecha de edición para el lastmod del sitemap", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { nodes: [{ slug: "a", name: "A", modified: "2026-10-01T10:00:00" }] },
    });

    const [url] = await getProductUrls();

    expect(url.modified).toBe("2026-10-01T10:00:00");
  });

  it("con `strict`, un fallo se registra y se relanza (sin él, lista vacía)", async () => {
    fetchGraphQL.mockRejectedValue(new Error("caído"));

    await expect(getProductUrls()).resolves.toEqual([]);
    await expect(getProductUrls({ strict: true })).rejects.toThrow("caído");
  });

  it("marca como oculta solo la visibilidad HIDDEN", async () => {
    fetchGraphQL.mockResolvedValue({
      products: {
        nodes: [
          { slug: "oculta", name: "Oculta", catalogVisibility: "HIDDEN" },
          { slug: "catalogo", name: "Catálogo", catalogVisibility: "CATALOG" },
          { slug: "busqueda", name: "Búsqueda", catalogVisibility: "SEARCH" },
          { slug: "visible", name: "Visible", catalogVisibility: "VISIBLE" },
          { slug: "sin-dato", name: "Sin dato" },
        ],
      },
    });

    const urls = await getProductUrls();

    expect(urls.map((url) => [url.slug, url.hidden])).toEqual([
      ["oculta", true],
      ["catalogo", false],
      ["busqueda", false],
      ["visible", false],
      ["sin-dato", false],
    ]);
  });

  it("con `all`, recorre el cursor y devuelve las 250 fichas de tres páginas", async () => {
    const catalog = Array.from({ length: 250 }, (_, i) => ({
      slug: `producto-${i}`,
      name: `Producto ${i}`,
    }));
    fetchGraphQL.mockImplementation(
      async (_document: unknown, options: { variables: { after?: string } }) => {
        const from = Number(options.variables.after ?? 0);
        const nodes = catalog.slice(from, from + 100);
        const next = from + 100 < catalog.length ? String(from + 100) : null;
        return {
          products: {
            pageInfo: { hasNextPage: next !== null, endCursor: next },
            nodes,
          },
        };
      },
    );

    const urls = await getProductUrls({ all: true });

    expect(fetchGraphQL).toHaveBeenCalledTimes(3);
    expect(urls).toHaveLength(250);
    expect(urls[0]!.slug).toBe("producto-0");
    expect(urls[249]!.slug).toBe("producto-249");
    expect(logger.warn).not.toHaveBeenCalled();
  });

  it("al llegar al tope de tandas avisa de que quedan fichas sin anunciar", async () => {
    fetchGraphQL.mockResolvedValue({
      products: {
        pageInfo: { hasNextPage: true, endCursor: "100" },
        nodes: [{ slug: "una", name: "Una" }],
      },
    });

    const urls = await getProductUrls({ all: true });

    expect(fetchGraphQL).toHaveBeenCalledTimes(20);
    expect(urls).toHaveLength(20);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "catalog.product_urls_truncated",
        maxPages: 20,
      }),
      expect.any(String),
    );
  });

  it("sin la opción hace UNA sola petición de 100 (presupuesto de prerender)", async () => {
    fetchGraphQL.mockResolvedValue({
      products: {
        pageInfo: { hasNextPage: true, endCursor: "100" },
        nodes: [{ slug: "una", name: "Una" }],
      },
    });

    const urls = await getProductUrls();

    expect(fetchGraphQL).toHaveBeenCalledTimes(1);
    expect(urls).toHaveLength(1);
    const variables = (fetchGraphQL.mock.calls[0]![1] as { variables: object })
      .variables;
    expect(variables).toMatchObject({ first: 100 });
    expect(variables).not.toHaveProperty("after");
  });

  it("una respuesta sin `products` es una lista vacía, no una excepción", async () => {
    fetchGraphQL.mockResolvedValue({});

    expect(await getProductUrls({ all: true })).toEqual([]);
  });

  it("sin información de página se detiene tras la primera tanda", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { nodes: [{ slug: "una", name: "Una" }] },
    });

    expect(await getProductUrls({ all: true })).toHaveLength(1);
    expect(fetchGraphQL).toHaveBeenCalledTimes(1);
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

describe("getCategoryStaticParams", () => {
  it("una entrada por categoría e idioma en que existe, con su tramo de URL", async () => {
    fetchGraphQL.mockResolvedValue({
      productCategories: {
        nodes: [
          {
            id: "c1",
            databaseId: 5,
            name: "Amor propio",
            slug: "amor-propio",
            description: null,
            tr_en: { name: "Self love", slug: "self-love" },
          },
          {
            id: "c2",
            databaseId: 6,
            name: "Solo es",
            slug: "solo-es",
            description: null,
          },
        ],
      },
    });

    expect(await getCategoryStaticParams()).toEqual([
      { locale: "es", slug: "amor-propio" },
      { locale: "en", slug: "self-love" },
      { locale: "es", slug: "solo-es" },
    ]);
  });

  it("un fallo del catálogo da lista vacía (la ruta sigue siendo ISR bajo demanda)", async () => {
    fetchGraphQL.mockRejectedValue(new Error("down"));

    expect(await getCategoryStaticParams()).toEqual([]);
  });
});
