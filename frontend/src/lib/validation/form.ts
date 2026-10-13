import type { ZodType } from "zod";

// ============================================================================
//  Validación de formularios en cliente reutilizando los esquemas Zod reales
//  del BFF (lib/validation/*). Así el formulario valida exactamente lo mismo
//  que el endpoint, con mensajes por campo listos para <Input error>.
// ============================================================================

export type FieldErrors<T> = Partial<Record<keyof T & string, string>>;

export type ValidationResult<T> =
  | { success: true; data: T; errors: null }
  | { success: false; data: null; errors: FieldErrors<T> };

/**
 * Valida `data` contra un esquema Zod y devuelve, si falla, el primer mensaje
 * de error por campo (mapa `campo -> mensaje`) apto para pintar bajo cada input.
 */
export function validateForm<T>(
  schema: ZodType<T>,
  data: unknown,
): ValidationResult<T> {
  const result = schema.safeParse(data);
  if (result.success) {
    return { success: true, data: result.data, errors: null };
  }

  const errors: FieldErrors<T> = {};
  for (const issue of result.error.issues) {
    const key = issue.path[0];
    // Solo el primer error de cada campo (el más relevante para el usuario).
    if (typeof key === "string" && !(key in errors)) {
      errors[key as keyof T & string] = issue.message;
    }
  }

  return { success: false, data: null, errors };
}
