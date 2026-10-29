import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactElement, ReactNode } from "react";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// ============================================================================
//  Landing de producto (`/products/[slug]/learn`): con los dos módulos apagados, o
//  sin landing publicada, es 404; un tramo que no es el canónico redirige (308); la
//  landing de producto manda sobre una editorial asociada; y publicada pinta la
//  vista y declara su JSON-LD (el FAQPage solo si hay preguntas con respuesta).
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
const resolveEntityRoute = vi.hoisted(() => vi.fn());
vi.mock("@/lib/i18n/entity-route", () => ({ resolveEntityRoute }));
vi.mock("@/lib/catalog/products", () => ({
  getProductBySlug: vi.fn(),
  getProductUrls: vi.fn(async () => []),
}));
const getProductLanding = vi.hoisted(() => vi.fn());
vi.mock("@/lib/landings/product-landing", () => ({
  getProductLanding,
  getPublishedProductLandingSlugs: vi.fn(async () => []),
  getPublishedProductLandingLocales: vi.fn(async () => ({})),
}));
vi.mock("@/lib/landings/related-product-landings", () => ({
  getRelatedProductLandings: vi.fn(async () => []),
}));
const getEditorialLandingForProduct = vi.hoisted(() => vi.fn());
vi.mock("@/lib/landings/editorial-product", () => ({ getEditorialLandingForProduct }));
const getEditorialLanding = vi.hoisted(() => vi.fn());
vi.mock("@/lib/landings/editorial-landing", () => ({ getEditorialLanding }));
vi.mock("@/lib/landings/editorial-href", () => ({
  resolveCmsLink: vi.fn(async () => null),
}));
vi.mock("@/lib/landings/product-bound-landings", () => ({
  getProductBoundLandingSlugs: vi.fn(async () => new Set()),
  getProductBoundLandingLocales: vi.fn(async () => ({})),
}));
vi.mock("@/components/landings/editorial/editorial-landing-route", () => ({
  EditorialLandingContent: () => null,
  heroTitleOf: (landing: { seo: { title: string | null } }) => landing.seo.title ?? "",
}));
vi.mock("@/components/landings/product/landing-product-view", () => ({
  LandingProductView: () => null,
}));

import { getSiteConfig } from "@/lib/config";
import ProductLearnPage, { generateMetadata } from "./page";

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

function config(productLanding: boolean, landings = false) {
  const base = structuredClone(CONFIG_DEFAULTS);
  base.brand.url = "https://tienda.example";
  base.modules = { ...base.modules, "product-landing": productLanding, landings };
  vi.mocked(getSiteConfig).mockResolvedValue(base);
}

const editorialLanding = {
  slug: "guia",
  contentLang: "es",
  locales: ["es"],
  hero: null,
  accent: null,
  lead: null,
  sections: [],
  quote: null,
  faq: null,
  products: null,
  product: "camiseta",
  productCtaLabel: null,
  closing: null,
  seo: {
    title: "Guía editorial",
    description: null,
    ogImage: null,
    datePublished: null,
    dateModified: null,
    author: null,
  },
};

const product = {
  name: "Camiseta",
  slug: "camiseta",
  urlSlug: "camiseta",
  price: "20",
  stockStatus: "IN_STOCK",
  shortDescription: "Resumen",
  image: null,
  locales: ["es"],
  urlSlugs: { es: "camiseta" },
};

const landing = (questions: { heading: string | null; body: string }[] = []) => ({
  contentLang: "es",
  locales: ["es"],
  seoTitle: null,
  seoDescription: null,
  seoOgImage: null,
  accents: { primary: null, secondary: null },
  heroSubtitle: "Subtítulo",
  heroImage: null,
  edu: { enabled: false, kicker: null, title: null, body: null, image: null },
  about: { title: null, body: null, components: [] },
  qualities: { enabled: false, title: null, items: [] },
  advice: { enabled: true, title: null, intro: null, items: questions },
  cta: { title: null, text: null },
  video: { enabled: false, url: null, caption: null, uploadDate: null, poster: null },
  closing: { title: null, text: null, ctaLabel: null, ctaHref: null },
});

const route = (isCanonical = true) => ({
  entity: product,
  nativeSlug: "camiseta",
  canonicalSlug: "camiseta",
  canonicalHref: "/products/camiseta",
  isCanonical,
});

const render = () =>
  ProductLearnPage({ params: Promise.resolve({ locale: "es", slug: "camiseta" }) });

const jsonLdTypes = (tree: ReactNode) =>
  find(tree, (el) => el.type === "script").map((el) => {
    const html = (el.props.dangerouslySetInnerHTML as { __html: string }).__html;
    return JSON.parse(html)["@type"] as string;
  });

beforeEach(() => {
  vi.clearAllMocks();
  getEditorialLandingForProduct.mockResolvedValue(null);
  getEditorialLanding.mockResolvedValue(null);
  config(true);
  resolveEntityRoute.mockResolvedValue(route());
  getProductLanding.mockResolvedValue(landing());
});

