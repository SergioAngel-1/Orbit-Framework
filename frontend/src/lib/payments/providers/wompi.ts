import "server-only";
import { PaymentError } from "../types";
import type {
  CreateCheckoutResult,
  PaymentProvider,
  PaymentStatus,
  WebhookVerification,
} from "../types";

// ============================================================================
//  PLANTILLA-STUB: Wompi (Colombia) — https://docs.wompi.co
//
//  Sin credenciales ni llamadas reales. Documenta el TODO de la integración
//  para que añadir Wompi sea "rellenar esta plantilla + registrarla".
//
//  Patrón Wompi (checkout alojado / Web Checkout):
//   - createCheckout: construir la URL de https://checkout.wompi.co/p/ con
//     public-key, currency, amount-in-cents, reference y `signature:integrity`
//     = SHA-256("<reference><amount-in-cents><currency><WOMPI_INTEGRITY_SECRET>").
//   - verifyWebhook (Eventos): el cuerpo trae `signature.properties` +
//     `timestamp`; la firma = SHA-256(concat(valores de properties) + timestamp
//     + WOMPI_EVENTS_SECRET) y se compara con `signature.checksum`.
//   - Estados Wompi: APPROVED | DECLINED | VOIDED | ERROR | PENDING.
//
//  Credenciales (server-only): WOMPI_PRIVATE_KEY, WOMPI_INTEGRITY_SECRET,
//  WOMPI_EVENTS_SECRET (+ NEXT_PUBLIC_WOMPI_PUBLIC_KEY solo si se usa widget).
// ============================================================================

export class WompiProvider implements PaymentProvider {
  readonly id = "wompi";

  async createCheckout(): Promise<CreateCheckoutResult> {
    // TODO(wompi): firmar `signature:integrity` y construir la URL de Web Checkout.
    throw new PaymentError(
      "Pasarela Wompi no implementada (plantilla-stub).",
      501,
      "provider_not_implemented",
    );
  }

  async verifyWebhook(): Promise<WebhookVerification> {
    // TODO(wompi): validar checksum SHA-256 con WOMPI_EVENTS_SECRET.
    throw new PaymentError(
      "Verificación de webhook Wompi no implementada (plantilla-stub).",
      501,
      "provider_not_implemented",
    );
  }

  mapStatus(providerStatus: string): PaymentStatus {
    switch (String(providerStatus).toUpperCase()) {
      case "APPROVED":
        return "approved";
      case "DECLINED":
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
