import { describe, expect, it } from "vitest";
import { instanceRegistry } from "@/instance/registry";
import {
  labelledProductFields,
  productFieldNumber,
  readProductFields,
} from "@/lib/catalog/product-fields";

// Los campos de producto del ejemplo: la declaración del frontend coincide con la del backend y
// `product.fields` los tipa por augmentation (`product-fields.d.ts`).

describe("campos de producto de ejemplo", () => {
  it("la declaración lista los dos campos del backend", () => {
    expect(Object.keys(instanceRegistry.productFields ?? {})).toEqual([
      "example_usage",
      "example_weight",
    ]);
    expect(instanceRegistry.productFields?.example_weight?.type).toBe("number");
  });

  it("lee los valores tipados y los rotula en el orden de la declaración", () => {
    const fields = readProductFields([
      { key: "example_weight", value: "40" },
      { key: "example_usage", value: "8 gotas" },
    ]);

    // `fields.example_usage` es `string | undefined` por la augmentation.
    const usage: string | undefined = fields.example_usage;

    expect(usage).toBe("8 gotas");
    expect(productFieldNumber(fields, "example_weight")).toBe(40);
    expect(
      labelledProductFields(fields, instanceRegistry.productFields).map((f) => f.label),
    ).toEqual(["Modo de uso", "Peso (g)"]);
  });
});
