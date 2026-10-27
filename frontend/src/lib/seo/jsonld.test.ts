import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// Las URL que construye el núcleo no dependen del registro de la instancia: estos tests
// fijan el caso sin mapa público (los mapas se prueban en los `*.public-paths.test.ts`).
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));
import type { ProductDetail } from "@/types/catalog";
import {
  BUSINESS_TYPES,
  COLLECTION_ITEM_LIST_LIMIT,
  buildArticleJsonLd,
  buildCollectionPageJsonLd,
  buildFounderPageJsonLd,
  buildLocalBusinessJsonLd,
  buildProductJsonLd,
  buildSiteGraph,
  buildVideoObjectJsonLd,
  businessType,
  parseAmountRange,
  parseDayRange,
  postalAddress,
  videoUploadDateTime,
} from "./jsonld";

function config() {
  const c = structuredClone(CONFIG_DEFAULTS);
  c.brand.name = "Mi Tienda";
  c.brand.url = "https://mitienda.example";
  c.ecommerce.currency = "EUR";
  c.ecommerce.country = "ES";
  return c;
}

const product = {
  databaseId: 7,
  name: "Camiseta",
  price: "€19,99",
  stockStatus: "IN_STOCK",
  image: { sourceUrl: "https://mitienda.example/c.jpg", altText: "" },
  reviews: { averageRating: 0, items: [] },
} as unknown as ProductDetail;

const productLd = (c = config()) =>
  buildProductJsonLd({
    product,
    config: c,
    url: "https://mitienda.example/products/camiseta",
    description: "Una camiseta",
  }) as Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

describe("buildProductJsonLd — envío", () => {
  it("sin importe en el panel no declara shippingDetails", () => {
    expect(productLd().offers).not.toHaveProperty("shippingDetails");
  });

  it("un importe único", () => {
    const c = config();
    c.seo.shipping_amount = "5";
    expect(productLd(c).offers.shippingDetails.shippingRate).toMatchObject({
      value: 5,
      currency: "EUR",
    });
  });

  it("un rango se declara como mín-máx, no como un importe inventado", () => {
    const c = config();
    c.seo.shipping_amount = "12-5";
    const rate = productLd(c).offers.shippingDetails.shippingRate;
    expect(rate).toMatchObject({ minValue: 5, maxValue: 12 });
    expect(rate).not.toHaveProperty("value");
  });

  it("texto suelto junto al importe no lo anula", () => {
    expect(parseAmountRange("12 EUR")).toEqual({ min: 12, max: 12 });
    expect(parseAmountRange("")).toBeNull();
    expect(parseAmountRange("gratis")).toBeNull();
  });

  it("deliveryTime solo con los DOS plazos (preparación y tránsito)", () => {
    const c = config();
    c.seo.shipping_amount = "5";
    c.seo.handling_days = "0-1";
    expect(productLd(c).offers.shippingDetails).not.toHaveProperty("deliveryTime");
    c.seo.transit_days = "2-4";
    expect(productLd(c).offers.shippingDetails.deliveryTime).toMatchObject({
      "@type": "ShippingDeliveryTime",
      handlingTime: { minValue: 0, maxValue: 1, unitCode: "DAY" },
      transitTime: { minValue: 2, maxValue: 4, unitCode: "DAY" },
    });
  });

  it("un plazo mal escrito no se adivina", () => {
    expect(parseDayRange("pronto")).toBeNull();
    expect(parseDayRange("5-2")).toBeNull();
    expect(parseDayRange("3")).toMatchObject({ minValue: 3, maxValue: 3 });
  });
});

