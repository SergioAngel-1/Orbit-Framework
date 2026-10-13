import { describe, expect, it } from "vitest";
import {
  buildEditorialRegistry,
  cleanPath,
  editorialLandingHref,
  editorialLandingPaths,
  findEditorialByAnyPath,
  findEditorialByPath,
  parseEditorialRegistry,
  staticEntries,
} from "./registry";
import { CORE_ROOT_SEGMENTS, reservedRootSegments } from "./reserved-segments";

// El fixture de `routing` en los tests es es/en (ver `src/i18n/routing.ts`).

const STATICS = staticEntries({
  perfil: {
    label: "Perfil",
    path: "mi-perfil",
    paths: { en: "my-profile" },
    base: "root",
    jsonLd: "person",
    uniqueSections: [
      { key: "uno", after: "s1" },
      { key: "dos", after: "s1" },
      { key: "mal", after: "no-es-un-slot" },
    ],
    consumesBase: ["lead", "faq", "no-existe"],
    faqAside: "s2",
    hints: { hero: "banner" },
  },
  guia: { label: "Guía", base: "area" },
});

const build = (raw: string, editorial?: Parameters<typeof buildEditorialRegistry>[1]) =>
  buildEditorialRegistry(raw, editorial, STATICS, reservedRootSegments([]));

describe("staticEntries", () => {
  it("normaliza lo que declara la instancia", () => {
    const perfil = STATICS.perfil!;

    expect(perfil).toMatchObject({
      slug: "perfil",
      path: "mi-perfil",
      paths: { es: "mi-perfil", en: "my-profile" },
      base: "root",
      jsonLd: "person",
      dynamic: false,
      faqAside: "s2",
      hints: { hero: "banner" },
    });
    expect(STATICS.guia).toMatchObject({
      path: "guia",
      base: "area",
      jsonLd: "article",
    });
  });

  it("descarta las anclas y las absorciones que no son un slot base", () => {
    expect(STATICS.perfil!.uniqueSections).toEqual([
      { key: "uno", after: "s1" },
      { key: "dos", after: "s1" },
    ]);
    expect(STATICS.perfil!.consumesBase).toEqual(["lead", "faq"]);
  });
});

describe("parseEditorialRegistry", () => {
  it("lee el texto canónico y descarta lo inválido y lo repetido", () => {
    const rows = parseEditorialRegistry(
      "uno | Uno | person\n\n  DOS | Dos\n | Sin id\nuno | Repetido | article\ncon-tipo | Con tipo | robot",
      [],
    );

    expect(rows).toEqual([
      { slug: "uno", label: "Uno", jsonLd: "person" },
      { slug: "dos", label: "Dos", jsonLd: "article" },
      { slug: "con-tipo", label: "Con tipo", jsonLd: "article" },
    ]);
  });

  it("un identificador que choca con una estática se ignora", () => {
    const rows = parseEditorialRegistry("perfil | Intrusa | article\notra | Otra", [
      "perfil",
    ]);

    expect(rows.map((row) => row.slug)).toEqual(["otra"]);
  });

  it("vacío o sin texto da lista vacía", () => {
    expect(parseEditorialRegistry("", [])).toEqual([]);
    expect(parseEditorialRegistry(null, [])).toEqual([]);
  });
});

