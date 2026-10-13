import { describe, expect, it } from "vitest";
import {
  cartItemDetailData,
  cartItemDetailValue,
  orderItemDetailData,
  orderItemDetailValue,
} from "@/lib/catalog/order-item-details";

// Lectura de los detalles de línea (módulo `order-item-details`): siempre por clave conocida.

describe("carrito (Store API item_data)", () => {
  const item = {
    item_data: [
      { name: "Intención", value: "Paz" },
      { name: "hwe_item_intencion", value: '{"id":7}', hidden: true },
      { name: "Talla", value: "M" },
    ],
  };

  it("lee el dato máquina por la entrada oculta de la clave", () => {
    expect(cartItemDetailData(item, "intencion")).toBe('{"id":7}');
  });

  it("devuelve null si la línea no lo lleva o no hay item_data", () => {
    expect(cartItemDetailData(item, "otra")).toBeNull();
    expect(cartItemDetailData({}, "intencion")).toBeNull();
    expect(
      cartItemDetailData({ item_data: [{ name: "hwe_item_x", value: "" }] }, "x"),
    ).toBeNull();
  });

  it("el valor visible se lee por etiqueta y nunca de una entrada oculta", () => {
    expect(cartItemDetailValue(item, "Intención")).toBe("Paz");
    expect(cartItemDetailValue(item, "hwe_item_intencion")).toBeNull();
    expect(cartItemDetailValue(item, "Inexistente")).toBeNull();
  });
});

describe("pedido (wc/v3 meta_data)", () => {
  const line = {
    meta_data: [
      { key: "Intención de uso", value: "Paz" },
      { key: "_hwe_item_intencion", value: { id: 7 } },
      { key: "_hwe_item_texto", value: "crudo" },
      { key: "_otra_interna", value: "secreto" },
    ],
  };

  it("lee el valor visible por su meta_key declarada", () => {
    expect(orderItemDetailValue(line, "Intención de uso")).toBe("Paz");
    expect(orderItemDetailValue(line, "_otra_interna")).toBe("secreto");
    expect(orderItemDetailValue(line, "nada")).toBeNull();
    expect(orderItemDetailValue({}, "Intención de uso")).toBeNull();
  });

  it("el dato máquina se lee de la meta oculta, re-serializado si ya viene decodificado", () => {
    expect(orderItemDetailData(line, "intencion")).toBe('{"id":7}');
    expect(orderItemDetailData(line, "texto")).toBe("crudo");
    expect(orderItemDetailData(line, "nada")).toBeNull();
  });
});
