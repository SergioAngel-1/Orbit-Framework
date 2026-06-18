import "server-only";
import { hmacSha256, safeEqual } from "../signature";
import type {
  CreateCheckoutInput,
  CreateCheckoutResult,
  PaymentProvider,
  PaymentStatus,
  WebhookVerification,
} from "../types";

// ============================================================================
//  Proveedor de ejemplo "no-op" (sandbox).
//
//  No cobra nada: implementa el contrato completo para validar el flujo de
//  punta a punta (crear pedido → createCheckout → webhook firmado → pagado)
//  SIN integrar ninguna pasarela real ni manejar datos de tarjeta.
//
//  - createCheckout: devuelve un redirect a la URL de retorno con la referencia
//    (simula el checkout alojado que rebota al usuario de vuelta).
//  - verifyWebhook: valida un HMAC-SHA256 del cuerpo crudo con un secreto de
//    sandbox, exactamente como lo hará una pasarela real. Un script de prueba
//    puede firmar un evento y POSTearlo a /api/payments/webhook/noop.
// ============================================================================

/** Secreto de firma del sandbox (NO es una credencial real). */
const SANDBOX_SECRET = process.env.NOOP_INTEGRITY_SECRET || "noop-sandbox-secret";

/** Cabecera donde el sandbox envía la firma del evento. */
const SIGNATURE_HEADER = "x-noop-signature";

interface NoopEvent {
  reference: string;
  status: string;
  transactionId?: string;
  amountMinor?: number;
  currency?: string;
}

export class NoopProvider implements PaymentProvider {
  readonly id = "noop";

  async createCheckout(input: CreateCheckoutInput): Promise<CreateCheckoutResult> {
    const url = new URL(input.returnUrl);
    url.searchParams.set("ref", input.reference);
    url.searchParams.set("provider", this.id);
    return {
      mode: "redirect",
      redirectUrl: url.toString(),
      providerReference: `noop_${input.reference}`,
    };
  }

  async verifyWebhook(
    rawBody: string,
    headers: Headers,
  ): Promise<WebhookVerification> {
    const signature = headers.get(SIGNATURE_HEADER) ?? "";
    const expected = hmacSha256(rawBody, SANDBOX_SECRET, "hex");
    const valid = signature.length > 0 && safeEqual(signature, expected);

    let event: NoopEvent;
    try {
      event = JSON.parse(rawBody) as NoopEvent;
    } catch {
      return { valid: false, reference: "", status: "error" };
    }

    return {
      valid,
      reference: String(event.reference ?? ""),
      status: this.mapStatus(event.status),
      providerTransactionId: event.transactionId,
      amountMinor: event.amountMinor,
      currency: event.currency,
    };
  }

  mapStatus(providerStatus: string): PaymentStatus {
    switch (String(providerStatus).toUpperCase()) {
      case "APPROVED":
      case "PAID":
        return "approved";
      case "DECLINED":
      case "REJECTED":
        return "declined";
      case "VOIDED":
      case "CANCELLED":
        return "voided";
      case "PENDING":
        return "pending";
      default:
        return "error";
    }
  }
}
