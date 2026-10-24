import { beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";
import es from "@/i18n/messages/es.json";
import { catalogFor, hasOwnCatalog } from "@/i18n/messages/catalogs";

let currentConfig: SiteConfig;
let degraded = false;

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => degraded,
}));
vi.mock("@/lib/catalog/products", () => ({
  getCategories: async () => [
    { slug: "camisetas", name: "Camisetas", description: "<p>Algodón.</p>" },
    { slug: "gorras", name: "Gorras", description: null },
  ],
}));
// `t` mínimo sobre los catálogos reales (sin ICU: solo {brand} y {name}).
vi.mock("next-intl/server", () => ({
  getTranslations: async ({
    locale,
    namespace,
  }: {
    locale: string;
    namespace: string;
  }) => {
    const root = (locale === "en" ? catalogFor("en") : es) as unknown as Record<
      string,
      unknown
    >;
    const ns = root[namespace] as Record<string, unknown>;
    return (key: string, values: Record<string, string> = {}) => {
      const value = key
        .split(".")
        .reduce<unknown>((node, part) => (node as Record<string, unknown>)?.[part], ns);
      return String(value).replace(
        /\{(\w+)\}/g,
        (_, name: string) => values[name] ?? "",
      );
    };
  },
}));

import { buildLlmsTxt } from "./build";

beforeEach(() => {
  degraded = false;
  currentConfig = structuredClone(CONFIG_DEFAULTS);
  currentConfig.brand.name = "Mi Tienda";
  currentConfig.brand.url = "https://mitienda.example/";
  currentConfig.geo.llms_txt_enabled = true;
});

describe("buildLlmsTxt", () => {
  it("lista páginas y categorías con URLs absolutas del idioma", async () => {
    const body = (await buildLlmsTxt("es"))!;
    expect(body).toContain("# Mi Tienda");
    expect(body).toContain(
      "- [Inicio](https://mitienda.example/): Página principal de Mi Tienda.",
    );
    expect(body).toContain("(https://mitienda.example/products)");
    expect(body).toContain(
      "- [Camisetas](https://mitienda.example/categories/camisetas): Algodón.",
    );
    expect(body).toContain("Productos de la categoría Gorras.");
    expect(body).toContain("https://mitienda.example/sitemap.xml");
  });

  // Sin catálogo propio para `en` (instancia monolingüe) no hay copy inglés que comprobar.
  it.skipIf(!hasOwnCatalog("en"))(
    "en inglés usa el copy y las URLs del idioma",
    async () => {
      const body = (await buildLlmsTxt("en"))!;
      expect(body).toContain("## Main pages");
      expect(body).toContain(
        "- [Home](https://mitienda.example/en): Mi Tienda home page.",
      );
      expect(body).toContain("(https://mitienda.example/en/legal/privacy)");
    },
  );

  it.skipIf(!hasOwnCatalog("en"))(
    "la descripción de marca sale en el idioma",
    async () => {
      currentConfig.brand.description = "Descripción";
      (currentConfig.brand as Record<string, unknown>).description_en = "Description";
      expect(await buildLlmsTxt("es")).toContain("\nDescripción\n");
      expect(await buildLlmsTxt("en")).toContain("\nDescription\n");
    },
  );

  it("datos clave solo con lo que el panel trae; el cargo del responsable no va", async () => {
    currentConfig.legal.company = "Mi Tienda SL";
    currentConfig.seo.founder_name = "Ana Ruiz";
    currentConfig.seo.founder_role = "Fundadora";
    const body = (await buildLlmsTxt("es"))!;
    expect(body).toContain("- Razón social: Mi Tienda SL");
    expect(body).toContain("- Responsable: Ana Ruiz");
    expect(body).not.toContain("Fundadora");
  });

  it("desactivado en el panel: no hay fichero", async () => {
    currentConfig.geo.llms_txt_enabled = false;
    expect(await buildLlmsTxt("es")).toBeNull();
  });

  it("con la config degradada no publica URLs con el dominio por defecto", async () => {
    degraded = true;
    expect(await buildLlmsTxt("es")).toBeNull();
  });
});
