import { describe, it, expect, vi } from "vitest";
import { buildPageBreadcrumbJsonLd } from "./breadcrumbs";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// Las URL que construye el núcleo no dependen del registro de la instancia: estos tests
// fijan el caso sin mapa público (los mapas se prueban en los `*.public-paths.test.ts`).
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

const config = structuredClone(CONFIG_DEFAULTS);
config.brand.url = "https://mitienda.example";

type ListItem = { position: number; name: string; item: string };
const items = (node: Record<string, unknown>) => node.itemListElement as ListItem[];

describe("buildPageBreadcrumbJsonLd", () => {
  it("antepone Inicio y numera desde 1", () => {
    const node = buildPageBreadcrumbJsonLd(config, "es", "Inicio", [
      { name: "Blog", href: "/blog" },
    ]);
    expect(node["@type"]).toBe("BreadcrumbList");
    expect(items(node).map((i) => [i.position, i.name])).toEqual([
      [1, "Inicio"],
      [2, "Blog"],
    ]);
  });

  it("las URLs son absolutas y con el prefijo del locale", () => {
    expect(
      items(
        buildPageBreadcrumbJsonLd(config, "es", "Inicio", [
          { name: "Blog", href: "/blog" },
        ]),
      )[1]!.item,
    ).toBe("https://mitienda.example/blog");

    const en = items(
      buildPageBreadcrumbJsonLd(config, "en", "Home", [
        { name: "Blog", href: "/blog" },
      ]),
    );
    expect(en[0]!.item).toBe("https://mitienda.example/en");
    expect(en[1]!.item).toBe("https://mitienda.example/en/blog");
  });

  it("admite más de un tramo", () => {
    const node = buildPageBreadcrumbJsonLd(config, "es", "Inicio", [
      { name: "Tienda", href: "/products" },
      { name: "Camisetas", href: "/categories/camisetas" },
    ]);
    expect(items(node)).toHaveLength(3);
    expect(items(node)[2]!.item).toBe("https://mitienda.example/categories/camisetas");
  });
});
