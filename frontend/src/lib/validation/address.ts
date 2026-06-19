import { z } from "zod";

export const addressSchema = z.object({
  first_name: z.string().max(100).optional().default(""),
  last_name: z.string().max(100).optional().default(""),
  company: z.string().max(100).optional().default(""),
  address_1: z.string().min(1, "Dirección requerida").max(300),
  address_2: z.string().max(300).optional().default(""),
  city: z.string().min(1, "Ciudad requerida").max(100),
  state: z.string().max(100).optional().default(""),
  postcode: z.string().max(20).optional().default(""),
  country: z.string().length(2, "País debe ser ISO 2 (ej. ES)").default("ES"),
  phone: z.string().max(30).optional().default(""),
  is_default: z.boolean().optional().default(false),
  label: z.string().max(50).optional().default(""),
});

export type AddressInput = z.infer<typeof addressSchema>;

export const addressUpdateSchema = z.object({
  index: z.number().int().min(0),
  address: addressSchema,
});

export const addressDeleteSchema = z.object({
  index: z.number().int().min(0),
});
