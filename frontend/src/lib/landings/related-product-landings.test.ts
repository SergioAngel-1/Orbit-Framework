import { beforeEach, describe, expect, it, vi } from "vitest";

const getSiteConfig = vi.hoisted(() => vi.fn());
vi.mock("@/lib/config", () => ({ getSiteConfig }));
const getPublishedProductLandingSlugs = vi.hoisted(() => vi.fn());
vi.mock("@/lib/landings/product-landing", () => ({ getPublishedProductLandingSlugs }));
const fetchLocalizedGraphQL = vi.hoisted(() => vi.fn());
vi.mock("@/lib/i18n/fetch-localized", () => ({ fetchLocalizedGraphQL }));

import {
  getRelatedProductLandings,
  pickRelatedSlugs,
  shuffleForSlug,
} from "./related-product-landings";

// Sin categorías ni etiquetas que emparenten unas landings con otras, «Sigue
// aprendiendo» reparte las tarjetas con un anillo round-robin.
const SLUGS = [
  "a1",
  "b2",
  "c3",
  "d4",
  "e5",
  "f6",
  "g7",
  "h8",
  "i9",
  "j10",
  "k11",
  "l12",
];
const LIMIT = 3;
const cardsOf = (slug: string) => pickRelatedSlugs(SLUGS, slug, LIMIT);

describe("shuffleForSlug", () => {
  it("es determinista: la misma semilla da siempre el mismo orden", () => {
    expect(shuffleForSlug(SLUGS, "x")).toEqual(shuffleForSlug(SLUGS, "x"));
  });

  it("no muta la entrada y conserva todos los elementos", () => {
    const original = [...SLUGS];
    const shuffled = shuffleForSlug(SLUGS, "x");
    expect(SLUGS).toEqual(original);
    expect([...shuffled].sort()).toEqual([...SLUGS].sort());
  });

  it("da un orden distinto para semillas distintas", () => {
    expect(shuffleForSlug(SLUGS, "a")).not.toEqual(shuffleForSlug(SLUGS, "b"));
  });

  it("soporta listas vacías y de un elemento", () => {
    expect(shuffleForSlug([], "x")).toEqual([]);
    expect(shuffleForSlug(["solo"], "x")).toEqual(["solo"]);
  });
});

describe("pickRelatedSlugs", () => {
  it("es determinista", () => {
    expect(cardsOf("d4")).toEqual(cardsOf("d4"));
  });

  it("ninguna landing se recomienda a sí misma ni repite tarjetas", () => {
    for (const slug of SLUGS) {
      const cards = cardsOf(slug);
      expect(cards).not.toContain(slug);
      expect(new Set(cards).size).toBe(cards.length);
    }
  });

  it("no hay dos landings con el mismo trío", () => {
    const seen = SLUGS.map((slug) => cardsOf(slug).join("|"));
    expect(new Set(seen).size).toBe(SLUGS.length);
  });

  it("reparte de forma uniforme: cada landing sale exactamente `limit` veces", () => {
    const count = new Map<string, number>();
    for (const slug of SLUGS) {
      for (const card of cardsOf(slug)) count.set(card, (count.get(card) ?? 0) + 1);
    }
    expect(count.size).toBe(SLUGS.length);
    for (const times of count.values()) expect(times).toBe(LIMIT);
  });

  it("devuelve lo que hay cuando hay menos landings que el límite", () => {
    expect(pickRelatedSlugs(["a", "b"], "a", 3)).toEqual(["b"]);
    expect(pickRelatedSlugs(["a"], "a", 3)).toEqual([]);
    expect(pickRelatedSlugs([], "a", 3)).toEqual([]);
  });

  it("un slug que no está en el anillo igualmente recibe tarjetas", () => {
    const cards = pickRelatedSlugs(SLUGS, "recien-publicada", LIMIT);
    expect(cards).toHaveLength(LIMIT);
    expect(new Set(cards).size).toBe(LIMIT);
  });
});

describe("getRelatedProductLandings", () => {
  beforeEach(() => {
    getSiteConfig
      .mockReset()
      .mockResolvedValue({ modules: { "product-landing": true } });
    getPublishedProductLandingSlugs.mockReset().mockResolvedValue(["a", "b", "c", "d"]);
    fetchLocalizedGraphQL.mockReset();
  });

  it("con el módulo apagado no consulta nada", async () => {
    getSiteConfig.mockResolvedValue({ modules: {} });

    expect(await getRelatedProductLandings("a", "es")).toEqual([]);
    expect(getPublishedProductLandingSlugs).not.toHaveBeenCalled();
    expect(fetchLocalizedGraphQL).not.toHaveBeenCalled();
  });

  it("sin otras landings publicadas no consulta nada", async () => {
    getPublishedProductLandingSlugs.mockResolvedValue(["a"]);

    expect(await getRelatedProductLandings("a", "es")).toEqual([]);
    expect(fetchLocalizedGraphQL).not.toHaveBeenCalled();
  });

  it("compone las tarjetas en el idioma pedido, con el resumen de respaldo", async () => {
    const chosen = pickRelatedSlugs(["a", "b", "c", "d"], "a", 3);
    fetchLocalizedGraphQL.mockResolvedValue({
      products: {
        nodes: chosen.map((slug, index) => ({
          slug,
          name: `Producto ${slug}`,
          name_en: `Product ${slug}`,
          slug_en: `${slug}-en`,
          image: index === 0 ? { sourceUrl: "https://cdn/x.jpg" } : null,
          shortDescription: index === 0 ? "<p>Resumen <b>corto</b></p>" : null,
          landing: { heroSubtitle: "Subtítulo", hero_subtitle_en: "Subtitle" },
        })),
      },
    });

    const cards = await getRelatedProductLandings("a", "en");

    expect(fetchLocalizedGraphQL).toHaveBeenCalledWith(
      expect.stringContaining("RelatedLandingProducts"),
      "en",
      expect.objectContaining({ variables: { slugs: chosen } }),
    );
    expect(cards.map((c) => c.name)).toEqual(chosen.map((s) => `Product ${s}`));
    expect(cards[0]).toMatchObject({
      href: `/products/${chosen[0]}-en/learn`,
      image: "https://cdn/x.jpg",
      excerpt: "Resumen corto",
    });
    expect(cards[1]).toMatchObject({ image: null, excerpt: "Subtitle" });
  });

  it("un producto que ya no devuelve WordPress no aporta tarjeta ni se sustituye", async () => {
    const chosen = pickRelatedSlugs(["a", "b", "c", "d"], "a", 3);
    fetchLocalizedGraphQL.mockResolvedValue({
      products: {
        nodes: [chosen[0], chosen[2]].map((slug) => ({
          slug,
          name: slug,
          image: null,
          landing: null,
        })),
      },
    });

    const cards = await getRelatedProductLandings("a", "es");

    expect(cards.map((c) => c.href)).toEqual([
      `/products/${chosen[0]}/learn`,
      `/products/${chosen[2]}/learn`,
    ]);
  });
});
