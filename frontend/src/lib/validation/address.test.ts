import { describe, expect, it } from "vitest";
import { CO_PACK, FALLBACK_ES_PACK } from "../../../tests/helpers/geo-pack";
import { GENERIC_PACK } from "@/lib/geo/generic";
import {
  addressDeleteSchema,
  buildAddressSchema,
  buildAddressUpdateSchema,
  locationSchema,
} from "./address";

const MINIMAL = { address_1: "Calle 1 # 2-3", city: "Pasto" };

describe("buildAddressSchema", () => {
  it("el país por defecto es el de la tienda, y el estado sale como código", () => {
    const r = buildAddressSchema(CO_PACK).safeParse({ ...MINIMAL, state: "Nariño" });

    expect(r.success).toBe(true);
    expect(r.data).toMatchObject({ country: "CO", state: "CO-NAR", phone: "" });
  });

  it("normaliza el teléfono con el prefijo del paquete", () => {
    const r = buildAddressSchema(CO_PACK).parse({
      ...MINIMAL,
      state: "CO-NAR",
      phone: "300 123 4567",
    });
    expect(r.phone).toBe("+573001234567");
  });

  it("una ciudad conocida fija el departamento, y uno incoherente es un error del campo", () => {
    expect(
      buildAddressSchema(CO_PACK).parse({ ...MINIMAL, city: "Bogotá" }).state,
    ).toBe("CO-DC");

    const r = buildAddressSchema(CO_PACK).safeParse({
      ...MINIMAL,
      city: "Bogotá",
      state: "Antioquia",
    });
    expect(r.success).toBe(false);
    expect(r.error?.flatten().fieldErrors.state).toEqual([
      "Departamento no corresponde a la ciudad",
    ]);
  });

  it("sin departamento y sin ciudad que lo fije, falla en vez de inventar uno", () => {
    const r = buildAddressSchema(CO_PACK).safeParse(MINIMAL);
    expect(r.success).toBe(false);
    expect(r.error?.flatten().fieldErrors.state).toEqual(["Departamento requerido"]);
  });

  it("un país sin paquete propio usa el pedido como país y deja el estado libre", () => {
    const r = buildAddressSchema(FALLBACK_ES_PACK).parse({
      ...MINIMAL,
      state: "Madrid",
    });
    expect(r).toMatchObject({ country: "ES", state: "Madrid" });
  });

  it("con el paquete genérico no hay país por defecto: hay que indicarlo", () => {
    const schema = buildAddressSchema(GENERIC_PACK);
    expect(schema.safeParse(MINIMAL).success).toBe(false);
    expect(schema.safeParse({ ...MINIMAL, country: "PE" }).success).toBe(true);
  });

  it("mantiene los mínimos de siempre", () => {
    const schema = buildAddressSchema(CO_PACK);
    expect(schema.safeParse({ city: "Pasto", state: "CO-NAR" }).success).toBe(false);
    expect(schema.safeParse({ address_1: "x", state: "CO-NAR" }).success).toBe(false);
    expect(
      schema.safeParse({ ...MINIMAL, state: "CO-NAR", country: "COL" }).success,
    ).toBe(false);
  });
});

describe("buildAddressUpdateSchema / addressDeleteSchema", () => {
  it("la actualización valida la dirección con el mismo paquete", () => {
    const schema = buildAddressUpdateSchema(CO_PACK);
    expect(
      schema.safeParse({ index: 0, address: { ...MINIMAL, state: "CO-NAR" } }).success,
    ).toBe(true);
    expect(schema.safeParse({ index: 0, address: MINIMAL }).success).toBe(false);
    expect(
      schema.safeParse({ index: -1, address: { ...MINIMAL, state: "CO-NAR" } }).success,
    ).toBe(false);
  });

  it("borrar pide un índice entero no negativo", () => {
    expect(addressDeleteSchema.safeParse({ index: 2 }).success).toBe(true);
    expect(addressDeleteSchema.safeParse({ index: 1.5 }).success).toBe(false);
  });
});

describe("locationSchema (pin del cliente)", () => {
  it.each([
    ["Bogotá", { lat: 4.6533, lng: -74.0836 }],
    ["límite norte", { lat: 90, lng: 0.5 }],
    ["límite sur", { lat: -90, lng: 0.5 }],
    ["antimeridiano", { lat: 10, lng: 180 }],
    ["antimeridiano negativo", { lat: 10, lng: -180 }],
  ])("acepta %s", (_caso, location) => {
    expect(locationSchema.safeParse(location).success).toBe(true);
  });

  it("rechaza 0,0: es el valor por defecto de un cliente que no ha marcado nada", () => {
    expect(locationSchema.safeParse({ lat: 0, lng: 0 }).success).toBe(false);
  });

  it.each([
    ["NaN", { lat: NaN, lng: -74 }],
    ["Infinity", { lat: 4, lng: Infinity }],
    ["latitud fuera de rango", { lat: 91, lng: -74 }],
    ["latitud negativa fuera de rango", { lat: -90.1, lng: -74 }],
    ["longitud fuera de rango", { lat: 4, lng: 181 }],
    ["cadena numérica (el cliente envía números)", { lat: "4.6533", lng: "-74.0836" }],
    ["falta la longitud", { lat: 4.6 }],
    ["nulo", null],
    ["lista", [4.6, -74.0]],
  ])("rechaza %s", (_caso, location) => {
    expect(locationSchema.safeParse(location).success).toBe(false);
  });

  it("buildAddressSchema conserva una ubicación válida y rechaza una basura", () => {
    const ok = buildAddressSchema(CO_PACK).safeParse({
      ...MINIMAL,
      state: "CO-NAR",
      location: { lat: 1.2136, lng: -77.2811 },
    });
    expect(ok.success).toBe(true);
    expect(ok.data?.location).toEqual({ lat: 1.2136, lng: -77.2811 });

    const basura = buildAddressSchema(CO_PACK).safeParse({
      ...MINIMAL,
      state: "CO-NAR",
      location: { lat: 0, lng: 0 },
    });
    expect(basura.success).toBe(false);
  });

  it("la ubicación es opcional en la dirección", () => {
    const r = buildAddressSchema(CO_PACK).safeParse({ ...MINIMAL, state: "CO-NAR" });
    expect(r.success).toBe(true);
    expect(r.data).not.toHaveProperty("location");
  });
});
