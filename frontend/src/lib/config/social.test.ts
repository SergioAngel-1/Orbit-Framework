import { describe, expect, it } from "vitest";
import {
  collectSocialSameAs,
  dialableNumber,
  getConfiguredSocialLinks,
  resolveSocialUrl,
  resolveSocialUrls,
  splitSocialValues,
  telHref,
  whatsappHref,
} from "./social";
import { CONFIG_DEFAULTS } from "./defaults";

describe("splitSocialValues", () => {
  it("parte por | y limpia vacíos", () => {
    expect(splitSocialValues("marca | Otra_Cuenta | ")).toEqual([
      "marca",
      "Otra_Cuenta",
    ]);
    expect(splitSocialValues("")).toEqual([]);
  });
});

describe("resolveSocialUrls", () => {
  it("arma URLs desde handles", () => {
    expect(resolveSocialUrls("marca | otra", "instagram")).toEqual([
      { user: "marca", href: "https://instagram.com/marca" },
      { user: "otra", href: "https://instagram.com/otra" },
    ]);
  });

  it("si pegan una URL, toma el último segmento útil", () => {
    expect(resolveSocialUrls("https://instagram.com/marca/", "instagram")).toEqual([
      { user: "marca", href: "https://instagram.com/marca" },
    ]);
  });

  it("valida los Q de Wikidata", () => {
    expect(resolveSocialUrls("Q123", "wikidata")).toEqual([
      { user: "Q123", href: "https://www.wikidata.org/wiki/Q123" },
    ]);
    expect(resolveSocialUrls("no-es-q", "wikidata")).toEqual([]);
  });
});

describe("whatsappHref", () => {
  it("número internacional → wa.me (solo el primero si hay varios)", () => {
    expect(whatsappHref("34 600 123 456")).toBe("https://wa.me/34600123456");
    expect(whatsappHref("34 600 123 456 | 34 600 000 000")).toBe(
      "https://wa.me/34600123456",
    );
  });

  it("acepta mensaje precargado y vacío → null", () => {
    expect(whatsappHref("34 600 123 456", "Hola")).toBe(
      "https://wa.me/34600123456?text=Hola",
    );
    expect(whatsappHref("")).toBeNull();
  });
});

describe("dialableNumber / telHref", () => {
  it("deja los dígitos (y el + inicial)", () => {
    expect(dialableNumber("+34 600 123 456")).toBe("+34600123456");
    expect(telHref("(601) 530 3533")).toBe("tel:6015303533");
  });

  it("sin dígitos → null", () => {
    expect(dialableNumber("")).toBeNull();
    expect(telHref("")).toBeNull();
  });
});

describe("getConfiguredSocialLinks", () => {
  it("lista las redes configuradas con etiquetas por cuenta", () => {
    const social = {
      ...CONFIG_DEFAULTS.social,
      twitter: "",
      instagram: "marca | otra",
      facebook: "",
    };
    const links = getConfiguredSocialLinks(social, (p) => p);

    expect(links).toEqual([
      {
        platform: "instagram",
        href: "https://instagram.com/marca",
        label: "instagram · marca",
      },
      {
        platform: "instagram",
        href: "https://instagram.com/otra",
        label: "instagram · otra",
      },
    ]);
  });
});

describe("collectSocialSameAs", () => {
  it("junta todas las cuentas incluido whatsapp/wikipedia/wikidata", () => {
    const social = {
      ...CONFIG_DEFAULTS.social,
      instagram: "marca",
      whatsapp: "34 600 123 456",
      wikipedia: "Mi_Marca",
      wikidata: "Q123",
    };
    const sameAs = collectSocialSameAs(social);

    expect(sameAs).toContain("https://instagram.com/marca");
    expect(sameAs).toContain("https://wa.me/34600123456");
    expect(sameAs).toContain("https://es.wikipedia.org/wiki/Mi_Marca");
    expect(sameAs).toContain("https://www.wikidata.org/wiki/Q123");
  });
});

describe("resolveSocialUrl", () => {
  it("devuelve la primera URL o null", () => {
    expect(resolveSocialUrl("marca", "instagram")).toBe("https://instagram.com/marca");
    expect(resolveSocialUrl("", "instagram")).toBeNull();
  });
});
