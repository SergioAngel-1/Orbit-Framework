import { normalizeGeoPack } from "@/lib/geo/normalize";
import { normalizePlacement } from "@/lib/banners/normalize";
import { normalizeShippingMethods } from "@/lib/shipping/normalize";
import { normalizeFreeShippingScope } from "@/lib/shipping/free-shipping-scope";
import { readFileSync } from "node:fs";
import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { afterEach, describe, expect, it } from "vitest";
import {
  buildSiteConfig,
  createStubServer,
  STUB_ORDERS,
} from "../../../.github/scripts/stub-graphql.mjs";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// ============================================================================
//  Endpoint GraphQL de pega del CI (.github/scripts/stub-graphql.mjs) y su
//  fixture de configuración (.github/scripts/site-config.ci.json).
// ============================================================================

const FIXTURE_URL = new URL(
  "../../../.github/scripts/site-config.ci.json",
  import.meta.url,
);
const fixture = JSON.parse(readFileSync(FIXTURE_URL, "utf8"));

let server: Server | undefined;

/** Arranca el stub en un puerto libre y devuelve su URL base. */
async function startStub(): Promise<string> {
  server = createStubServer(buildSiteConfig(fixture));
  await new Promise<void>((ok) => server!.listen(0, "127.0.0.1", ok));
  return `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
}

afterEach(async () => {
  if (server) await new Promise((ok) => server!.close(ok));
  server = undefined;
});

describe("fixture site-config.ci.json", () => {
  it("la paleta es la POR DEFECTO del framework (no diverge de CONFIG_DEFAULTS)", () => {
    // El CI sirve esta paleta al frontend: si cambia el valor por defecto, el
    // fixture debe cambiar con él, o axe mediría una paleta que ya no existe.
    expect(fixture.design.colors).toEqual(CONFIG_DEFAULTS.design.colors);
  });
});

describe("buildSiteConfig", () => {
  it("expone solo `design.colors`: nada de tipografía (traería Google Fonts) ni metadatos", () => {
    const config = buildSiteConfig({
      _comentario: "no debe salir",
      design: { colors: { brand: "#111111" }, typography: { font_url: "https://x" } },
    });
    expect(config).toEqual({ design: { colors: { brand: "#111111" } } });
  });

  it("`extra` (STUB_SITE_CONFIG_EXTRA) añade secciones: los e2e activan lo opcional sin tocar el fixture", () => {
    const config = buildSiteConfig(
      { design: { colors: { brand: "#111111" } } },
      { payments: { whatsapp_enabled: true, cash_enabled: true } },
    );
    expect(config).toEqual({
      design: { colors: { brand: "#111111" } },
      payments: { whatsapp_enabled: true, cash_enabled: true },
    });
  });

  it("`extra` no puede sustituir la paleta del fixture", () => {
    const config = buildSiteConfig(
      { design: { colors: { brand: "#111111" } } },
      { design: { colors: { brand: "#ff0000" } } },
    );
    expect(config.design.colors.brand).toBe("#111111");
  });
});

describe("createStubServer", () => {
  it("la ruta de config devuelve la paleta del fixture", async () => {
    const base = await startStub();

    const res = await fetch(`${base}/wp-json/hwe/v1/config`);

    expect(res.status).toBe(200);
    expect(res.headers.get("content-type")).toContain("application/json");
    expect(await res.json()).toEqual({ design: { colors: fixture.design.colors } });
  });

  it("la ruta de config ignora la query string", async () => {
    const base = await startStub();

    const res = await fetch(`${base}/wp-json/hwe/v1/config?_=1`);

    expect(await res.json()).toHaveProperty("design.colors.brand");
  });

  it("cualquier otra ruta responde {data:{}} (CMS vacío), también a un POST con cuerpo", async () => {
    const base = await startStub();

    const get = await fetch(`${base}/cualquier/ruta`);
    const post = await fetch(`${base}/graphql`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ query: "{ products { nodes { id } } }" }),
    });

    expect(await get.json()).toEqual({ data: {} });
    expect(post.status).toBe(200);
    expect(await post.json()).toEqual({ data: {} });
  });
});

describe("landing de producto de prueba (GraphQL)", () => {
  async function operation(base: string, query: string, variables: object = {}) {
    const res = await fetch(`${base}/graphql`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ query, variables }),
    });
    return res.json();
  }

  it("publica el producto de prueba y sirve su landing con traducción", async () => {
    const base = await startStub();

    expect(
      await operation(
        base,
        "query ProductLandingsPublished { hweProductLandingsPublished }",
      ),
    ).toEqual({ data: { hweProductLandingsPublished: ["e2e-landing"] } });

    const landing = await operation(base, "query ProductLanding($slug: ID!) { x }", {
      slug: "e2e-landing",
    });
    expect(landing.data.product.landing.published).toBe(true);
    expect(landing.data.product.landing.tr_en.heroSubtitle).toBeTruthy();

    const product = await operation(base, "query ProductBySlug($slug: ID!) { x }", {
      slug: "e2e-landing",
    });
    expect(product.data.product.tr_en.slug).toBe("e2e-landing-en");
  });

  it("un producto que no es el de prueba no tiene landing ni ficha", async () => {
    const base = await startStub();

    // Como WooGraphQL: `product: null` MÁS un error, no un `null` limpio.
    const unknown = {
      data: { product: null },
      errors: [{ message: "No product ID was found corresponding to the slug: otro" }],
    };
    expect(
      await operation(base, "query ProductLanding($slug: ID!) { x }", { slug: "otro" }),
    ).toEqual(unknown);
    expect(
      await operation(base, "query ProductBySlug($slug: ID!) { x }", { slug: "otro" }),
    ).toEqual(unknown);
  });

  it("resuelve el slug traducido y deja vacío cualquier otro", async () => {
    const base = await startStub();
    const query =
      "query SlugFrom($postType: String!, $locale: String!, $slug: String!) { x }";

    expect(
      await operation(base, query, {
        postType: "product",
        locale: "en",
        slug: "e2e-landing-en",
      }),
    ).toEqual({ data: { hweSlugFrom: "e2e-landing" } });
    expect(
      await operation(base, query, {
        postType: "post",
        locale: "en",
        slug: "e2e-landing-en",
      }),
    ).toEqual({ data: { hweSlugFrom: null } });
  });

  it("una operación desconocida sigue respondiendo vacío", async () => {
    const base = await startStub();

    expect(
      await operation(base, "query Products { products { nodes { id } } }"),
    ).toEqual({
      data: {},
    });
  });
});

describe("pedidos de prueba (wc/v3)", () => {
  it("sirve cada pedido del fixture, del cliente 7", async () => {
    const base = await startStub();

    for (const order of STUB_ORDERS) {
      const res = await fetch(`${base}/wp-json/wc/v3/orders/${order.id}`);
      const body = await res.json();
      expect(body.id).toBe(order.id);
      expect(body.customer_id).toBe(7);
    }
  });

  it("cubre los estados que la vista de pedido distingue", () => {
    const statuses = STUB_ORDERS.map((o: { status: string }) => o.status);

    expect(statuses).toEqual(
      expect.arrayContaining(["completed", "processing", "on-hold"]),
    );
  });

  it("el listado de pedidos (con su query) devuelve todos, como lista", async () => {
    const base = await startStub();

    const res = await fetch(`${base}/wp-json/wc/v3/orders?customer=7&per_page=20`);
    const body = await res.json();

    expect(Array.isArray(body)).toBe(true);
    expect(body).toHaveLength(STUB_ORDERS.length);
  });

  it("las reseñas de producto, vacías y como lista", async () => {
    const base = await startStub();

    const body = await (
      await fetch(`${base}/wp-json/wc/v3/products/reviews?product=3`)
    ).json();

    expect(body).toEqual([]);
  });

  it("sirve un cupón público para los avisos (módulo coupon-rules)", async () => {
    const base = await startStub();

    const body = await (await fetch(`${base}/wp-json/hwe/v1/coupons/public`)).json();

    expect(body.items).toHaveLength(1);
    expect(body.items[0]).toMatchObject({
      code: "HOLA10",
      scope: "all",
      perUserLimited: false,
    });
  });

  it("sirve el paquete de país genérico, con la forma del contrato de `hwe-geo`", async () => {
    const base = await startStub();

    const body = await (await fetch(`${base}/wp-json/hwe/v1/geo`)).json();

    // Lo que el lector acepta: si el stub divergiera del contrato, el CI
    // ejercitaría la rama de respaldo sin enterarse.
    expect(normalizeGeoPack(body)).toEqual(body);
    expect(body).toMatchObject({ country: "generic", requested: "ES" });
  });

  it("sirve los extras y el alcance del envío (módulo shipping)", async () => {
    const base = await startStub();

    const methods = await (
      await fetch(`${base}/wp-json/hwe-shipping/v1/methods`)
    ).json();
    const scope = await (
      await fetch(`${base}/wp-json/hwe-shipping/v1/free-shipping/scope`)
    ).json();

    expect(normalizeShippingMethods(methods)).toEqual([
      { id: 3, name: "Seguro de envío", addedValue: 5 },
    ]);
    expect(normalizeFreeShippingScope(scope, "es").cities).toEqual(["Madrid"]);
  });

  it("sirve el carrusel de la portada con una imagen que el propio stub entrega", async () => {
    const base = await startStub();

    const raw = await (
      await fetch(`${base}/wp-json/hwe-banners/v1/banners/carousel`)
    ).json();
    const placement = normalizePlacement(raw);
    const image = await fetch(placement.slides[0].image);

    expect(placement.slides).toHaveLength(1);
    expect(placement.slides[0].image.startsWith(base)).toBe(true);
    expect(image.headers.get("content-type")).toBe("image/svg+xml");
  });

  it("un pedido que no está en el fixture responde {data:{}} (CMS vacío)", async () => {
    const base = await startStub();

    const res = await fetch(`${base}/wp-json/wc/v3/orders/99999`);

    expect(await res.json()).toEqual({ data: {} });
  });

  it("sirve el cliente 7 con correo y sin meta ajena", async () => {
    const base = await startStub();

    const body = await (await fetch(`${base}/wp-json/wc/v3/customers/7`)).json();

    expect(body).toMatchObject({ id: 7, email: "ana@example.com" });
  });
});
