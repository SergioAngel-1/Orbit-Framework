import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...a: unknown[]) => fetchGraphQL(...a),
}));
// `cache` de React memoiza por petición: aquí se neutraliza para probar cada llamada.
vi.mock("react", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react")>()),
  cache: <T>(fn: T) => fn,
}));

import { getBlogCategories, getBlogCategory } from "./categories";

beforeEach(() => {
  fetchGraphQL.mockReset();
});

describe("getBlogCategories", () => {
  it("deja fuera los cajones y las vacías", async () => {
    fetchGraphQL.mockResolvedValue({
      categories: {
        nodes: [
          { name: "Guías", slug: "guias", count: 5 },
          { name: "Uncategorized", slug: "uncategorized", count: 40 },
          { name: "Vacía", slug: "vacia", count: 0 },
          { name: "Sin cuenta", slug: "sin-cuenta", count: null },
        ],
      },
    });

    const categories = await getBlogCategories();

    expect(categories.map((c) => c.slug)).toEqual(["guias"]);
  });

  it("un CMS vacío da lista vacía; un fallo de red se propaga", async () => {
    fetchGraphQL.mockResolvedValueOnce({});
    expect(await getBlogCategories()).toEqual([]);

    fetchGraphQL.mockRejectedValueOnce(new Error("down"));
    await expect(getBlogCategories()).rejects.toThrow("down");
  });
});

describe("getBlogCategory", () => {
  it("normaliza count y descripción", async () => {
    fetchGraphQL.mockResolvedValue({
      category: { name: "Guías", slug: "guias", description: " Todo. ", count: 2 },
    });
    expect(await getBlogCategory("guias")).toEqual({
      name: "Guías",
      slug: "guias",
      description: "Todo.",
      count: 2,
      locales: ["es"],
    });
  });

  it("pide las traducciones de todos los idiomas y devuelve la del idioma de la página", async () => {
    fetchGraphQL.mockResolvedValue({
      category: {
        name: "Guías",
        slug: "guias",
        count: 2,
        tr_en: { name: "Guides", description: "Everything." },
      },
    });

    const category = await getBlogCategory("guias", "en");

    expect(fetchGraphQL.mock.calls.at(-1)![0]).toContain('translation(locale: "en")');
    expect(category).toMatchObject({
      name: "Guides",
      description: "Everything.",
      locales: ["es", "en"],
    });
  });

  it("inexistente es null", async () => {
    fetchGraphQL.mockResolvedValue({ category: null });
    expect(await getBlogCategory("nada")).toBeNull();
  });
});
