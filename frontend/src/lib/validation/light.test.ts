import { describe, expect, it } from "vitest";
import { locationSchema } from "./address";
import { parseGeoPoint } from "./light";

const VALID = [
  ["Bogotá", { lat: 4.6533, lng: -74.0836 }],
  ["límite norte", { lat: 90, lng: 0.5 }],
  ["límite sur", { lat: -90, lng: 0.5 }],
  ["antimeridiano", { lat: 10, lng: 180 }],
  ["antimeridiano negativo", { lat: 10, lng: -180 }],
] as const;

const INVALID = [
  ["0,0 (mapa sin marcar)", { lat: 0, lng: 0 }],
  ["NaN", { lat: NaN, lng: -74 }],
  ["Infinity", { lat: 4, lng: Infinity }],
  ["latitud fuera de rango", { lat: 91, lng: -74 }],
  ["latitud negativa fuera de rango", { lat: -90.1, lng: -74 }],
  ["longitud fuera de rango", { lat: 4, lng: 181 }],
  ["cadena numérica", { lat: "4.6533", lng: "-74.0836" }],
  ["falta la longitud", { lat: 4.6 }],
  ["nulo", null],
  ["lista", [4.6, -74.0]],
  ["texto", "4.6533,-74.0836"],
] as const;

describe("parseGeoPoint", () => {
  it.each(VALID)("acepta %s", (_caso, location) => {
    expect(parseGeoPoint(location)).toEqual(location);
  });

  it.each(INVALID)("rechaza %s", (_caso, location) => {
    expect(parseGeoPoint(location)).toBeNull();
  });

  it("devuelve solo lat y lng: no propaga claves de más", () => {
    expect(parseGeoPoint({ lat: 4.6, lng: -74, extra: true })).toEqual({
      lat: 4.6,
      lng: -74,
    });
  });
});

describe("locationSchema y parseGeoPoint (una sola regla)", () => {
  it.each(VALID)("coinciden al aceptar %s", (_caso, location) => {
    const parsed = locationSchema.safeParse(location);
    expect(parsed.success).toBe(true);
    expect(parsed.data).toEqual(parseGeoPoint(location));
  });

  it.each(INVALID)("coinciden al rechazar %s", (_caso, location) => {
    expect(locationSchema.safeParse(location).success).toBe(false);
    expect(parseGeoPoint(location)).toBeNull();
  });
});
