import { z } from "zod";
import type { GeoPack } from "./types";

const text = (max: number) => z.string().trim().min(1).max(max);

const packSchema = z.object({
  country: z.string().regex(/^([A-Z]{2}|generic)$/),
  name: text(120),
  fallback: z.boolean(),
  requested: z.string().max(20).default(""),
  dialCode: z
    .string()
    .regex(/^(\+[1-9]\d{0,3})?$/)
    .default(""),
  divisionLabel: text(120),
  address: z.object({
    division: z.enum(["required", "optional", "free", "none"]),
    postcode: z.enum(["required", "optional", "none"]),
    cityDivision: z.boolean(),
  }),
  divisions: z.array(z.object({ code: text(20), name: text(120) })).max(500),
  cities: z
    .array(z.object({ name: text(120), division: z.string().max(20) }))
    .max(5000),
  documentTypes: z
    .array(z.object({ code: text(20), label: text(120), pattern: text(200) }))
    .max(50),
});

/**
 * Valida lo que devuelve `/wp-json/hwe/v1/geo`. `null` si no tiene la forma del
 * contrato: quien lo lee cae al paquete genérico y lo registra.
 */
export function normalizeGeoPack(raw: unknown): GeoPack | null {
  const parsed = packSchema.safeParse(raw);
  return parsed.success ? parsed.data : null;
}
