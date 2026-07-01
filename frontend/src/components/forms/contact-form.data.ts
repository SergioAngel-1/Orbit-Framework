import type { SelectOption } from "@/components/ui";

// Categorías de PQR y países: valores por defecto genéricos del framework
// (no dependen de la marca/instancia). Los datos propios de cada instancia
// (email, teléfono, sedes) se pasan como props a <ContactForm/> — ver
// contact-form.tsx — normalmente alimentados desde getSiteConfig() en el
// Server Component que renderiza el formulario.

export const REQUEST_TYPES: readonly SelectOption[] = [
  { value: "peticion", label: "Petición o solicitud" },
  { value: "queja", label: "Queja" },
  { value: "reclamo", label: "Reclamo" },
  { value: "sugerencia", label: "Sugerencia" },
  { value: "felicitacion", label: "Felicitación" },
  { value: "otro", label: "Otro" },
] as const;

export type RequestTypeValue = (typeof REQUEST_TYPES)[number]["value"];

/** Sede/sucursal física opcional (cadenas con varios puntos de venta). */
export interface Branch {
  name: string;
  address: string;
  phone: string;
  hours: string;
  mapUrl: string;
}

export interface Country {
  code: string;
  country: string;
  name: string;
}

export const COUNTRIES: readonly Country[] = [
  { code: "+57", country: "CO", name: "Colombia" },
  { code: "+52", country: "MX", name: "México" },
  { code: "+54", country: "AR", name: "Argentina" },
  { code: "+56", country: "CL", name: "Chile" },
  { code: "+51", country: "PE", name: "Perú" },
  { code: "+593", country: "EC", name: "Ecuador" },
  { code: "+58", country: "VE", name: "Venezuela" },
  { code: "+591", country: "BO", name: "Bolivia" },
  { code: "+595", country: "PY", name: "Paraguay" },
  { code: "+598", country: "UY", name: "Uruguay" },
  { code: "+1", country: "US", name: "Estados Unidos" },
  { code: "+1", country: "CA", name: "Canadá" },
  { code: "+34", country: "ES", name: "España" },
  { code: "+55", country: "BR", name: "Brasil" },
  { code: "+44", country: "GB", name: "Reino Unido" },
] as const;