describe("buildProductJsonLd — devoluciones", () => {
  it("ventana finita con días, método y quién paga", () => {
    const c = config();
    c.seo.return_category = "finite";
    c.seo.return_days = "14";
    c.seo.return_method = "mail_and_store";
    c.seo.return_fees = "customer_pays";
    const policy = productLd(c).offers.hasMerchantReturnPolicy;
    expect(policy.merchantReturnDays).toBe(14);
    expect(policy.returnMethod).toEqual([
      "https://schema.org/ReturnByMail",
      "https://schema.org/ReturnInStore",
    ]);
    // «El cliente paga» NO es ReturnShippingFees (esa es una tarifa de la
    // tienda y Google exigiría su importe).
    expect(policy.returnFees).toBe(
      "https://schema.org/ReturnFeesCustomerResponsibility",
    );
  });

  it("finita sin días no se declara: sería una ventana inventada", () => {
    const c = config();
    c.seo.return_category = "finite";
    c.seo.return_days = "";
    expect(productLd(c).offers).not.toHaveProperty("hasMerchantReturnPolicy");
  });

  it("ilimitada y «no se admiten» se emiten sin días", () => {
    const c = config();
    c.seo.return_category = "none";
    c.seo.return_days = "";
    const policy = productLd(c).offers.hasMerchantReturnPolicy;
    expect(policy.returnPolicyCategory).toBe(
      "https://schema.org/MerchantReturnNotPermitted",
    );
    expect(policy).not.toHaveProperty("merchantReturnDays");
    expect(policy).not.toHaveProperty("returnMethod");
  });

  it("sin foto ni descripción, no emite `image` ni `description` vacías", () => {
    const ld = buildProductJsonLd({
      product: { ...product, image: null } as ProductDetail,
      config: config(),
      url: "https://mitienda.example/products/camiseta",
      description: "   ",
    });
    expect(ld).not.toHaveProperty("image");
    expect(ld).not.toHaveProperty("description");
    // El resto del nodo no cambia.
    expect(ld.name).toBe("Camiseta");
    expect(ld.offers).toBeDefined();
    expect(ld.brand).toBeDefined();
  });

  it("con foto y descripción, la salida conserva ambas claves", () => {
    const ld = productLd();
    expect(ld.image).toEqual(["https://mitienda.example/c.jpg"]);
    expect(ld.description).toBe("Una camiseta");
  });

  it("la ficha lleva @id y validFrom", () => {
    const ld = productLd();
    expect(ld["@id"]).toBe("https://mitienda.example/products/camiseta#product");
    expect(ld.offers.validFrom).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });
});

type JsonNode = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

describe("buildSiteGraph", () => {
  const graph = (c = config(), locale = "es", opts = {}) =>
    buildSiteGraph(c, locale, opts)["@graph"] as JsonNode[];
  const node = (g: JsonNode[], type: string) => g.find((n) => n["@type"] === type);

  it("Organization + WebSite, con buscador solo si la búsqueda está activa", () => {
    const c = config();
    c.ecommerce.search_enabled = true;
    expect(node(graph(c), "WebSite")!.potentialAction.target.urlTemplate).toBe(
      "https://mitienda.example/products?search={search_term_string}",
    );
    c.ecommerce.search_enabled = false;
    expect(node(graph(c), "WebSite")).not.toHaveProperty("potentialAction");
  });

  it("sin datos legales ni redes, la Organization no publica identidad ajena", () => {
    const org = node(graph(config()), "Organization")!;
    expect(org).not.toHaveProperty("sameAs");
    expect(org).not.toHaveProperty("contactPoint");
    expect(org).not.toHaveProperty("legalName");
  });

  it("la descripción sale en el idioma de la página", () => {
    const c = config();
    c.brand.description = "Descripción";
    (c.brand as Record<string, unknown>).description_en = "Description";
    expect(node(graph(c, "es"), "Organization")!.description).toBe("Descripción");
    expect(node(graph(c, "en"), "Organization")!.description).toBe("Description");
  });

  it("otros nombres de la marca y razón social, sin repetir el nombre", () => {
    const c = config();
    c.seo.alternate_names = "Tienda Antigua, Mi Tienda";
    c.legal.company = "Mi Tienda SL";
    const org = node(graph(c), "Organization")!;
    expect(org.alternateName).toEqual(["Tienda Antigua"]);
    expect(org.legalName).toBe("Mi Tienda SL");
  });

  describe("fundador", () => {
    const withFounder = (name: string) => {
      const c = config();
      c.seo.founder_name = name;
      return c;
    };

    it("Organization y Person comparten el mismo @id", () => {
      const g = graph(withFounder("Ana Ruiz"));
      const person = node(g, "Person")!;
      expect(person["@id"]).toBe("https://mitienda.example/#founder");
      expect(node(g, "Organization")!.founder).toEqual({ "@id": person["@id"] });
      expect(person).not.toHaveProperty("@context");
    });

    it("Person.worksFor es la Organization", () => {
      expect(node(graph(withFounder("Ana Ruiz")), "Person")!.worksFor).toEqual({
        "@id": "https://mitienda.example/#organization",
      });
    });

    it("con página propia su url es esa y el perfil externo va en sameAs", () => {
      const c = withFounder("Ana Ruiz");
      c.seo.founder_url = "https://linkedin.com/in/ana";
      const person = node(
        graph(c, "es", { founderPageUrl: "https://mitienda.example/about" }),
        "Person",
      )!;
      expect(person.url).toBe("https://mitienda.example/about");
      expect(person.sameAs).toEqual(["https://linkedin.com/in/ana"]);
    });

    it("sin nombre no hay Person ni arista", () => {
      const g = graph(withFounder(""));
      expect(node(g, "Person")).toBeUndefined();
      expect(node(g, "Organization")).not.toHaveProperty("founder");
    });

    it("el documento de su página lleva el mismo @id y su @context", () => {
      const doc = buildFounderPageJsonLd(
        withFounder("Ana Ruiz"),
        "https://mitienda.example/about",
      )!;
      expect(doc["@context"]).toBe("https://schema.org");
      expect(doc["@id"]).toBe("https://mitienda.example/#founder");
      expect(doc.mainEntityOfPage).toBe("https://mitienda.example/about");
      expect(buildFounderPageJsonLd(withFounder(""), "x")).toBeNull();
    });
  });
});

