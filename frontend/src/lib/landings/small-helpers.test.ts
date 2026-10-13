import { describe, expect, it } from "vitest";
import { findConsumedSection } from "./consumed-section";
import { faqAsideSection } from "./faq-aside";
import { asList } from "./i18n-list";
import { groupLearnProducts } from "./learn-index";
import type { LandingContentSection } from "./types";

const section = (id: LandingContentSection["id"]): LandingContentSection => ({
  id,
  layout: "image-right",
  kicker: null,
  title: id,
  body: null,
  image: null,
  imageAlt: null,
  image2: null,
  image2Alt: null,
});

describe("findConsumedSection", () => {
  it("busca por SLOT, no por posición", () => {
    // El editor desactivó s1: s3 ya no está en la posición 2.
    const sections = [section("s2"), section("s3")];

    expect(findConsumedSection(sections, "s3")?.id).toBe("s3");
    expect(findConsumedSection(sections, "s1")).toBeNull();
  });
});

describe("faqAsideSection", () => {
  it("solo admite un slot de contenido", () => {
    expect(faqAsideSection({ faqAside: "s3" })).toBe("s3");
    expect(faqAsideSection({ faqAside: "quote" })).toBeNull();
    expect(faqAsideSection({ faqAside: null })).toBeNull();
  });
});

describe("asList", () => {
  it("devuelve la lista o vacío (next-intl devuelve la clave si falta)", () => {
    expect(asList<string>(["a"])).toEqual(["a"]);
    expect(asList<string>("clave.que.falta")).toEqual([]);
    expect(asList<string>(undefined)).toEqual([]);
  });
});

describe("groupLearnProducts", () => {
  const items = [
    { name: "Zeta", cat: "otros" },
    { name: "Alfa", cat: "cremas" },
    { name: "Beta", cat: "cremas" },
    { name: "Gamma", cat: "formulas" },
  ];
  const resolvers = {
    category: (item: (typeof items)[number]) => item.cat,
    name: (slug: string) => ({ cremas: "Cremas", formulas: "Fórmulas" })[slug] ?? "",
    title: (item: (typeof items)[number]) => item.name,
  };

  it("agrupa por categoría, ordena dentro del grupo y el resto va detrás por nombre", () => {
    const groups = groupLearnProducts(items, resolvers);

    expect(groups.map((group) => group.name)).toEqual(["Cremas", "Fórmulas", "otros"]);
    expect(groups[0]!.items.map((item) => item.name)).toEqual(["Alfa", "Beta"]);
  });

  it("el orden editorial de la instancia pone su cabeza primero y no pierde el resto", () => {
    const groups = groupLearnProducts(items, resolvers, ["formulas"]);

    expect(groups.map((group) => group.slug)).toEqual(["formulas", "cremas", "otros"]);
  });

  it("sin productos no hay grupos", () => {
    expect(groupLearnProducts([], resolvers)).toEqual([]);
  });
});
