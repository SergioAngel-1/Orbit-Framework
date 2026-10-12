import { describe, expect, it } from "vitest";
import { parseWebhookOrderId } from "./order-id";

// El `id` de un webhook de pedido acaba interpolado en una clave de Redis
// (`order:status:<id>`) y en los efectos del pedido: tiene que ser un id de
// WooCommerce, no «lo que venga».
describe("parseWebhookOrderId", () => {
  it.each([
    [1234, 1234],
    ["1234", 1234],
    [1, 1],
  ])("acepta %j como %j", (value, expected) => {
    expect(parseWebhookOrderId(value)).toBe(expected);
  });

  it.each([
    ["ausente", undefined],
    ["nulo", null],
    ["cero", 0],
    ["negativo", -4],
    ["decimal", 1.5],
    ["NaN", Number.NaN],
    ["infinito", Number.POSITIVE_INFINITY],
    ["fuera del rango entero seguro", 2 ** 60],
    ["texto no numérico", "abc"],
    ["texto con ruta", "../../etc/passwd"],
    ["texto con separador de clave de Redis", "12:otra"],
    ["texto con espacios", " 12 "],
    ["texto vacío", ""],
    ["cero a la izquierda", "0012"],
    ["texto desmesurado", "9".repeat(40)],
    ["booleano", true],
    ["objeto", { id: 1 }],
    ["lista", [1]],
  ])("rechaza %s", (_caso, value) => {
    expect(parseWebhookOrderId(value)).toBeNull();
  });
});
