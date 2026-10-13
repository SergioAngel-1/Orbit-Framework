import { describe, expect, it } from "vitest";
import { CO_PACK, FALLBACK_ES_PACK } from "../../../tests/helpers/geo-pack";
import { GENERIC_PACK } from "./generic";
import {
  applyGeoPack,
  checkAddress,
  divisionForCity,
  divisionOptionsForCity,
  findDivision,
  normalizePhoneForPack,
  packCountry,
} from "./address";

describe("packCountry", () => {
  it("el país del paquete, o el pedido si es el de reserva", () => {
    expect(packCountry(CO_PACK)).toBe("CO");
    expect(packCountry(FALLBACK_ES_PACK)).toBe("ES");
    expect(packCountry(GENERIC_PACK)).toBe("");
  });
});

describe("findDivision", () => {
  it.each([
    ["CO-ANT", "CO-ANT"],
    ["co-ant", "CO-ANT"],
    ["Antioquia", "CO-ANT"],
    ["  ANTIOQUIA ", "CO-ANT"],
    ["ANT", "CO-ANT"],
    ["bogota d.c.", "CO-DC"],
    ["Bogotá D C", "CO-DC"],
    ["narino", "CO-NAR"],
  ])("«%s» es %s", (raw, code) => {
    expect(findDivision(CO_PACK, raw)?.code).toBe(code);
  });

  it("no inventa una división", () => {
    expect(findDivision(CO_PACK, "Atlantis")).toBeUndefined();
    expect(findDivision(CO_PACK, "")).toBeUndefined();
    expect(findDivision(CO_PACK, "DC ")).toBe(findDivision(CO_PACK, "dc"));
  });
});

describe("ciudades", () => {
  it("una ciudad conocida tiene una sola división, sin importar tildes ni mayúsculas", () => {
    expect(divisionForCity(CO_PACK, "Bogotá")).toBe("CO-DC");
    expect(divisionForCity(CO_PACK, " bogota ")).toBe("CO-DC");
    expect(divisionForCity(CO_PACK, "Chia")).toBe("CO-CUN");
    expect(divisionOptionsForCity(CO_PACK, "Medellín").map((d) => d.code)).toEqual([
      "CO-ANT",
    ]);
  });

  it("otra ciudad admite todas las divisiones", () => {
    expect(divisionForCity(CO_PACK, "Pasto")).toBeUndefined();
    expect(divisionOptionsForCity(CO_PACK, "Pasto")).toHaveLength(4);
    expect(divisionOptionsForCity(CO_PACK, "")).toHaveLength(4);
  });

  it("un paquete sin `cityDivision` no ata la ciudad a la división", () => {
    const pack = { ...CO_PACK, address: { ...CO_PACK.address, cityDivision: false } };
    expect(divisionForCity(pack, "Bogotá")).toBeUndefined();
    expect(divisionOptionsForCity(pack, "Bogotá")).toHaveLength(4);
  });
});

describe("normalizePhoneForPack", () => {
  it("antepone el prefijo del paquete a un número nacional", () => {
    expect(normalizePhoneForPack(CO_PACK, "300 123 4567")).toBe("+573001234567");
    expect(normalizePhoneForPack(CO_PACK, "(300) 123-4567")).toBe("+573001234567");
  });

  it("un número con prefijo se queda con él", () => {
    expect(normalizePhoneForPack(CO_PACK, "+34 600 111 222")).toBe("+34600111222");
    expect(normalizePhoneForPack(CO_PACK, "0034600111222")).toBe("+34600111222");
  });

  it("nunca inventa un número: lo que no cuadra se deja como lo escribió", () => {
    expect(normalizePhoneForPack(CO_PACK, "12345")).toBe("12345");
    expect(normalizePhoneForPack(CO_PACK, "llamar a Ana")).toBe("llamar a Ana");
    expect(normalizePhoneForPack(CO_PACK, "")).toBe("");
  });

  it("sin prefijo en el paquete no se antepone nada", () => {
    expect(normalizePhoneForPack(FALLBACK_ES_PACK, "600111222")).toBe("600111222");
  });
});

