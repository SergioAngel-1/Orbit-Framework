import { describe, expect, it } from "vitest";
import { checkoutSeedFromCustomer } from "./customer-seed";

const ADDRESS = {
  first_name: "",
  last_name: "",
  company: "",
  address_1: "Calle 1",
  address_2: "",
  city: "Valencia",
  state: "",
  postcode: "",
  country: "ES",
  phone: "",
  is_default: true,
  label: "Casa",
};

describe("checkoutSeedFromCustomer", () => {
  it("reúne perfil y libreta de una sola lectura del cliente", () => {
    const seed = checkoutSeedFromCustomer(
      {
        email: " ana@example.com ",
        first_name: "Ana",
        last_name: "",
        billing: { last_name: "Ruiz", phone: "+34600111222", city: "Madrid" },
        shipping: { city: "Sevilla", country: "ES" },
        meta_data: [
          { key: "hwe_addresses", value: JSON.stringify([ADDRESS]) },
          { key: "otro_meta", value: "no debe viajar" },
        ],
      },
      "ES",
    );

    expect(seed).toEqual({
      first_name: "Ana",
      last_name: "Ruiz",
      email: "ana@example.com",
      phone: "+34600111222",
      city: "Madrid",
      state: "",
      country: "ES",
      addresses: [ADDRESS],
    });
    // Solo lo que el formulario usa: el resto del meta no sale del servidor.
    expect(JSON.stringify(seed)).not.toContain("no debe viajar");
  });

  it("un cliente sin datos da un punto de partida vacío, con el país de la tienda", () => {
    const seed = checkoutSeedFromCustomer(
      { email: "", first_name: "", last_name: "", billing: {}, shipping: {} },
      "CO",
    );

    expect(seed).toMatchObject({
      first_name: "",
      email: "",
      city: "",
      country: "CO",
      addresses: [],
    });
  });

  it("el país de la dirección del cliente gana al de la tienda", () => {
    const seed = checkoutSeedFromCustomer(
      {
        email: "",
        first_name: "",
        last_name: "",
        billing: { country: "PT" },
        shipping: {},
      },
      "ES",
    );

    expect(seed.country).toBe("PT");
  });
});
