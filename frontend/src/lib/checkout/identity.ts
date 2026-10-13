import { hasDocument, type CustomerIdentity } from "@/lib/customer/identity";
import type { IdentityPolicy } from "@/lib/customer/policy";
import { checkDocument, type DocumentInput } from "@/lib/validation/identity";

// ============================================================================
//  Qué documento y qué aceptación de términos lleva un pedido.
//
//  Invitado: los dos salen del formulario y son obligatorios (el documento, solo
//  si el paquete de país tiene tipos de documento).
//  Con sesión: lo que la cuenta ya tenga MANDA (el documento está bloqueado y la
//  aceptación no se vuelve a pedir); solo lo que falte sale del formulario, y eso
//  mismo se devuelve en `persist` para guardarlo en la cuenta.
//  Nunca se confía en el cliente para dar por aceptados los términos.
// ============================================================================

export interface CheckoutIdentityInput {
  identity?: DocumentInput;
  terms_accepted?: true;
}

export interface IdentityToPersist {
  documentType?: string;
  documentNumber?: string;
  termsAccepted?: true;
}

export type CheckoutIdentityResolution =
  | { ok: true; identity: CustomerIdentity; persist: IdentityToPersist | null }
  | { ok: false; field: "identity" | "terms_accepted"; error: string };

/**
 * @param stored Identidad de la cuenta, o `null` para un invitado.
 * @param policy Lo que pide el módulo en esta tienda (`identityPolicy`).
 */
export function resolveCheckoutIdentity(
  stored: CustomerIdentity | null,
  input: CheckoutIdentityInput,
  policy: IdentityPolicy,
  now: Date = new Date(),
): CheckoutIdentityResolution {
  const persist: IdentityToPersist = {};
  const needsDocument = policy.documentTypes.length > 0;

  let documentType: string | null = null;
  let documentNumber: string | null = null;
  if (stored && hasDocument(stored)) {
    documentType = stored.documentType;
    documentNumber = stored.documentNumber;
  } else if (needsDocument) {
    if (!input.identity)
      return { ok: false, field: "identity", error: "Documento requerido." };
    const invalid = checkDocument(policy.documentTypes, input.identity);
    if (invalid) return { ok: false, field: "identity", error: invalid };
    documentType = input.identity.documentType;
    documentNumber = input.identity.documentNumber;
    if (stored) {
      persist.documentType = documentType;
      persist.documentNumber = documentNumber;
    }
  }

  let termsAcceptedAt = stored?.termsAcceptedAt ?? null;
  if (!termsAcceptedAt) {
    if (input.terms_accepted !== true) {
      return {
        ok: false,
        field: "terms_accepted",
        error: "Debes aceptar los términos y condiciones para continuar.",
      };
    }
    termsAcceptedAt = now.toISOString();
    if (stored) persist.termsAccepted = true;
  }

  return {
    ok: true,
    identity: { documentType, documentNumber, termsAcceptedAt },
    persist: Object.keys(persist).length > 0 ? persist : null,
  };
}
