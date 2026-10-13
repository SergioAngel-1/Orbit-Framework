import { describe, expect, it, vi } from "vitest";

vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

import { localizedUrlSlug, urlSlugsByLocale } from "./url-slug";

const post = { slug: "mi-entrada", slug_en: "my-post", slug_pt_br: "" };

describe("localizedUrlSlug", () => {
  it("el idioma por defecto usa el slug nativo", () => {
    expect(localizedUrlSlug(post, "es")).toBe("mi-entrada");
  });

  it("un secundario con slug traducido usa ese", () => {
    expect(localizedUrlSlug(post, "en")).toBe("my-post");
  });

  it("sin slug traducido (o vacío) usa el nativo", () => {
    expect(localizedUrlSlug(post, "pt-BR")).toBe("mi-entrada");
    expect(localizedUrlSlug(post, "fr")).toBe("mi-entrada");
  });
});

describe("urlSlugsByLocale", () => {
  it("da el tramo de URL de cada idioma del sitio", () => {
    expect(urlSlugsByLocale(post)).toEqual({
      es: "mi-entrada",
      en: "my-post",
      "pt-BR": "mi-entrada",
    });
  });
});
