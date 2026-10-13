import type { GeoPack } from "./types";

/**
 * Paquete de reserva: todo texto libre, sin divisiones, ciudades ni documentos.
 *
 * Es el que sirve WordPress para un país sin paquete y el que se usa aquí si
 * WordPress no responde (mismo papel que `CONFIG_DEFAULTS`). Ningún formulario
 * puede suponer nada del país con él.
 */
export const GENERIC_PACK: GeoPack = {
  country: "generic",
  name: "Genérico",
  fallback: true,
  requested: "",
  dialCode: "",
  divisionLabel: "Provincia o estado",
  address: { division: "free", postcode: "optional", cityDivision: false },
  divisions: [],
  cities: [],
  documentTypes: [],
};