describe("buildEditorialRegistry", () => {
  it("las estáticas van primero y las del panel nacen en el área con el molde estándar", () => {
    const registry = build("nueva | Nueva | article");

    expect(Object.keys(registry)).toEqual(["perfil", "guia", "nueva"]);
    expect(registry.nueva).toMatchObject({
      base: "area",
      path: "nueva",
      uniqueSections: [],
      consumesBase: [],
      dynamic: true,
    });
  });

  it("aplica la URL configurada en el CMS, con un tramo por idioma", () => {
    const registry = build("nueva | Nueva | article", {
      nueva: { url: { base: "root", path: "mi-pagina", path_en: "my-page" } },
    });

    expect(registry.nueva).toMatchObject({
      base: "root",
      path: "mi-pagina",
      paths: { es: "mi-pagina", en: "my-page" },
    });
    expect(editorialLandingHref(registry.nueva!, "es")).toBe("/mi-pagina");
    expect(editorialLandingHref(registry.nueva!, "en")).toBe("/my-page");
  });

  it("sin tramo en un idioma, usa el del idioma por defecto configurado", () => {
    const registry = build("nueva | Nueva | article", {
      nueva: { url: { base: "area", path: "algo" } },
    });

    expect(registry.nueva!.paths).toEqual({ es: "algo", en: "algo" });
  });

  it("sin config conserva el tramo por idioma de la entrada de código", () => {
    const registry = build("");

    expect(registry.perfil!.paths).toEqual({ es: "mi-perfil", en: "my-profile" });
    expect(editorialLandingPaths(registry.perfil!)).toEqual({
      es: "/mi-perfil",
      en: "/my-profile",
    });
    expect(editorialLandingPaths(registry.guia!)).toEqual({
      es: "/learn/guia",
      en: "/learn/guia",
    });
  });

  it("una landing de raíz sobre un tramo reservado vuelve al área", () => {
    for (const reserved of ["products", "blog", "learn", "en", "api"]) {
      const registry = build("nueva | Nueva | article", {
        nueva: { url: { base: "root", path: reserved } },
      });
      expect(registry.nueva!.base, reserved).toBe("area");
    }
  });

  it("si solo el tramo de un idioma está reservado, la landing se queda en la raíz", () => {
    const registry = build("nueva | Nueva | article", {
      nueva: { url: { base: "root", path: "mi-pagina", path_en: "products" } },
    });

    expect(registry.nueva).toMatchObject({
      base: "root",
      paths: { es: "mi-pagina", en: "mi-pagina" },
    });
  });

  it("la segunda landing que reclama una URL ocupada se reubica a la suya por defecto", () => {
    const registry = build("a | A | article\nb | B | article", {
      a: { url: { base: "area", path: "compartida" } },
      b: { url: { base: "area", path: "compartida" } },
    });

    expect(registry.a!.path).toBe("compartida");
    expect(registry.b).toMatchObject({
      base: "area",
      path: "b",
      paths: { es: "b", en: "b" },
    });
  });

  it("una landing con producto asociado no entra en el registro de URL libre", () => {
    const withProduct = build("con-producto | Con producto | article", {
      "con-producto": { product: "camiseta" },
    });
    const withoutEditorial = build("con-producto | Con producto | article");

    expect(withProduct["con-producto"]).toBeUndefined();
    // La forma de 1 argumento SÍ la ve (es la que usa la ruta de la ficha).
    expect(withoutEditorial["con-producto"]).toBeDefined();
  });
});

describe("landing estática con producto asociado", () => {
  it("tampoco entra en la URL libre, y la forma de 1 argumento la sigue viendo", () => {
    // El panel ofrece «Producto asociado» para CUALQUIER landing, las estáticas incluidas.
    const withProduct = build("", { guia: { product: "camiseta" } });
    const withoutEditorial = build("");

    expect(withProduct.guia).toBeUndefined();
    expect(withoutEditorial.guia).toBeDefined();
  });
});

describe("findEditorialByPath / findEditorialByAnyPath", () => {
  const registry = build("nueva | Nueva | article", {
    nueva: { url: { base: "area", path: "mi-pagina", path_en: "my-page" } },
  });

  it("distingue la ubicación: una landing del área no responde en la raíz", () => {
    expect(findEditorialByPath(registry, "area", "mi-pagina", "es")?.slug).toBe(
      "nueva",
    );
    expect(findEditorialByPath(registry, "root", "mi-pagina", "es")).toBeNull();
  });

  it("solo casa el tramo de ese idioma", () => {
    expect(findEditorialByPath(registry, "area", "my-page", "es")).toBeNull();
    expect(findEditorialByPath(registry, "area", "my-page", "en")?.slug).toBe("nueva");
  });

  it("acepta el tramo de otro idioma y avisa de que no es el canónico", () => {
    const exact = findEditorialByAnyPath(registry, "area", "my-page", "en");
    const alias = findEditorialByAnyPath(registry, "area", "mi-pagina", "en");

    expect(exact).toMatchObject({ canonical: true });
    expect(alias).toMatchObject({ canonical: false });
    expect(alias?.entry.slug).toBe("nueva");
    expect(findEditorialByAnyPath(registry, "area", "inventada", "en")).toBeNull();
  });
});

describe("reservedRootSegments", () => {
  it("incluye las rutas del starter, los idiomas, api y lo de la instancia", () => {
    const reserved = reservedRootSegments(["mi-ruta"]);

    for (const segment of CORE_ROOT_SEGMENTS) expect(reserved.has(segment)).toBe(true);
    for (const segment of ["es", "en", "api", "mi-ruta", "learn"]) {
      expect(reserved.has(segment), segment).toBe(true);
    }
  });
});

describe("cleanPath", () => {
  it("deja solo [a-z0-9-] sin barras ni guiones en los extremos", () => {
    expect(cleanPath(" /Mi Página-1/ ")).toBe("mipgina-1");
    expect(cleanPath(null)).toBe("");
  });
});
