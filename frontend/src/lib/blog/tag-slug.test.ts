import { describe, it, expect } from "vitest";
import { slugifyTagName, tagUrlSlug } from "./tag-slug";

describe("slugifyTagName", () => {
  it("minúsculas, sin acentos y con guiones", () => {
    expect(slugifyTagName("Summer travel tips")).toBe("summer-travel-tips");
    expect(slugifyTagName("  Mother's Day ")).toBe("mother-s-day");
    expect(slugifyTagName("Ánimo")).toBe("animo");
  });
});

describe("tagUrlSlug", () => {
  const tag = {
    slug: "bienestar-animal",
    name: "Bienestar animal",
    name_en: "Animal welfare",
  };

  it("en el idioma por defecto conserva el slug de WordPress", () => {
    expect(tagUrlSlug(tag, "es")).toBe("bienestar-animal");
  });

  it("en otro idioma usa el nombre traducido", () => {
    expect(tagUrlSlug(tag, "en")).toBe("animal-welfare");
  });

  it("sin traducción, o con un nombre que no deja slug, cae al de WordPress", () => {
    expect(tagUrlSlug({ slug: "viajes", name: "Viajes" }, "en")).toBe("viajes");
    expect(tagUrlSlug({ slug: "x", name: "X", name_en: "—" }, "en")).toBe("x");
  });
});
