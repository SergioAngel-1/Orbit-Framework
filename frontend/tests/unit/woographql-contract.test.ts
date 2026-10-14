import { describe, expect, it } from "vitest";
import { PRODUCTS_QUERY } from "@/lib/woocommerce/queries";

// ============================================================================
//  Contrato con WooGraphQL ≥ 1.0 (esquema real, volcado por introspección con
//  WPGraphQL 2.17–2.23 y WooGraphQL 1.0.2–1.0.3).
//
//  Los tests con `fetchGraphQL` simulado y el stub de CI devuelven lo que se les
//  pide, así que no ven una consulta que el servidor RECHAZARÍA: en v2.0.0 el
//  listado de la tienda pedía `ProductStockStatusEnum` / `ProductsOrderbyEnum`
//  (nombres de WooGraphQL 0.x) y daba «Unknown type» contra el WooGraphQL fijado.
//  Al subir WooGraphQL, vuelve a volcar estas listas.
// ============================================================================

const SCHEMA = {
  enums: {
    StockStatusEnum: ["IN_STOCK", "ON_BACKORDER", "OUT_OF_STOCK"],
    ProductsOrderByEnum: [
      "DATE",
      "IN",
      "MENU_ORDER",
      "MODIFIED",
      "NAME",
      "NAME_IN",
      "PARENT",
      "POPULARITY",
      "PRICE",
      "RATING",
      "REVIEW_COUNT",
      "SLUG",
    ],
    OrderEnum: ["ASC", "DESC"],
  },
  scalars: ["Int", "String", "Float", "Boolean", "ID"],
};

/** `$nombre: Tipo` de la cabecera de la operación. */
function declaredVariables(query: string): Record<string, string> {
  const header = query.slice(query.indexOf("query "), query.indexOf(") {") + 1);
  return Object.fromEntries(
    [...header.matchAll(/\$(\w+):\s*(\[?\w+\]?)/g)].map((m) => [m[1]!, m[2]!]),
  );
}

describe("PRODUCTS_QUERY frente al esquema de WooGraphQL", () => {
  const vars = declaredVariables(PRODUCTS_QUERY);

  it("todos los tipos declarados existen en el esquema", () => {
    const known = new Set([...Object.keys(SCHEMA.enums), ...SCHEMA.scalars]);
    for (const [name, type] of Object.entries(vars)) {
      expect(known.has(type.replace(/[[\]]/g, "")), `$${name}: ${type}`).toBe(true);
    }
  });

  it("los filtros de precio son Float y el de stock, [StockStatusEnum]", () => {
    expect(vars.minPrice).toBe("Float");
    expect(vars.maxPrice).toBe("Float");
    expect(vars.stockStatus).toBe("[StockStatusEnum]");
    expect(vars.orderbyField).toBe("ProductsOrderByEnum");
  });

  it("el valor por defecto de orderbyField es un valor válido del enum", () => {
    const m = /\$orderbyField:\s*ProductsOrderByEnum\s*=\s*(\w+)/.exec(PRODUCTS_QUERY);
    expect(SCHEMA.enums.ProductsOrderByEnum).toContain(m![1]);
  });
});
