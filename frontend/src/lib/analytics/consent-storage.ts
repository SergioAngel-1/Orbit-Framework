// ============================================================================
//  Decisión de consentimiento guardada en el navegador (`localStorage`).
//
//  Formato mínimo y compatible con la v1: la clave `cookie-consent` con
//  `accepted` o `declined`. El módulo `consent-log` (tarea 45) lo amplía con la
//  generación, el alcance (qué servicios cubría el «Aceptar») y el comprobante
//  del registro legal.
//
//  Todo acceso es tolerante a fallos: en Safari con cookies bloqueadas el simple
//  acceso a `localStorage` lanza, y sin estos `try` el árbol entero caería en
//  vez de volver a «sin respuesta todavía».
// ============================================================================

export const CONSENT_STORAGE_KEY = "cookie-consent";

export type ConsentDecision = "accepted" | "declined";

export interface StoredConsent {
  status: ConsentDecision;
}

type ReadableStorage = Pick<Storage, "getItem">;
type WritableStorage = Pick<Storage, "setItem" | "removeItem">;

export function readStoredConsent(storage: ReadableStorage): StoredConsent | null {
  try {
    const raw = storage.getItem(CONSENT_STORAGE_KEY);
    return raw === "accepted" || raw === "declined" ? { status: raw } : null;
  } catch {
    return null;
  }
}

/** Guarda la decisión. Devuelve `false` si no hubo dónde (se repreguntará). */
export function storeConsent(
  storage: WritableStorage,
  status: ConsentDecision,
): boolean {
  try {
    storage.setItem(CONSENT_STORAGE_KEY, status);
    return true;
  } catch {
    return false;
  }
}

export function clearStoredConsent(storage: WritableStorage): void {
  try {
    storage.removeItem(CONSENT_STORAGE_KEY);
  } catch {
    /* sin almacenamiento no hay nada guardado que retirar */
  }
}
