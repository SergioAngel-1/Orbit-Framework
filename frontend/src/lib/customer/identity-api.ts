import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import type { CustomerIdentity } from "./identity";

// ============================================================================
//  Llamadas server-to-server al plugin `hwe-customer-identity` (secreto interno,
//  que añade `wpFetch`). Las reglas de negocio —documento único entre cuentas y
//  de escritura única— las decide WordPress; aquí solo se traducen sus respuestas.
// ============================================================================

function internalPost(path: string, body: unknown): Promise<Response> {
  return wpFetch(`/wp-json/hwe/v1/auth/${path}`, {
    method: "POST",
    cache: "no-store",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}

/** `true`/`false`, o `null` si WordPress no respondió (el llamador decide). */
export async function isDocumentAvailable(
  documentType: string,
  documentNumber: string,
): Promise<boolean | null> {
  try {
    const res = await internalPost("document-available", {
      document_type: documentType,
      document_number: documentNumber,
    });
    if (!res.ok) return null;
    const data = (await res.json()) as { available?: boolean };
    return data.available === true;
  } catch {
    return null;
  }
}

export type AttachIdentityResult =
  | { ok: true; identity: CustomerIdentity }
  | {
      ok: false;
      /** `document_taken`: de otra cuenta. `document_locked`: ya tiene otro. */
      reason: "document_taken" | "document_locked" | "invalid" | "unavailable";
    };

/**
 * Registra el documento (solo si el usuario no tiene uno) y/o la aceptación de
 * términos (solo la primera vez). Repetir el mismo documento es idempotente.
 */
export async function attachIdentity(
  userId: number,
  input: {
    documentType?: string;
    documentNumber?: string;
    termsAccepted?: boolean;
  },
): Promise<AttachIdentityResult> {
  const body: Record<string, unknown> = { user_id: userId };
  if (input.documentType && input.documentNumber) {
    body.document_type = input.documentType;
    body.document_number = input.documentNumber;
  }
  if (input.termsAccepted) body.terms_accepted = true;

  let res: Response;
  try {
    res = await internalPost("attach-identity", body);
  } catch {
    return { ok: false, reason: "unavailable" };
  }

  const data = (await res.json().catch(() => ({}))) as {
    error?: string;
    document_type?: string;
    document_number?: string;
    terms_accepted_at?: string;
  };
  if (res.status === 409) {
    return {
      ok: false,
      reason: data.error === "document_locked" ? "document_locked" : "document_taken",
    };
  }
  if (res.status === 400) return { ok: false, reason: "invalid" };
  if (!res.ok) return { ok: false, reason: "unavailable" };

  return {
    ok: true,
    identity: {
      documentType: data.document_type || null,
      documentNumber: data.document_number || null,
      termsAcceptedAt: data.terms_accepted_at || null,
    },
  };
}
