import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactElement, ReactNode } from "react";

// ============================================================================
//  Índice A-Z del catálogo: anuncia exactamente lo que anuncian el sitemap y
//  `llms.txt` —caja SEO de la entidad y exclusiones de la instancia—, tanto para
//  fichas como para categorías.
// ============================================================================

const registry = vi.hoisted(() => ({ sitemap: undefined as unknown }));
vi.mock("@/instance/registry", () => ({ instanceRegistry: registry }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("next-intl/server", () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
  setRequestLocale: vi.fn(),
}));
vi.mock("@/i18n/navigation", () => ({ Link: () => null }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn(async () => ({})) }));
vi.mock("@/lib/seo/breadcrumbs", () => ({ buildPageBreadcrumbJsonLd: () => ({}) }));

const catalog = vi.hoisted(() => ({
  products: [] as unknown[],
  categories: [] as unknown[],
}));
vi.mock("@/lib/catalog/products", () => ({
  getProductUrls: vi.fn(async () => catalog.products),
  getCategories: vi.fn(async () => catalog.categories),
}));

import ArchivePage from "./page";

type El = ReactElement<{ children?: ReactNode; href?: string }>;

function hrefs(node: ReactNode, out: string[] = []): string[] {
  if (!node || typeof node !== "object") return out;
  if (Array.isArray(node)) {
    node.forEach((n) => hrefs(n, out));
    return out;
  }
  const el = node as El;
  if (typeof el.props?.href === "string") out.push(el.props.href);
  hrefs(el.props?.children, out);
  return out;
}

const product = (slug: string, extra: Record<string, unknown> = {}) => ({
  slug,
  slugs: { es: slug },
  names: { es: slug },
  locales: ["es"],
  hidden: false,
  ...extra,
});

const render = async () =>
  hrefs(await ArchivePage({ params: Promise.resolve({ locale: "es" }) }));

beforeEach(() => {
  registry.sitemap = undefined;
  catalog.products = [
    product("camiseta"),
    product("oculta", { hidden: true }),
    product("sin-indexar", { seoNoindex: true }),
    product("redirigida"),
    product("otra"),
  ];
  catalog.categories = [
    { slug: "ropa", name: "Ropa" },
    { slug: "privada", name: "Privada", seoNoindex: true },
    { slug: "redirigida", name: "Redirigida" },
  ];
});

describe("ProductsArchivePage", () => {
  it("sin exclusiones solo filtra lo oculto y lo marcado en la caja SEO", async () => {
    const links = await render();

    expect(links).toEqual(
      expect.arrayContaining([
        "/products/camiseta",
        "/products/redirigida",
        "/products/otra",
        "/categories/ropa",
        "/categories/redirigida",
      ]),
    );
    expect(links).not.toContain("/products/oculta");
    expect(links).not.toContain("/products/sin-indexar");
    expect(links).not.toContain("/categories/privada");
  });

  it("aplica las exclusiones de la instancia a fichas y categorías, exactas y con comodín", async () => {
    registry.sitemap = { exclude: ["/products/redirigida", "/categories/redirigida"] };

    const links = await render();

    expect(links).not.toContain("/products/redirigida");
    expect(links).not.toContain("/categories/redirigida");
    expect(links).toContain("/products/camiseta");
    expect(links).toContain("/categories/ropa");
  });

  it("un prefijo con `/*` retira todas las fichas o todas las categorías", async () => {
    registry.sitemap = { exclude: ["/categories/*"] };

    const links = await render();

    expect(links.some((href) => href.startsWith("/categories/"))).toBe(false);
    expect(links).toContain("/products/otra");
  });
});
