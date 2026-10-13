// ============================================================================
//  Envío (navegador) de las decisiones de cookies al registro de
//  consentimientos (`POST /api/consent`). Lo que no llega se queda en la cola
//  de `consent-storage.ts` y se reenvía en la siguiente carga.
//
//  En ORDEN y parando en el primer fallo: si «aceptar» no llegó, «retirar»
//  tampoco debe adelantarse, o el registro contaría la historia al revés.
// ============================================================================

import { isConsentReceipt } from "./consent-record";
import { acknowledgePending, dropPending, readStoredConsent } from "./consent-storage";

const ENDPOINT = "/api/consent";
const PERMANENT_REJECTIONS = new Set([400, 409, 422]);

type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

let inflight: Promise<void> | null = null;

async function drain(
  storage: Storage,
  fetchImpl: FetchLike,
  onChange: () => void,
): Promise<void> {
  for (;;) {
    const next = readStoredConsent(storage)?.pending[0];
    if (!next) return;
    let receipt: unknown;
    let rejected = false;
    try {
      // `keepalive`: la decisión puede ir seguida de una recarga (retirar el
      // consentimiento recarga la página) o de una navegación.
      const res = await fetchImpl(ENDPOINT, {
        method: "POST",
        keepalive: true,
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(next),
      });
      // El servidor no lo aceptará nunca (petición inválida, o ya no hay
      // servicios que consentir): se descarta. Un 403 (Origin) o un 429 no
      // entran: son configuración o momento, y la prueba no debe perderse.
      rejected = PERMANENT_REJECTIONS.has(res.status);
      if (!res.ok && !rejected) return;
      if (res.ok) receipt = ((await res.json()) as { receipt?: unknown }).receipt;
    } catch {
      return;
    }
    if (!rejected && !isConsentReceipt(receipt)) return;
    try {
      if (rejected) dropPending(storage, next);
      else acknowledgePending(storage, next, receipt as string);
    } catch {
      return; // almacenamiento bloqueado: sin poder sacarlo de la cola, se reenviaría sin fin
    }
    onChange();
    const after = readStoredConsent(storage)?.pending[0];
    if (after?.clientAt === next.clientAt && after.decision === next.decision) return;
  }
}

/** Envía lo pendiente. Una sola pasada a la vez: las llamadas concurrentes esperan a la en curso. */
export function syncPendingConsent(
  storage: Storage,
  fetchImpl: FetchLike = (input, init) => fetch(input, init),
  onChange: () => void = () => {},
): Promise<void> {
  if (!inflight) {
    inflight = drain(storage, fetchImpl, onChange)
      .catch(() => {
        /* almacenamiento inaccesible (Safari bloqueado): nada que enviar */
      })
      .finally(() => {
        inflight = null;
      });
  }
  return inflight;
}
