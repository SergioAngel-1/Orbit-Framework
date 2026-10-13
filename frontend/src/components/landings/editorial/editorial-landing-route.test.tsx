import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactElement, ReactNode } from "react";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// ============================================================================
//  Ruta de una landing editorial (`/learn/[slug]` y `/[landingPath]`): el módulo
//  apagado o una landing sin publicar es 404; el tramo de otro idioma redirige
//  (308) al canónico; la de la raíz no responde bajo el área; y solo los idiomas
//  con traducción entran en el hreflang y en los params estáticos.
// ============================================================================

vi.mock("next/navigation", () => ({
  notFound: () => {
    throw new Error("NEXT_NOT_FOUND");
  },
}));
vi.mock("next-intl/server", () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
  setRequestLocale: vi.fn(),
}));
const permanentRedirect = vi.hoisted(() =>
  vi.fn((arg: { href: string }) => {
    throw new Error(`NEXT_REDIRECT:${arg.href}`);
  }),
);
vi.mock("@/i18n/navigation", () => ({ Link: () => null, permanentRedirect }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
const getEditorialLanding = vi.hoisted(() => vi.fn());
vi.mock("@/lib/landings/editorial-landing", () => ({ getEditorialLanding }));
vi.mock("@/lib/landings/editorial-href", () => ({
  resolveCmsLink: vi.fn(async () => null),
  getEditorialHrefStrict: vi.fn(async () => null),
}));
vi.mock("@/components/landings/editorial/landing-editorial-view", () => ({
  LandingEditorialView: () => null,
}));

import { getSiteConfig } from "@/lib/config";
import {
  EditorialLandingRoute,
  editorialMetadata,
  editorialStaticParams,
} from "./editorial-landing-route";

type El = ReactElement<{ children?: ReactNode } & Record<string, unknown>>;

function find(node: ReactNode, test: (el: El) => boolean, out: El[] = []): El[] {
  if (!node || typeof node !== "object") return out;
  if (Array.isArray(node)) {
    node.forEach((n) => find(n, test, out));
    return out;
  }
  const el = node as El;
  if (test(el)) out.push(el);
  find(el.props?.children, test, out);
  return out;
}

/** La ruta devuelve el elemento de `EditorialLandingContent` (async): se ejecuta para ver su salida. */
async function rendered(tree: ReactNode): Promise<ReactNode> {
  const element = tree as ReactElement<Record<string, unknown>>;
  const component = element.type as (
    props: Record<string, unknown>,
  ) => Promise<ReactNode>;
  return component(element.props);
}

const jsonLdTypes = (tree: ReactNode) =>
  find(tree, (el) => el.type === "script").map((el) => {
    const html = (el.props.dangerouslySetInnerHTML as { __html: string }).__html;
    return JSON.parse(html)["@type"] as string;
  });

function config(
  landings: boolean,
  editorial: Record<string, unknown>,
  registry: string,
) {
  const base = structuredClone(CONFIG_DEFAULTS);
  base.brand.url = "https://tienda.example";
  base.modules = { ...base.modules, landings };
  base.landings = { registry, editorial: editorial as never };
  vi.mocked(getSiteConfig).mockResolvedValue(base);
}

const REGISTRY = "guia | Guía | article\nperfil | Perfil | person";
const EDITORIAL = {
  guia: {
    published: true,
    url: { base: "area", path: "guia-de-uso", path_en: "usage-guide" },
    seo: { title_en: "Usage guide" },
  },
  perfil: { published: true, url: { base: "root", path: "mi-perfil" } },
};

const landing = (over: Record<string, unknown> = {}) => ({
  slug: "guia",
  contentLang: "es",
  locales: ["es", "en"],
  hero: null,
  accent: null,
  lead: null,
  sections: [],
  quote: null,
  faq: null,
  products: null,
  product: null,
  productCtaLabel: null,
  closing: null,
  seo: {
    title: "Guía de uso",
    description: null,
    ogImage: null,
    datePublished: null,
    dateModified: null,
    author: null,
  },
  ...over,
});

beforeEach(() => {
  vi.clearAllMocks();
  config(true, EDITORIAL, REGISTRY);
  getEditorialLanding.mockResolvedValue(landing());
});

describe("EditorialLandingRoute", () => {
  it("con el módulo apagado es 404 y no lee la landing", async () => {
    config(false, EDITORIAL, REGISTRY);

    await expect(
      EditorialLandingRoute({ locale: "es", base: "area", path: "guia-de-uso" }),
    ).rejects.toThrow("NEXT_NOT_FOUND");
    expect(getEditorialLanding).not.toHaveBeenCalled();
  });

  it("un tramo que no existe es 404", async () => {
    await expect(
      EditorialLandingRoute({ locale: "es", base: "area", path: "inventado" }),
    ).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("una landing sin publicar o sin contenido es 404", async () => {
    getEditorialLanding.mockResolvedValue(null);

    await expect(
      EditorialLandingRoute({ locale: "es", base: "area", path: "guia-de-uso" }),
    ).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("una landing de la raíz no responde bajo el área, ni al revés", async () => {
    await expect(
      EditorialLandingRoute({ locale: "es", base: "area", path: "mi-perfil" }),
    ).rejects.toThrow("NEXT_NOT_FOUND");
    await expect(
      EditorialLandingRoute({ locale: "es", base: "root", path: "guia-de-uso" }),
    ).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("el tramo de otro idioma redirige al canónico del idioma de la página", async () => {
    await expect(
      EditorialLandingRoute({ locale: "en", base: "area", path: "guia-de-uso" }),
    ).rejects.toThrow("NEXT_REDIRECT:/learn/usage-guide");
    expect(permanentRedirect).toHaveBeenCalledWith({
      href: "/learn/usage-guide",
      locale: "en",
    });
  });

  it("publicada y en su tramo canónico pinta la vista con migas y artículo", async () => {
    const tree = await EditorialLandingRoute({
      locale: "es",
      base: "area",
      path: "guia-de-uso",
    });

    expect(getEditorialLanding).toHaveBeenCalledWith("guia", "es");
    expect(jsonLdTypes(await rendered(tree))).toEqual(["BreadcrumbList", "Article"]);
  });

  it("una landing de tipo persona declara `Person`; con preguntas, también FAQPage", async () => {
    getEditorialLanding.mockResolvedValue(
      landing({
        slug: "perfil",
        faq: { title: null, items: [{ question: "¿Quién?", answer: "<p>Yo</p>" }] },
      }),
    );

    const tree = await EditorialLandingRoute({
      locale: "es",
      base: "root",
      path: "mi-perfil",
    });

    expect(jsonLdTypes(await rendered(tree))).toEqual([
      "BreadcrumbList",
      "Person",
      "FAQPage",
    ]);
  });
});

describe("editorialStaticParams", () => {
  it("una entrada por landing publicada de la ubicación y por idioma con traducción", async () => {
    const params = await editorialStaticParams("area", "slug");

    // `guia` trae título SEO en inglés: existe en los dos idiomas, con el tramo de cada uno.
    expect(params).toEqual([
      { locale: "es", slug: "guia-de-uso" },
      { locale: "en", slug: "usage-guide" },
    ]);
  });

  it("la raíz usa su propio nombre de parámetro y sin traducción solo el idioma por defecto", async () => {
    const params = await editorialStaticParams("root", "landingPath");

    expect(params).toEqual([{ locale: "es", landingPath: "mi-perfil" }]);
  });

  it("con el módulo apagado no hay params", async () => {
    config(false, EDITORIAL, REGISTRY);

    expect(await editorialStaticParams("area", "slug")).toEqual([]);
  });
});

describe("editorialMetadata", () => {
  it("anuncia solo los idiomas de la landing, con el tramo de cada uno", async () => {
    getEditorialLanding.mockResolvedValue(landing({ locales: ["es"] }));

    const metadata = await editorialMetadata("es", "area", "guia-de-uso");

    expect(metadata.alternates?.canonical).toBe("/learn/guia-de-uso");
    expect(Object.keys(metadata.alternates?.languages ?? {})).toEqual([
      "es",
      "x-default",
    ]);
  });

  it("con traducción añade el hreflang con su tramo", async () => {
    const metadata = await editorialMetadata("es", "area", "guia-de-uso");

    expect(metadata.alternates?.languages).toMatchObject({
      es: "/learn/guia-de-uso",
      en: "/en/learn/usage-guide",
    });
  });

  it("sin landing no declara metadatos", async () => {
    getEditorialLanding.mockResolvedValue(null);

    expect(await editorialMetadata("es", "area", "guia-de-uso")).toEqual({});
  });
});
