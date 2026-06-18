// ============================================================================
//  Contrato común de la capa de pasarelas de pago (provider-agnostic).
//
//  Toda pasarela (Wompi, PayU, Bold, ePayco, Mercado Pago…) implementa la
//  interfaz `PaymentProvider`. El resto del sistema (checkout + Route Handlers)
//  habla SOLO con esta abstracción: añadir una pasarela no toca el checkout.
//
//  Diseño alrededor del patrón LATAM: checkout alojado / widget +
//  confirmación server-to-server firmada (webhook). El redirect del cliente
//  NUNCA es prueba de pago; la verdad la da `verifyWebhook`.
// ============================================================================

/** Estado de pago normalizado, común a todas las pasarelas. */
export type PaymentStatus = "pending" | "approved" | "declined" | "voided" | "error";

/** Datos para iniciar un cobro. Los provee el BFF a partir del pedido WC. */
export interface CreateCheckoutInput {
  /** Id del pedido WooCommerce (referencia de conciliación). */
  reference: string;
  /** Importe en unidades menores (centavos). */
  amountMinor: number;
  /** Moneda ISO-4217 (COP, USD…). */
  currency: string;
  customer: { email: string; fullName: string; phone?: string };
  /** URL de retorno del usuario (solo UX, NO prueba de pago). */
  returnUrl: string;
  metadata?: Record<string, string>;
}

/** Resultado de iniciar el cobro: a dónde mandar al usuario. */
export interface CreateCheckoutResult {
  mode: "redirect" | "widget";
  /** Checkout alojado: URL a la que redirigir el navegador. */
  redirectUrl?: string;
  /** Config para un widget embebido (datos públicos del proveedor). */
  widget?: Record<string, unknown>;
  /** Referencia/identificador de la transacción en la pasarela. */
  providerReference?: string;
}

/** Resultado de verificar un webhook entrante de la pasarela. */
export interface WebhookVerification {
  /** Firma/integridad verificada. Si es false, se descarta el evento. */
  valid: boolean;
  /** Id del pedido WooCommerce (referencia). */
  reference: string;
  status: PaymentStatus;
  providerTransactionId?: string;
  /** Importe reportado, para validar contra el pedido (unidades menores). */
  amountMinor?: number;
  currency?: string;
}

/** Interfaz única que toda pasarela debe implementar. */
export interface PaymentProvider {
  /** Identificador estable: "wompi" | "payu" | "bold" | "noop" | … */
  readonly id: string;
  /** Inicia el cobro y devuelve a dónde llevar al usuario. */
  createCheckout(input: CreateCheckoutInput): Promise<CreateCheckoutResult>;
  /** Verifica la firma del webhook y normaliza el evento. */
  verifyWebhook(rawBody: string, headers: Headers): Promise<WebhookVerification>;
  /** Traduce el estado nativo de la pasarela al estado normalizado. */
  mapStatus(providerStatus: string): PaymentStatus;
}

/** Error de la capa de pagos con código HTTP sugerido. */
export class PaymentError extends Error {
  readonly status: number;
  readonly code?: string;

  constructor(message: string, status = 500, code?: string) {
    super(message);
    this.name = "PaymentError";
    this.status = status;
    this.code = code;
  }
}
