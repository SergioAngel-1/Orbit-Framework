// ============================================================================
//  Paquete de país (`GET /wp-json/hwe/v1/geo`, mu-plugin `hwe-geo`).
//
//  Lo que un formulario de dirección o de documento necesita saber de un país:
//  divisiones administrativas, ciudades con su división, tipos de documento,
//  prefijo telefónico y política de la dirección. Contrato: docs/FRONTEND_CONNECT.md.
// ============================================================================

export interface GeoDivision {
  /** Código que exige WooCommerce para el estado (p. ej. `CO-ANT`). */
  code: string;
  name: string;
}

export interface GeoCity {
  name: string;
  /** Código de la división a la que pertenece (vacío si el paquete no tiene divisiones). */
  division: string;
}

export interface GeoDocumentType {
  code: string;
  label: string;
  /**
   * Expresión regular sin delimitadores contra el número NORMALIZADO (sin
   * espacios, puntos ni guiones, en mayúsculas).
   */
  pattern: string;
}

/** `required` = lista cerrada obligatoria; `optional` = lista cerrada opcional; `free` = texto libre; `none` = no se pide. */
export type DivisionMode = "required" | "optional" | "free" | "none";
export type PostcodeMode = "required" | "optional" | "none";

export interface GeoPack {
  /** ISO 3166-1 alfa-2 del paquete, o `generic` si es el de reserva. */
  country: string;
  name: string;
  /** El país de la tienda no tiene paquete y se sirve el genérico (texto libre). */
  fallback: boolean;
  /** País que se pidió (`ecommerce.country`); útil cuando `fallback` es `true`. */
  requested: string;
  /** Prefijo telefónico (`+57`) o vacío. */
  dialCode: string;
  /** Cómo llamar a la división en la interfaz («Departamento», «Provincia»…). */
  divisionLabel: string;
  address: {
    division: DivisionMode;
    postcode: PostcodeMode;
    /** Una ciudad de la lista solo admite su división. */
    cityDivision: boolean;
  };
  divisions: GeoDivision[];
  cities: GeoCity[];
  documentTypes: GeoDocumentType[];
}
