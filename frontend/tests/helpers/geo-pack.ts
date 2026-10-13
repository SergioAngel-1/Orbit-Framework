import type { GeoPack } from "@/lib/geo/types";

/** Paquete con la forma del de Colombia, reducido a lo que los tests necesitan. */
export const CO_PACK: GeoPack = {
  country: "CO",
  name: "Colombia",
  fallback: false,
  requested: "CO",
  dialCode: "+57",
  divisionLabel: "Departamento",
  address: { division: "required", postcode: "optional", cityDivision: true },
  divisions: [
    { code: "CO-ANT", name: "Antioquia" },
    { code: "CO-CUN", name: "Cundinamarca" },
    { code: "CO-DC", name: "Bogotá D.C." },
    { code: "CO-NAR", name: "Nariño" },
  ],
  cities: [
    { name: "Bogotá", division: "CO-DC" },
    { name: "Medellín", division: "CO-ANT" },
    { name: "Chía", division: "CO-CUN" },
  ],
  documentTypes: [
    { code: "CC", label: "Cédula de ciudadanía", pattern: "^[1-9]\\d{3,9}$" },
  ],
};

/** País sin paquete propio: el de reserva, pero sabiendo qué país se pidió. */
export const FALLBACK_ES_PACK: GeoPack = {
  country: "generic",
  name: "Genérico",
  fallback: true,
  requested: "ES",
  dialCode: "",
  divisionLabel: "Provincia o estado",
  address: { division: "free", postcode: "optional", cityDivision: false },
  divisions: [],
  cities: [],
  documentTypes: [],
};