describe("ProductLearnPage", () => {
  it("con los dos módulos apagados es 404 y no consulta nada", async () => {
    config(false, false);

    await expect(render()).rejects.toThrow("NEXT_NOT_FOUND");
    expect(resolveEntityRoute).not.toHaveBeenCalled();
    expect(getProductLanding).not.toHaveBeenCalled();
  });

  it("un producto que no existe es 404", async () => {
    resolveEntityRoute.mockResolvedValue(null);

    await expect(render()).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("sin landing publicada es 404", async () => {
    getProductLanding.mockResolvedValue(null);

    await expect(render()).rejects.toThrow("NEXT_NOT_FOUND");
    expect(getProductLanding).toHaveBeenCalledWith("camiseta", "es");
  });

  it("un tramo que no es el canónico redirige al de la ficha en su idioma", async () => {
    resolveEntityRoute.mockResolvedValue(route(false));

    await expect(render()).rejects.toThrow("NEXT_REDIRECT:/products/camiseta/learn");
    expect(permanentRedirect).toHaveBeenCalledWith({
      href: "/products/camiseta/learn",
      locale: "es",
    });
  });

  it("publicada pinta la vista y declara migas, artículo y producto", async () => {
    const tree = await render();

    expect(jsonLdTypes(tree)).toEqual(["BreadcrumbList", "Article", "Product"]);
  });

  it("con preguntas con respuesta declara también el FAQPage", async () => {
    getProductLanding.mockResolvedValue(
      landing([
        { heading: "¿Cómo?", body: "Así" },
        { heading: null, body: "Un consejo" },
      ]),
    );

    expect(jsonLdTypes(await render())).toContain("FAQPage");
  });

  it("sin landing de producto, una editorial asociada se pinta con el motor de landings", async () => {
    config(true, true);
    getProductLanding.mockResolvedValue(null);
    getEditorialLandingForProduct.mockResolvedValue({ slug: "guia", ctaLabel: null });
    getEditorialLanding.mockResolvedValue(editorialLanding);

    const tree = (await render()) as ReactElement<Record<string, unknown>>;

    expect(getEditorialLandingForProduct).toHaveBeenCalledWith("camiseta", "es");
    expect(getEditorialLanding).toHaveBeenCalledWith("guia", "es");
    // Lleva el `Product` de la ficha en su JSON-LD y el nombre del producto como etiqueta.
    const props = tree.props as {
      extraJsonLd: { "@type": string }[];
      entry: { slug: string; label: string };
    };
    expect(props.extraJsonLd.map((node) => node["@type"])).toEqual(["Product"]);
    expect(props.entry).toMatchObject({ slug: "guia", label: "Camiseta" });
  });

  it("la landing de producto manda sobre la editorial asociada", async () => {
    config(true, true);
    getEditorialLandingForProduct.mockResolvedValue({ slug: "guia", ctaLabel: null });
    getEditorialLanding.mockResolvedValue(editorialLanding);

    await render();

    expect(getEditorialLandingForProduct).not.toHaveBeenCalled();
  });

  it("solo con el módulo landings y sin editorial asociada es 404", async () => {
    config(false, true);
    getEditorialLandingForProduct.mockResolvedValue(null);

    await expect(render()).rejects.toThrow("NEXT_NOT_FOUND");
    expect(getProductLanding).not.toHaveBeenCalled();
  });

  it("con solo consejos (sin encabezado) no declara FAQPage", async () => {
    getProductLanding.mockResolvedValue(
      landing([{ heading: null, body: "Un consejo" }]),
    );

    expect(jsonLdTypes(await render())).not.toContain("FAQPage");
  });
});

describe("generateMetadata (caja SEO heredada de la ficha)", () => {
  const meta = () =>
    generateMetadata({ params: Promise.resolve({ locale: "es", slug: "camiseta" }) });

  it("ficha normal: la landing se anuncia con su canónica y sin robots", async () => {
    const metadata = await meta();

    expect(metadata.alternates?.canonical).toBe("/products/camiseta/learn");
    expect(metadata.robots).toBeUndefined();
  });

  it("ficha noindex: la landing va noindex,follow y sin alternates", async () => {
    resolveEntityRoute.mockResolvedValue({
      ...route(),
      entity: { ...product, seoNoindex: true },
    });

    const metadata = await meta();

    expect(metadata.robots).toEqual({ index: false, follow: true });
    expect(metadata.alternates).toBeUndefined();
  });

  it("ficha con canónica manual: la landing no inventa canónica, va noindex,follow", async () => {
    resolveEntityRoute.mockResolvedValue({
      ...route(),
      entity: { ...product, seoCanonical: "https://otra.example/camiseta" },
    });

    const metadata = await meta();

    expect(metadata.robots).toEqual({ index: false, follow: true });
    expect(metadata.alternates).toBeUndefined();
  });
});
