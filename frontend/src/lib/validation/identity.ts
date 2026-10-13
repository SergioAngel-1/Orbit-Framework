import { z } from "zod";
import type { GeoDocumentType } from "@/lib/geo/types";

// ============================================================================
//  Documento de identidad del cliente (módulo `customer-identity`).
//
//  Los tipos y sus formatos NO están aquí: salen del paquete de país de la
//  tienda (`getGeoPack().documentTypes`, mu-plugin `hwe-geo`), el mismo que usa
//  WordPress para volver a validar al escribir. El número se compara y se guarda
//  NORMALIZADO (sin espacios, puntos ni guiones, en mayúsculas), que es también
//  lo que hace único tipo + número.
// ============================================================================

export function normalizeDocumentNumber(raw: string): string {
  return raw
    .trim()
    .replace(/[\s.-]+/g, "")
    .toUpperCase();
}

export function findDocumentType(
  types: readonly GeoDocumentType[],
  code: string | null | undefined,
): GeoDocumentType | undefined {
  return code ? types.find((t) => t.code === code) : undefined;
}

/** ¿Cuadra el número (ya normalizado) con el patrón de su tipo? Un tipo desconocido, no. */
export function isValidDocument(
  types: readonly GeoDocumentType[],
  code: string,
  normalizedNumber: string,
): boolean {
  const type = findDocumentType(types, code);
  if (!type) return false;
  try {
    return new RegExp(type.pattern).test(normalizedNumber);
  } catch {
    // Un patrón que no compila no valida nada (el paquete ya lo descarta en WordPress).
    return false;
  }
}

/** Forma del documento en una petición; el contenido lo valida `checkDocument` con el paquete. */
export const documentInputSchema = z.object({
  documentType: z.string().min(1, "Tipo de documento requerido").max(20),
  documentNumber: z
    .string()
    .min(1, "Documento requerido")
    .max(30, "Documento no válido")
    .transform(normalizeDocumentNumber),
});

export type DocumentInput = z.infer<typeof documentInputSchema>;

/** Casilla de términos + privacidad + veracidad de los datos: solo vale `true`. */
export const termsAcceptedField = z.literal(
  true,
  "Debes aceptar los términos y condiciones para continuar",
);

/**
 * Valida un documento contra los tipos del paquete. Devuelve el mensaje de error
 * del campo, o `null` si es válido.
 */
export function checkDocument(
  types: readonly GeoDocumentType[],
  input: DocumentInput,
): string | null {
  if (!findDocumentType(types, input.documentType))
    return "Tipo de documento no válido";
  if (!isValidDocument(types, input.documentType, input.documentNumber)) {
    return "Número de documento no válido";
  }
  return null;
}