describe("checkAddress", () => {
  const base = { city: "Pasto", country: "CO" };

  it("resuelve el nombre de la división a su código", () => {
    const r = checkAddress(CO_PACK, { ...base, state: "Nariño" });
    expect(r.issues).toEqual([]);
    expect(r.state).toBe("CO-NAR");
  });

  it("una ciudad conocida fija la división si no se indicó", () => {
    expect(checkAddress(CO_PACK, { city: "Bogotá", country: "CO" })).toMatchObject({
      state: "CO-DC",
      issues: [],
    });
  });

  it("rechaza una división que no corresponde a la ciudad (no la pisa en silencio)", () => {
    const r = checkAddress(CO_PACK, {
      city: "Bogotá",
      state: "Antioquia",
      country: "CO",
    });
    expect(r.issues).toEqual([
      { field: "state", message: "Departamento no corresponde a la ciudad" },
    ]);
  });

  it("rechaza una división desconocida", () => {
    const r = checkAddress(CO_PACK, { ...base, state: "Atlantis" });
    expect(r.issues).toEqual([{ field: "state", message: "Departamento no válido" }]);
  });

  it("exige la división si es obligatoria y no hay ciudad que la fije; nunca usa una por defecto", () => {
    const r = checkAddress(CO_PACK, base);
    expect(r.issues).toEqual([{ field: "state", message: "Departamento requerido" }]);
    expect(r.state).toBe("");
  });

  it("con la división opcional, vacía es válido, pero escrita debe ser real", () => {
    const pack = {
      ...CO_PACK,
      address: { ...CO_PACK.address, division: "optional" as const },
    };
    expect(checkAddress(pack, base).issues).toEqual([]);
    expect(checkAddress(pack, { ...base, state: "Atlantis" }).issues).toHaveLength(1);
  });

  it("exige el código postal solo cuando el paquete lo pide", () => {
    const pack = {
      ...CO_PACK,
      address: { ...CO_PACK.address, postcode: "required" as const },
    };
    expect(checkAddress(pack, { ...base, state: "CO-NAR" }).issues).toEqual([
      { field: "postcode", message: "Código postal requerido" },
    ]);
    expect(checkAddress(CO_PACK, { ...base, state: "CO-NAR" }).issues).toEqual([]);
  });

  it("una dirección de otro país es texto libre", () => {
    const r = checkAddress(CO_PACK, { city: "Madrid", state: "Madrid", country: "ES" });
    expect(r).toMatchObject({ state: "Madrid", issues: [] });
  });

  it("el paquete de reserva deja el estado tal cual", () => {
    expect(
      checkAddress(FALLBACK_ES_PACK, {
        city: "Madrid",
        state: " Madrid ",
        country: "ES",
      }),
    ).toMatchObject({ state: "Madrid", issues: [] });
    expect(checkAddress(GENERIC_PACK, { state: "Lo que sea" }).state).toBe(
      "Lo que sea",
    );
  });

  it("un paquete que no pide división (`none`) la vacía", () => {
    const pack = {
      ...CO_PACK,
      address: { ...CO_PACK.address, division: "none" as const },
    };
    expect(checkAddress(pack, { ...base, state: "Antioquia" }).state).toBe("");
  });
});

describe("applyGeoPack", () => {
  it("normaliza cada dirección y reporta los problemas por campo con nombre", () => {
    const { addresses, details } = applyGeoPack(CO_PACK, {
      billing_address: { city: "Medellín", country: "CO", phone: "3001234567" },
      shipping_address: { city: "Pasto", country: "CO", state: "Atlantis" },
    });

    expect(addresses.billing_address).toMatchObject({
      state: "CO-ANT",
      phone: "+573001234567",
    });
    expect(details).toEqual({ "shipping_address.state": ["Departamento no válido"] });
  });

  it("no añade claves que no venían, y salta las direcciones ausentes", () => {
    const { addresses } = applyGeoPack(FALLBACK_ES_PACK, {
      billing: { city: "Madrid" },
      shipping: undefined,
    });

    expect(addresses.billing).toEqual({ city: "Madrid" });
    expect("shipping" in addresses).toBe(false);
  });
});
