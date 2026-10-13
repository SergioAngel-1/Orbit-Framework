import type { ShippingMethod } from "./types";

/** Normaliza el payload crudo de `GET /wp-json/hwe-shipping/v1/methods`. */
export function normalizeShippingMethods(raw: unknown): ShippingMethod[] {
  if (!Array.isArray(raw)) return [];

  const out: ShippingMethod[] = [];
  for (const entry of raw) {
    if (!entry || typeof entry !== "object") continue;
    const { id, name, added_value } = entry as Record<string, unknown>;
    if (typeof id !== "number" || !Number.isInteger(id) || id <= 0) continue;
    if (typeof name !== "string" || name.trim() === "") continue;
    const addedValue =
      typeof added_value === "number" && Number.isFinite(added_value) && added_value > 0
        ? added_value
        : 0;
    out.push({ id, name: name.trim(), addedValue });
  }
  return out;
}
