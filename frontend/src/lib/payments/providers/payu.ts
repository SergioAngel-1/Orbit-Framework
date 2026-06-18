import "server-only";
import { PaymentError } from "../types";
import type {
  CreateCheckoutResult,
  PaymentProvider,
  PaymentStatus,
  WebhookVerification,
} from "../types";

// ============================================================================
//  PLANTILLA-STUB: PayU Latam — https://developers.payulatam.com
//
//  Sin credenciales ni llamadas reales. Documenta el TODO de la integración.
//
//  Patrón PayU (WebCheckout por formulario):
//   - createCheckout: devolver `mode: "redirect"` a la URL de WebCheckout con
//     merchantId, accountId, referenceCode, amount, currency y
//     `signature` = MD5("<API_KEY>~<merchantId>~<referenceCode>~<amount>~<currency>").
//   - verifyWebhook (Confirmación): PayU envía `sign` = MD5(concat de
//     ApiKey~merchantId~referenceCode~value~currency~state_pol). Validar y
//     mapear `state_pol` (4=APPROVED, 6=DECLINED, 5=EXPIRED, 7=PENDING).
//
//  Credenciales (server-only): PAYU_MERCHANT_ID, PAYU_API_KEY, PAYU_ACCOUNT_ID.
//  Nota: PayU usa MD5 históricamente; usar `crypto.createHash("md5")` en la
//  implementación real (las primitivas SHA-256 de `signature.ts` no aplican).
// ============================================================================

export class PayUProvider implements PaymentProvider {
  readonly id = "payu";

  async createCheckout(): Promise<CreateCheckoutResult> {
    // TODO(payu): firmar con MD5 y construir el formulario/URL de WebCheckout.
    throw new PaymentError(
      "Pasarela PayU no implementada (plantilla-stub).",
      501,
      "provider_not_implemented",
    );
  }

  async verifyWebhook(): Promise<WebhookVerification> {
    // TODO(payu): validar `sign` (MD5) de la confirmación y mapear state_pol.
    throw new PaymentError(
      "Verificación de confirmación PayU no implementada (plantilla-stub).",
      501,
      "provider_not_implemented",
    );
  }

  mapStatus(providerStatus: string): PaymentStatus {
    switch (String(providerStatus)) {
      case "4": // APPROVED
        return "approved";
      case "6": // DECLINED
        return "declined";
      case "5": // EXPIRED
        return "voided";
      case "7": // PENDING
        return "pending";
      default:
        return "error";
    }
  }
}
