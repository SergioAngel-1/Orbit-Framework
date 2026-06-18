import "server-only";
import { PaymentError } from "../types";
import type {
  CreateCheckoutResult,
  PaymentProvider,
  PaymentStatus,
  WebhookVerification,
} from "../types";

// ============================================================================
//  PLANTILLA-STUB: Bold (Colombia) — https://developers.bold.co
//
//  Sin credenciales ni llamadas reales. Documenta el TODO de la integración.
//
//  Patrón Bold (Botón / Checkout):
//   - createCheckout: el widget se firma con `integritySignature` =
//     SHA-256("<orderId><amount><currency><BOLD_SECRET_KEY>"). Puede operar como
//     widget embebido (mode: "widget") con los datos públicos, o como redirect.
//   - verifyWebhook: Bold envía eventos firmados; validar el HMAC con la clave
//     de identidad/secreto y mapear el estado de la transacción.
//
//  Credenciales (server-only): BOLD_IDENTITY_KEY, BOLD_SECRET_KEY.
// ============================================================================

export class BoldProvider implements PaymentProvider {
  readonly id = "bold";

  async createCheckout(): Promise<CreateCheckoutResult> {
    // TODO(bold): calcular integritySignature (SHA-256) y devolver widget/redirect.
    throw new PaymentError(
      "Pasarela Bold no implementada (plantilla-stub).",
      501,
      "provider_not_implemented",
    );
  }

  async verifyWebhook(): Promise<WebhookVerification> {
    // TODO(bold): validar la firma del evento y normalizar el estado.
    throw new PaymentError(
      "Verificación de webhook Bold no implementada (plantilla-stub).",
      501,
      "provider_not_implemented",
    );
  }

  mapStatus(providerStatus: string): PaymentStatus {
    switch (String(providerStatus).toUpperCase()) {
      case "APPROVED":
      case "SALE_APPROVED":
        return "approved";
      case "REJECTED":
      case "SALE_REJECTED":
        return "declined";
      case "VOIDED":
        return "voided";
      case "PENDING":
        return "pending";
      default:
        return "error";
    }
  }
}
