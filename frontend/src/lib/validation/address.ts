import { z } from "zod";
import { checkAddress, packCountry } from "@/lib/geo/address";
import type { GeoPack } from "@/lib/geo/types";

/**
 * Pin del cliente: coordenadas en grados decimales. Son números JSON, no cadenas: un valor
 * mal formado se rechaza (422 en el BFF) y nunca llega a WordPress. `(0, 0)` es el valor por
 * defecto de un mapa sin marcar, no una ubicación real, así que también se rechaza.
 */
export const locationSchema = z
  .object({
    lat: z.number().finite().min(-90).max(90),
    lng: z.number().finite().min(-180).max(180),
  })
  .refine((point) => point.lat !== 0 || point.lng !== 0, {
    message: "Ubicación inválida.",
  });

export type Location = z.infer<typeof locationSchema>;

/**
 * Esquema de una dirección de la libreta, según el paquete de país de la tienda
 * (`getGeoPack()`): el país por defecto es el de la tienda, la división se valida
 * contra la lista del paquete (y contra la ciudad si el paquete lo pide), y el
 * estado y el teléfono salen normalizados.
 *
 * Con el paquete genérico no hay país por defecto: el cliente debe indicarlo.
 */
export function buildAddressSchema(pack: GeoPack) {
  const own = packCountry(pack);
  const countryMessage = "País debe ser ISO 2 (ej. CO)";
  const country = own
    ? z.string().length(2, countryMessage).default(own)
    : z.string().length(2, countryMessage);

  return z
    .object({
      first_name: z.string().max(100).optional().default(""),
      last_name: z.string().max(100).optional().default(""),
      company: z.string().max(100).optional().default(""),
      address_1: z.string().min(1, "Dirección requerida").max(300),
      address_2: z.string().max(300).optional().default(""),
      city: z.string().min(1, "Ciudad requerida").max(100),
      state: z.string().max(100).optional().default(""),
      postcode: z.string().max(20).optional().default(""),
      country,
      phone: z.string().max(30).optional().default(""),
      is_default: z.boolean().optional().default(false),
      label: z.string().max(50).optional().default(""),
      location: locationSchema.optional(),
    })
    .superRefine((address, ctx) => {
      for (const issue of checkAddress(pack, address).issues) {
        ctx.addIssue({ code: "custom", message: issue.message, path: [issue.field] });
      }
    })
    .transform((address) => {
      const { state, phone } = checkAddress(pack, address);
      return { ...address, state, phone };
    });
}

export type AddressInput = z.infer<ReturnType<typeof buildAddressSchema>>;

/** A qué dirección apunta una petición: `id` (estable) o `index` (forma anterior). */
const addressTarget = {
  id: z
    .string()
    .regex(/^[A-Za-z0-9_-]{1,64}$/)
    .optional(),
  index: z.number().int().min(0).optional(),
};

const hasTarget = (target: { id?: string; index?: number }) =>
  target.id !== undefined || target.index !== undefined;
const MISSING_TARGET = { message: "Falta la dirección (`id` o `index`).", path: ["id"] };

export function buildAddressUpdateSchema(pack: GeoPack) {
  return z
    .object({ ...addressTarget, address: buildAddressSchema(pack) })
    .refine(hasTarget, MISSING_TARGET);
}

export const addressDeleteSchema = z
  .object(addressTarget)
  .refine(hasTarget, MISSING_TARGET);
