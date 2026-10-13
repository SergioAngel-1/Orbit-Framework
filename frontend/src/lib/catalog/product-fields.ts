import type { ProductFieldDeclaration, ProductFieldValues } from "@/lib/instance/types";

// ============================================================================
//  Campos de producto declarativos (mu-plugin `hwe-product-fields`).
//
//  La instancia los declara en el backend con `hwe_product_fields` (clave,
//  etiqueta, tipo, si es traducible y en qué caja va); WPGraphQL los expone como
//  `Product.hweFields(locale)`, una lista de pares `{ key, value }` con los que
//  tienen valor y ya resueltos al idioma. Aquí se leen:
//
//    · `readProductFields` convierte la lista en un mapa clave → texto;
//    · los lectores tipados (`productFieldNumber`, `productFieldBoolean`) leen el
//      valor según el tipo declarado.
//
//  El tipo de `product.fields` lo pone la instancia con *module augmentation* de
//  `ProductFieldValues` (ver `lib/instance/types.ts`). Módulo PURO.
// ============================================================================

/** Un par tal como lo devuelve WPGraphQL. */
export interface RawProductField {
  key: string;
  value: string;
}

export type ProductFields = ProductFieldValues & Record<string, string | undefined>;

/**
 * Mapa clave → valor de lo que devuelve `hweFields`. Descarta lo que no es un par válido y los
 * valores vacíos; ante una clave repetida manda la última.
 */
export function readProductFields(
  raw: readonly (RawProductField | null | undefined)[] | null | undefined,
): ProductFields {
  const out: Record<string, string> = {};
  for (const pair of raw ?? []) {
    if (!pair || typeof pair.key !== "string" || typeof pair.value !== "string")
      continue;
    if (pair.key === "" || pair.value.trim() === "") continue;
    out[pair.key] = pair.value;
  }
  return out as ProductFields;
}

/** El valor de un campo como número, o `null` si no hay valor o no es un número. */
export function productFieldNumber(fields: ProductFields, key: string): number | null {
  const raw = fields[key];
  if (raw === undefined || raw.trim() === "") return null;
  const value = Number(raw);
  return Number.isFinite(value) ? value : null;
}

/** Un campo `boolean`: `true` solo si vale `"1"`. */
export function productFieldBoolean(fields: ProductFields, key: string): boolean {
  return fields[key] === "1";
}

/**
 * Los campos con valor, en el orden de la declaración y con su etiqueta (los que no declaran
 * etiqueta no se listan: no hay con qué rotularlos). Para listas genéricas de «ficha técnica».
 */
export function labelledProductFields(
  fields: ProductFields,
  declarations: Record<string, ProductFieldDeclaration> | undefined,
): {
  key: string;
  label: string;
  type: ProductFieldDeclaration["type"];
  value: string;
}[] {
  return Object.entries(declarations ?? {}).flatMap(([key, declaration]) => {
    const value = fields[key];
    if (value === undefined || !declaration.label) return [];
    return [{ key, label: declaration.label, type: declaration.type, value }];
  });
}
