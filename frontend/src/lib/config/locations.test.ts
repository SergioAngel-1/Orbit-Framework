import { describe, expect, it } from "vitest";
import { getConfiguredLocations } from "./locations";
import { CONFIG_DEFAULTS, type SiteConfig } from "./index";

function configWithLocations(
  locations: SiteConfig["legal"]["locations"],
  mobile = "",
): SiteConfig {
  return {
    ...CONFIG_DEFAULTS,
    legal: { ...CONFIG_DEFAULTS.legal, mobile, locations },
  };
}

function filledLocation(overrides = {}) {
  return {
    label: "Sede Centro",
    address: "Calle 1 # 2-3",
    city: "",
    region: "",
    postal_code: "",
    maps: "",
    phone: "",
    hours: "",
    latitude: "",
    longitude: "",
    ...overrides,
  };
}

const emptyLocations = () => ({
  location_1: filledLocation({ label: "", address: "" }),
  location_2: filledLocation({ label: "", address: "" }),
  location_3: filledLocation({ label: "", address: "" }),
  location_4: filledLocation({ label: "", address: "" }),
});

describe("getConfiguredLocations", () => {
  it("omite los slots vacíos", () => {
    const locs = getConfiguredLocations(configWithLocations(emptyLocations()));
    expect(locs).toEqual([]);
  });

  it("una sede sin teléfono hereda el del negocio", () => {
    const locations = emptyLocations();
    locations.location_1 = filledLocation();
    const [sede] = getConfiguredLocations(
      configWithLocations(locations, "300 111 2233"),
    );

    expect(sede?.name).toBe("Sede Centro");
    expect(sede?.phone).toBe("300 111 2233");
  });

  it("respeta el teléfono propio y parsea horario y coordenadas", () => {
    const locations = emptyLocations();
    locations.location_2 = filledLocation({
      label: "Norte",
      address: "Av 5",
      phone: "601 555 0100",
      hours: "Mo-Fr 08:00-18:00\nSa 09:00-13:00\n",
      latitude: "4.75",
      longitude: "-74.02",
    });
    const [sede] = getConfiguredLocations(configWithLocations(locations));

    expect(sede?.phone).toBe("601 555 0100");
    expect(sede?.hours).toEqual(["Mo-Fr 08:00-18:00", "Sa 09:00-13:00"]);
    expect(sede?.latitude).toBe(4.75);
    expect(sede?.longitude).toBe(-74.02);
  });

  it("coordenadas inválidas → null", () => {
    const locations = emptyLocations();
    locations.location_1 = filledLocation({ latitude: "no", longitude: "200" });
    const [sede] = getConfiguredLocations(configWithLocations(locations));

    expect(sede?.latitude).toBeNull();
    expect(sede?.longitude).toBeNull();
  });
});
