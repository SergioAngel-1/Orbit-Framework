import { describe, it, expect } from "vitest";
import {
  ogLocale,
  siteOgImage,
  siteOpenGraph,
  siteTwitter,
  siteOgImageUrl,
} from "./opengraph";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

const config = structuredClone(CONFIG_DEFAULTS);
config.brand.name = "Mi Tienda";
config.brand.url = "https://mitienda.example";
config.ecommerce.country = "CO";

describe("siteOpenGraph", () => {
  // El contrato que hace que esto funcione en TODAS las páginas es que aquí NO
  // haya `title` ni `description`: Next los resuelve desde los de la propia
  // página cuando el objeto no los trae, y este objeto lo hereda toda página
  // que no declare `openGraph`. En cuanto alguien los fije, la tienda, el blog
  // y los legales se comparten todos con el titular de la home — sin que falle
  // ningún tipo ni el build. De ahí esta aserción.
  it("no fija título ni descripción: los pone cada página", () => {
    expect(siteOpenGraph(config, "es")).not.toHaveProperty("title");
    expect(siteOpenGraph(config, "es")).not.toHaveProperty("description");
    expect(siteTwitter(config, "es")).not.toHaveProperty("title");
    expect(siteTwitter(config, "es")).not.toHaveProperty("description");
  });

  it("lleva los campos que una página no tiene por qué repetir", () => {
    const og = siteOpenGraph(config, "es");
    expect(og.siteName).toBe("Mi Tienda");
    expect(og.locale).toBe("es_CO");
    expect(og.alternateLocale).toEqual(["en_US"]);
    expect(og.images).toHaveLength(1);
  });

  it("el locale alterno es el OTRO, en cada idioma", () => {
    expect(siteOpenGraph(config, "en").locale).toBe("en_US");
    expect(siteOpenGraph(config, "en").alternateLocale).toEqual(["es_CO"]);
  });

  it("sin tarjetas de instancia, la imagen es la dinámica de marca, con idioma", () => {
    expect(siteOgImageUrl(config, "es")).toBe("/api/og");
    expect(siteOgImageUrl(config, "en")).toBe("/api/og?locale=en");
  });

  it("una imagen propia del panel manda sobre la dinámica", () => {
    const custom = structuredClone(config);
    custom.seo.default_og = "custom";
    custom.seo.og_image = "https://cdn.example/og.png";
    expect(siteOgImageUrl(custom, "en")).toBe("https://cdn.example/og.png");
  });

  it("solo la tarjeta generada declara 1200×630; la del panel va sin medidas", () => {
    expect(siteOgImage(config, "es")).toMatchObject({ width: 1200, height: 630 });
    const custom = structuredClone(config);
    custom.seo.default_og = "custom";
    custom.seo.og_image = "https://cdn.example/logo-512.png";
    const img = siteOgImage(custom, "es");
    expect(img).not.toHaveProperty("width");
    expect(img).not.toHaveProperty("height");
  });

  it("og:type es `website` por defecto y og:url la canónica que se le pase", () => {
    expect(siteOpenGraph(config, "es").type).toBe("website");
    expect(siteOpenGraph(config, "es")).not.toHaveProperty("url");
    expect(siteOpenGraph(config, "es", "/tienda").url).toBe("/tienda");
  });

  it("`default_og: custom` sin imagen cae a la de marca, no a una URL vacía", () => {
    const custom = structuredClone(config);
    custom.seo.default_og = "custom";
    custom.seo.og_image = "";
    expect(siteOgImageUrl(custom, "es")).toBe("/api/og");
  });
});

describe("ogLocale", () => {
  it("el idioma canónico lleva el país de la tienda", () => {
    expect(ogLocale("es", "CO")).toBe("es_CO");
  });

  it("sin país válido, cada idioma cae a su territorio habitual", () => {
    expect(ogLocale("es", "")).toBe("es_ES");
    expect(ogLocale("en", "CO")).toBe("en_US");
  });
});
