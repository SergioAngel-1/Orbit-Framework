import { describe, expect, it } from "vitest";
import { findEditorialLandingForProduct } from "./editorial-product";

const config = (
  editorial: Record<string, unknown>,
  registry = "guia | Guía | article",
  enabled = true,
) =>
  ({
    modules: { landings: enabled },
    landings: { registry, editorial },
  }) as never;

const landing = (extra: Record<string, unknown> = {}) => ({
  published: true,
  product: "camiseta",
  product_cta_label: "Descubre la camiseta",
  product_cta_label_en: "Discover the T-shirt",
  ...extra,
});

describe("findEditorialLandingForProduct", () => {
  it("encuentra la landing publicada y registrada de ese producto", () => {
    const link = findEditorialLandingForProduct(
      config({ guia: landing() }),
      "camiseta",
      "es",
    );

    expect(link).toEqual({ slug: "guia", ctaLabel: "Descubre la camiseta" });
  });

  it("devuelve el botón en el idioma pedido", () => {
    const link = findEditorialLandingForProduct(
      config({ guia: landing() }),
      "camiseta",
      "en",
    );

    expect(link?.ctaLabel).toBe("Discover the T-shirt");
  });

  it("ignora una landing sin publicar, de otro producto o sin registrar", () => {
    expect(
      findEditorialLandingForProduct(
        config({ guia: landing({ published: false }) }),
        "camiseta",
        "es",
      ),
    ).toBeNull();
    expect(
      findEditorialLandingForProduct(config({ guia: landing() }), "gorra", "es"),
    ).toBeNull();
    // Sin registrar `getEditorialLanding` la da por inexistente: la ruta serviría un 404.
    expect(
      findEditorialLandingForProduct(config({ guia: landing() }, ""), "camiseta", "es"),
    ).toBeNull();
  });

  it("con el módulo apagado no hay landing", () => {
    expect(
      findEditorialLandingForProduct(
        config({ guia: landing() }, "guia | Guía | article", false),
        "camiseta",
        "es",
      ),
    ).toBeNull();
  });

  it("con dos landings sobre el mismo producto gana la primera del registro", () => {
    const link = findEditorialLandingForProduct(
      config(
        { otra: landing(), guia: landing() },
        "guia | Guía | article\notra | Otra | article",
      ),
      "camiseta",
      "es",
    );

    expect(link?.slug).toBe("otra");
  });
});
