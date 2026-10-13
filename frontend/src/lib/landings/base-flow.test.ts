import { describe, expect, it } from "vitest";
import { buildBaseFlow, faqAsideFor } from "./base-flow";
import type { EditorialLanding, LandingContentSection } from "./types";

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

const source = (over: Partial<EditorialLanding> = {}) => ({
  lead: null,
  sections: [] as LandingContentSection[],
  quote: null,
  faq: null,
  products: null,
  closing: null,
  ...over,
});

const ids = (flow: { id: string }[]) => flow.map((s) => s.id);
const faq = { title: null, items: [{ question: "¿?", answer: "<p>x</p>" }] };
const products = (after: "lead" | "s1" | "s2" | "quote" | "faq") => ({
  title: null,
  items: [],
  after,
  categorySlug: null,
});

describe("buildBaseFlow", () => {
  it("solo incluye los slots con contenido, en el orden del molde", () => {
    const flow = buildBaseFlow(
      source({
        lead: "<p>x</p>",
        sections: [section("s2"), section("s1")],
        quote: {
          text: "c",
          author: null,
          photo: null,
          backgroundImage: null,
          logo: null,
          logoAlt: null,
        },
        faq,
        closing: {
          title: "Fin",
          text: null,
          ctaLabel: null,
          ctaHref: null,
          cta2Label: null,
          cta2Href: null,
          backgroundImage: null,
        },
      }),
      { faqAside: null },
    );

    expect(ids(flow)).toEqual(["hero", "lead", "s2", "s1", "quote", "faq", "closing"]);
  });

  it("una landing vacía solo tiene hero", () => {
    expect(ids(buildBaseFlow(source(), { faqAside: null }))).toEqual(["hero"]);
  });

  it("la banda de productos va tras el slot que elige el panel", () => {
    const flow = buildBaseFlow(
      source({
        sections: [section("s1"), section("s2")],
        faq,
        products: products("s1"),
      }),
      { faqAside: null },
    );

    expect(ids(flow)).toEqual(["hero", "s1", "products", "s2", "faq"]);
  });

  it("un ancla que no está en el flujo deja los productos justo antes del cierre", () => {
    const closing = {
      title: "Fin",
      text: null,
      ctaLabel: null,
      ctaHref: null,
      cta2Label: null,
      cta2Href: null,
      backgroundImage: null,
    };

    expect(
      ids(
        buildBaseFlow(source({ products: products("quote"), closing }), {
          faqAside: null,
        }),
      ),
    ).toEqual(["hero", "products", "closing"]);
    expect(
      ids(buildBaseFlow(source({ products: products("quote") }), { faqAside: null })),
    ).toEqual(["hero", "products"]);
  });

  it("la FAQ junto a una sección no entra como banda propia, pero vuelve si el slot está apagado", () => {
    const entry = { faqAside: "s2" as const };

    expect(
      ids(buildBaseFlow(source({ sections: [section("s2")], faq }), entry)),
    ).toEqual(["hero", "s2"]);
    expect(
      ids(buildBaseFlow(source({ sections: [section("s1")], faq }), entry)),
    ).toEqual(["hero", "s1", "faq"]);
  });
});

describe("faqAsideFor", () => {
  it("exige FAQ con preguntas y el slot activo", () => {
    const entry = { faqAside: "s3" as const };

    expect(faqAsideFor({ faq, sections: [section("s3")] }, entry)).toBe("s3");
    expect(faqAsideFor({ faq: null, sections: [section("s3")] }, entry)).toBeNull();
    expect(faqAsideFor({ faq, sections: [] }, entry)).toBeNull();
  });
});
