import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { LoyaltyConfig } from "@/lib/config/types";
import { formatPoints, pointsToMoney } from "./format";

/** Config del módulo con los campos que toca cada prueba. */
function config(overrides: Partial<LoyaltyConfig> = {}): LoyaltyConfig {
  return { ...CONFIG_DEFAULTS.loyalty, ...overrides };
}

describe("pointsToMoney", () => {
  it("convierte con el valor vigente de la unidad", () => {
    expect(pointsToMoney(100, config({ point_value: "0.01" }))).toBeCloseTo(1, 10);
    expect(pointsToMoney(250, config({ point_value: "0.02" }))).toBeCloseTo(5, 10);
  });

  it("admite cargos (importes negativos)", () => {
    expect(pointsToMoney(-100, config({ point_value: "0.01" }))).toBeCloseTo(-1, 10);
  });

  it("sin un valor válido no inventa dinero", () => {
    expect(pointsToMoney(100, config({ point_value: "" }))).toBe(0);
    expect(pointsToMoney(100, config({ point_value: "no-es-un-numero" }))).toBe(0);
    expect(pointsToMoney(100, config({ point_value: "-1" }))).toBe(0);
  });

  it("normaliza un valor con comas o espacios", () => {
    expect(pointsToMoney(100, config({ point_value: " 0,25 " }))).toBeCloseTo(25, 10);
  });
});

describe("formatPoints", () => {
  it("usa el singular con una unidad", () => {
    expect(
      formatPoints(
        1,
        config({
          currency_name: "estrella",
          currency_name_plural: "estrellas",
          currency_icon: "https://cdn.example/star.svg",
        }),
      ),
    ).toEqual({ text: "1 estrella", icon: "https://cdn.example/star.svg" });
  });

  it("usa el plural con cualquier otra cantidad", () => {
    const unit = config({
      currency_name: "estrella",
      currency_name_plural: "estrellas",
      currency_icon: "",
    });

    expect(formatPoints(0, unit)).toEqual({ text: "0 estrellas", icon: null });
    expect(formatPoints(2, unit)).toEqual({ text: "2 estrellas", icon: null });
    expect(formatPoints(-1, unit)).toEqual({ text: "-1 estrella", icon: null });
  });

  it("no devuelve icono si la configuración no trae uno", () => {
    expect(formatPoints(5, config({ currency_icon: "   " })).icon).toBeNull();
  });

  it("respeta el idioma al agrupar la cantidad", () => {
    const unit = config({
      currency_name: "estrella",
      currency_name_plural: "estrellas",
    });

    expect(formatPoints(1234, unit, "es").text).toBe("1234 estrellas");
    expect(formatPoints(1234, unit, "en").text).toBe("1,234 estrellas");
  });
});
