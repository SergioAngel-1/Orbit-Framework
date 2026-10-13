import { describe, it, expect } from "vitest";
import {
  DEFAULT_TITLE_TEMPLATE,
  TITLE_MAX_CHARS,
  brandedTitle,
  fitTitle,
  resolveTitleTemplate,
  siteTitle,
} from "./title";

// Google corta el `<title>` hacia los 60 caracteres. La plantilla del sitio
// (`%s · %site%`) añade ` · Mi Tienda` a TODO título, así que uno de 60 sale de
// 72: lo que Google enseña es la plantilla, no lo que se escribió.

describe("resolveTitleTemplate", () => {
  it("sustituye %site% por el nombre de la marca y deja %s para Next", () => {
    expect(resolveTitleTemplate("%s · %site%", "Mi Tienda")).toBe("%s · Mi Tienda");
  });

  it("sin plantilla configurada usa la de siempre", () => {
    expect(resolveTitleTemplate("", "Mi Tienda")).toBe("%s · Mi Tienda");
    expect(resolveTitleTemplate(undefined, "Mi Tienda")).toBe("%s · Mi Tienda");
    expect(DEFAULT_TITLE_TEMPLATE).toBe("%s · %site%");
  });

  it("sustituye TODAS las apariciones de %site%", () => {
    expect(resolveTitleTemplate("%site% | %s | %site%", "Mi")).toBe("Mi | %s | Mi");
  });
});

describe("fitTitle", () => {
  const template = "%s · Mi Tienda"; // sufijo de 12 caracteres
  const suffix = 12;

  it("un título al que le cabe el sufijo lo conserva: Next lo añade", () => {
    expect(fitTitle("Camisetas básicas", template)).toBe("Camisetas básicas");
  });

  it("en el límite exacto (título + sufijo = 60) todavía cabe", () => {
    const title = "x".repeat(TITLE_MAX_CHARS - suffix);
    expect(fitTitle(title, template)).toBe(title);
  });

  it("con un carácter de más, va SIN sufijo y como título absoluto", () => {
    const title = "x".repeat(TITLE_MAX_CHARS - suffix + 1);
    expect(fitTitle(title, template)).toEqual({ absolute: title });
  });

  it("nunca recorta el texto del título: lo decide quien lo escribe", () => {
    const long = "palabra ".repeat(20).trim();
    expect(fitTitle(long, template)).toEqual({ absolute: long });
  });

  it("una plantilla sin %s no se toca (no hay sufijo que medir)", () => {
    expect(fitTitle("Un título", "Mi Tienda")).toBe("Un título");
  });

  it("respeta otro límite", () => {
    expect(fitTitle("x".repeat(30), template, 40)).toEqual({
      absolute: "x".repeat(30),
    });
    expect(fitTitle("x".repeat(25), template, 40)).toBe("x".repeat(25));
  });
});

describe("brandedTitle", () => {
  const template = "%s · Mi Tienda";
  const aliases = ["Tienda Antigua", "Mi Tienda Online", "Mi"];
  const run = (title: string) => brandedTitle(title, template, "Mi Tienda", aliases);

  it("retira del final la marca que el título ya trae escrita", () => {
    expect(run("Camiseta Lino – Verano | Tienda Antigua")).toBe(
      "Camiseta Lino – Verano",
    );
    expect(run("Gorra | Ligera y cómoda | Mi")).toBe("Gorra | Ligera y cómoda");
    expect(run("Abrigos para el frío - Mi Tienda Online")).toBe("Abrigos para el frío");
  });

  it("retira dos marcas encadenadas", () => {
    expect(run("Botas de Monte | Mi Tienda – Tienda Antigua")).toBe("Botas de Monte");
  });

  it("un título que es solo la marca va sin plantilla", () => {
    expect(run("Mi Tienda")).toEqual({ absolute: "Mi Tienda" });
    expect(run("Tienda Antigua")).toEqual({ absolute: "Tienda Antigua" });
  });

  it("no recorta una marca que forma parte del nombre", () => {
    expect(run("Fórmulas Mi")).toBe("Fórmulas Mi");
    expect(run("Mezclas Mi para dormir")).toBe("Mezclas Mi para dormir");
  });

  it("sigue quitando la plantilla si el título limpio no cabe", () => {
    const largo = "Un título de entrada que por sí solo ya ocupa cincuenta y tantos";
    expect(run(`${largo} | Tienda Antigua`)).toEqual({ absolute: largo });
  });

  it("sin alias se comporta como fitTitle", () => {
    expect(brandedTitle("Calcetines", template, "Mi Tienda")).toBe("Calcetines");
  });
});

describe("siteTitle", () => {
  const config = {
    brand: { name: "Mi Tienda" },
    seo: {
      title_template: "%s | %site%",
      alternate_names: "Tienda Antigua\nMi Tienda SL",
    },
  };

  it("aplica la plantilla y los nombres alternativos del panel", () => {
    expect(siteTitle(config, "Camisetas | Tienda Antigua")).toBe("Camisetas");
  });

  it("sin plantilla ni alias usa los de siempre", () => {
    expect(siteTitle({ brand: { name: "Mi Tienda" }, seo: {} }, "Camisetas")).toBe(
      "Camisetas",
    );
  });
});
