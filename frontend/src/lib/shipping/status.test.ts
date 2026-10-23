import { describe, expect, it } from "vitest";
import { readRateDetails, readShippingStatus } from "./status";

function cart(status: unknown) {
  return { extensions: status === undefined ? {} : { hwe_shipping: status } };
}

describe("readShippingStatus", () => {
  it("sin la extensión (WordPress sin el módulo) no hay estado", () => {
    expect(readShippingStatus(cart(undefined))).toBeNull();
    expect(readShippingStatus({})).toBeNull();
    expect(readShippingStatus(null)).toBeNull();
    expect(readShippingStatus(undefined)).toBeNull();
  });

  it("lee el pin que falta: needs_location, sin datos de distancia", () => {
    expect(
      readShippingStatus(cart({ mode: "distance", status: "needs_location" })),
    ).toEqual({ mode: "distance", status: "needs_location" });
  });

  it("lee una entrega por distancia calculada, con respaldo", () => {
    expect(
      readShippingStatus(
        cart({
          mode: "distance",
          status: "ok",
          distance_km: 4.35,
          duration_min: 12,
          fallback: true,
        }),
      ),
    ).toEqual({
      mode: "distance",
      status: "ok",
      distance_km: 4.35,
      duration_min: 12,
      fallback: true,
    });
  });

  it("acepta todos los modos y estados del contrato", () => {
    for (const mode of ["fixed", "distance", "reserve", "none"]) {
      for (const status of [
        "ok",
        "needs_address",
        "needs_location",
        "out_of_coverage",
      ]) {
        expect(readShippingStatus(cart({ mode, status }))).toEqual({ mode, status });
      }
    }
  });

  it.each([
    ["modo desconocido", { mode: "express", status: "ok" }],
    ["estado desconocido", { mode: "fixed", status: "pendiente" }],
    ["sin modo", { status: "ok" }],
    ["distancia como texto", { mode: "distance", status: "ok", distance_km: "4.35" }],
    ["distancia NaN", { mode: "distance", status: "ok", distance_km: NaN }],
    ["duración negativa", { mode: "distance", status: "ok", duration_min: -3 }],
    ["respaldo como texto", { mode: "distance", status: "ok", fallback: "yes" }],
    ["no es un objeto", "ok"],
    ["lista", ["ok"]],
  ])("forma inesperada (%s) → null, sin lanzar", (_caso, status) => {
    expect(() => readShippingStatus(cart(status))).not.toThrow();
    expect(readShippingStatus(cart(status))).toBeNull();
  });
});

describe("readRateDetails", () => {
  const meta = (entries: Record<string, string>) =>
    Object.entries(entries).map(([key, value]) => ({ key, value }));

  it("una tarifa sin metas del módulo (otro método) no tiene detalle", () => {
    expect(readRateDetails({ meta_data: [] })).toBeNull();
    expect(readRateDetails({})).toBeNull();
    expect(readRateDetails(null)).toBeNull();
    expect(readRateDetails(undefined)).toBeNull();
  });

  it("una opción de ciudad fija: sin km ni respaldo", () => {
    expect(
      readRateDetails({
        meta_data: meta({ hwe_option_id: "a1b2c3", hwe_city_type: "fixed" }),
      }),
    ).toEqual({
      optionId: "a1b2c3",
      cityType: "fixed",
      distanceKm: null,
      fallback: false,
    });
  });

  it("una opción por distancia: lee los km (texto numérico) y el respaldo", () => {
    expect(
      readRateDetails({
        meta_data: meta({
          hwe_option_id: "exp1",
          hwe_city_type: "distance",
          hwe_distance_km: "4.35",
          hwe_fallback: "yes",
        }),
      }),
    ).toEqual({
      optionId: "exp1",
      cityType: "distance",
      distanceKm: 4.35,
      fallback: true,
    });
  });

  it("la reserva se reconoce por su tipo", () => {
    expect(
      readRateDetails({
        meta_data: meta({ hwe_option_id: "reserve", hwe_city_type: "reserve" }),
      }),
    ).toEqual({
      optionId: "reserve",
      cityType: "reserve",
      distanceKm: null,
      fallback: false,
    });
  });

  it.each([
    ["km NaN", "NaN"],
    ["km vacíos", ""],
    ["km negativos", "-1"],
    ["km infinitos", "Infinity"],
    ["km con basura", "4,3 km"],
  ])("por distancia con %s → distanceKm null, sin lanzar", (_caso, km) => {
    expect(
      readRateDetails({
        meta_data: meta({
          hwe_option_id: "exp1",
          hwe_city_type: "distance",
          hwe_distance_km: km,
          hwe_fallback: "no",
        }),
      }),
    ).toEqual({
      optionId: "exp1",
      cityType: "distance",
      distanceKm: null,
      fallback: false,
    });
  });

  it.each([
    ["sin id de opción", { hwe_city_type: "fixed" }],
    ["id vacío", { hwe_option_id: "", hwe_city_type: "fixed" }],
    ["tipo desconocido", { hwe_option_id: "x", hwe_city_type: "hybrid" }],
    ["sin tipo", { hwe_option_id: "x" }],
  ])("metas malformadas (%s) → null", (_caso, entries) => {
    expect(() => readRateDetails({ meta_data: meta(entries) })).not.toThrow();
    expect(readRateDetails({ meta_data: meta(entries) })).toBeNull();
  });
});
