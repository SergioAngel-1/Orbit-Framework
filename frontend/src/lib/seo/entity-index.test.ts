import { describe, expect, it } from "vitest";
import {
  entityIndexMetadata,
  inheritedIndexMetadata,
  isEntityAnnounced,
} from "./entity-index";

const own = {
  canonical: "/products/camiseta",
  languages: { es: "/products/camiseta" },
};

describe("entityIndexMetadata", () => {
  it("sin controles, la canónica y el hreflang propios", () => {
    expect(entityIndexMetadata(own, {})).toEqual({ alternates: own });
  });

  it("noindex: robots noindex,follow y sin alternates", () => {
    expect(entityIndexMetadata(own, { seoNoindex: true })).toEqual({
      robots: { index: false, follow: true },
    });
  });

  it("canónica manual: apunta a ella y sin hreflang", () => {
    expect(
      entityIndexMetadata(own, { seoCanonical: "https://otra.example/principal" }),
    ).toEqual({ alternates: { canonical: "https://otra.example/principal" } });
  });

  it("una canónica que no es URL absoluta se ignora", () => {
    expect(entityIndexMetadata(own, { seoCanonical: "/relativa" })).toEqual({
      alternates: own,
    });
  });
});

describe("isEntityAnnounced", () => {
  it("solo se anuncia lo indexable con canónica propia", () => {
    expect(isEntityAnnounced({})).toBe(true);
    expect(isEntityAnnounced({ seoNoindex: false, seoCanonical: null })).toBe(true);
    expect(isEntityAnnounced({ seoNoindex: true })).toBe(false);
    expect(isEntityAnnounced({ seoCanonical: "https://otra.example/x" })).toBe(false);
  });
});

describe("inheritedIndexMetadata", () => {
  it("con la ficha anunciada, la landing lleva sus propios alternates", () => {
    expect(inheritedIndexMetadata(own, {})).toEqual({ alternates: own });
  });

  it("ficha noindex: la landing también, sin alternates", () => {
    expect(inheritedIndexMetadata(own, { seoNoindex: true })).toEqual({
      robots: { index: false, follow: true },
    });
  });

  it("ficha con canónica manual: la landing no hereda la canónica, va noindex,follow", () => {
    expect(
      inheritedIndexMetadata(own, { seoCanonical: "https://otra.example/camiseta" }),
    ).toEqual({ robots: { index: false, follow: true } });
  });
});