describe("postalAddress y sedes", () => {
  it("emite localidad, región y código postal cuando el panel los trae", () => {
    expect(
      postalAddress(config(), {
        street: "Calle Mayor 1",
        city: " Madrid ",
        region: "Madrid",
        postalCode: "28001",
      }),
    ).toEqual({
      "@type": "PostalAddress",
      streetAddress: "Calle Mayor 1",
      addressLocality: "Madrid",
      addressRegion: "Madrid",
      postalCode: "28001",
      addressCountry: "ES",
    });
  });

  it("no emite claves vacías", () => {
    expect(
      postalAddress(config(), { street: "Calle 1", city: "", postalCode: " " }),
    ).toEqual({
      "@type": "PostalAddress",
      streetAddress: "Calle 1",
      addressCountry: "ES",
    });
  });

  function withLocation() {
    const c = config();
    c.legal.locations.location_1 = {
      ...c.legal.locations.location_1,
      label: "Madrid",
      address: "Calle Mayor 1",
      city: "Madrid",
      region: "Madrid",
      postal_code: "28001",
    };
    return c;
  }

  it("una entidad por sede, con las partes de su dirección", () => {
    const [node] = buildLocalBusinessJsonLd(withLocation());
    expect(node!["@type"]).toBe("LocalBusiness");
    expect(node!["@id"]).toBe("https://mitienda.example/#location-1");
    expect(node!.address).toMatchObject({
      addressLocality: "Madrid",
      postalCode: "28001",
    });
  });

  it("el tipo de negocio es el del panel, solo si es uno de los ofrecidos", () => {
    const c = withLocation();
    c.seo.business_type = "Bakery";
    expect(buildLocalBusinessJsonLd(c)[0]!["@type"]).toBe("Bakery");
    c.seo.business_type = "<script>";
    expect(buildLocalBusinessJsonLd(c)[0]!["@type"]).toBe("LocalBusiness");
    c.seo.business_type = "";
    expect(businessType(c)).toBe("LocalBusiness");
  });

  it("la lista de tipos coincide con las opciones del esquema del panel", () => {
    const schema = readFileSync(
      join(
        __dirname,
        "../../../../backend/wp-content/mu-plugins/hwe-control-center/SchemaGroups/Seo.php",
      ),
      "utf8",
    );
    const block =
      /'business_type'\s*=>\s*\[[\s\S]*?'options'\s*=>\s*\[([\s\S]*?)\],\s*'description'/.exec(
        schema,
      )?.[1];
    expect(block, "business_type no encontrado en SchemaGroups/Seo.php").toBeTruthy();
    const options = [...block!.matchAll(/'([A-Za-z]+)'\s*=>/g)].map((m) => m[1]);
    expect(options.sort()).toEqual([...BUSINESS_TYPES].sort());
  });
});

describe("buildArticleJsonLd", () => {
  const base = {
    headline: "Título",
    description: "Resumen",
    config: config(),
    url: "https://mitienda.example/blog/x",
  };

  it("atribuye a la Organization por @id si no hay autor", () => {
    const node = buildArticleJsonLd(base);
    expect(node.author).toEqual({ "@id": "https://mitienda.example/#organization" });
    expect(node.publisher).toEqual({ "@id": "https://mitienda.example/#organization" });
  });

  it("BlogPosting con autor, idioma y speakable", () => {
    const node = buildArticleJsonLd({
      ...base,
      type: "BlogPosting",
      author: { name: "Ana", url: "https://ana.example", description: "Bio" },
      inLanguage: "es",
      speakable: [".post-headline"],
      datePublished: "2026-01-01T00:00:00",
    });
    expect(node["@type"]).toBe("BlogPosting");
    expect(node.author).toMatchObject({
      "@type": "Person",
      name: "Ana",
      description: "Bio",
    });
    expect(node.inLanguage).toBe("es");
    expect(node.speakable).toEqual({
      "@type": "SpeakableSpecification",
      cssSelector: [".post-headline"],
    });
    expect(node).not.toHaveProperty("dateModified");
  });
});

describe("vídeo", () => {
  it("un día a secas sale con hora y zona (Google exige la zona)", () => {
    expect(videoUploadDateTime("2025-09-18")).toBe("2025-09-18T12:00:00Z");
  });

  it("una fecha con hora y zona se respeta", () => {
    expect(videoUploadDateTime("2025-09-18T08:30:00-05:00")).toBe(
      "2025-09-18T08:30:00-05:00",
    );
  });

  it("con hora pero sin zona, un mes a secas o basura: nada", () => {
    expect(videoUploadDateTime("2025-09-18T08:30:00")).toBeUndefined();
    expect(videoUploadDateTime("2025-09")).toBeUndefined();
    expect(videoUploadDateTime("18/09/2025")).toBeUndefined();
    expect(videoUploadDateTime(undefined)).toBeUndefined();
  });

  it("buildVideoObjectJsonLd solo emite uploadDate si es válida", () => {
    const base = { name: "n", description: "d", youtubeId: "abc" };
    expect(
      buildVideoObjectJsonLd({ ...base, uploadDate: "2025-09-18" }).uploadDate,
    ).toBe("2025-09-18T12:00:00Z");
    expect(buildVideoObjectJsonLd(base)).not.toHaveProperty("uploadDate");
  });
});

describe("buildCollectionPageJsonLd", () => {
  it("CollectionPage con ItemList y total real cuando va acotada", () => {
    const node = buildCollectionPageJsonLd({
      name: "Tienda",
      url: "https://mitienda.example/products",
      items: [
        { name: "A", url: "u1", image: null },
        { name: "B", url: "u2", image: "i" },
      ],
      totalItems: 50,
    }) as Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
    expect(node.mainEntity.numberOfItems).toBe(50);
    expect(node.mainEntity.itemListElement).toHaveLength(2);
    expect(node.mainEntity.itemListElement[0]).not.toHaveProperty("image");
    expect(COLLECTION_ITEM_LIST_LIMIT).toBe(24);
  });
});

describe("buildProductJsonLd — reseñas", () => {
  const reviewed = {
    ...product,
    reviews: {
      averageRating: 4.5,
      items: [{ rating: 5, authorName: "Ana", date: "2026-01-01", content: "Bien" }],
    },
  } as unknown as ProductDetail;

  const ld = (reviewsEnabled: boolean) => {
    const c = config();
    c.ecommerce.reviews_enabled = reviewsEnabled;
    return buildProductJsonLd({
      product: reviewed,
      config: c,
      url: "https://mitienda.example/products/camiseta",
      description: "Una camiseta",
    }) as Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
  };

  it("con reviews_enabled apagado no emite aggregateRating ni review", () => {
    const node = ld(false);
    expect(node).not.toHaveProperty("aggregateRating");
    expect(node).not.toHaveProperty("review");
  });

  it("con reviews_enabled encendido los emite", () => {
    const node = ld(true);
    expect(node.aggregateRating).toMatchObject({
      ratingValue: 4.5,
      reviewCount: 1,
    });
    expect(node.review).toHaveLength(1);
  });
});
