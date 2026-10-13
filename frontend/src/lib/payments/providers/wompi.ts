import "server-only";
import { PaymentError } from "../types";
import { safeEqual, sha256 } from "../signature";
import { getPaymentSettings } from "../credentials";
import type {
  CreateCheckoutInput,
  CreateCheckoutResult,
  PaymentProvider,
  PaymentStatus,
  WebhookVerification,
} from "../types";

// ============================================================================
//  Wompi (Colombia) — https://docs.wompi.co/docs/colombia/inicio-rapido/
//
//  Checkout alojado (Web Checkout), sin SDK ni llamadas salientes: el cobro se
//  inicia redirigiendo el navegador a una URL firmada.
//
//  createCheckout: URL de https://checkout.wompi.co/p/ con public-key,
//  currency, amount-in-cents, reference y `signature:integrity`
//    = SHA-256("<reference><amount-in-cents><currency><secreto de integridad>")
//  en hex. Al terminar, Wompi devuelve al usuario a `redirect-url` con
//  `?id=<transacción>`: solo UX, NUNCA prueba de pago.
//
//  verifyWebhook (Eventos, `transaction.updated`): el cuerpo trae
//  `signature.properties` (rutas sobre `data`) y `timestamp`; el checksum
//    = SHA-256(valores de properties concatenados + timestamp + secreto de eventos)
//  y se compara con `signature.checksum` (o la cabecera `X-Event-Checksum`).
//  Además:
//   - las propiedades firmadas deben incluir id, estado e importe de la
//     transacción (si no, esos datos llegarían sin firmar);
//   - el `environment` del evento (`test`|`prod`) debe casar con el de la llave
//     pública: un evento de sandbox no puede marcar pagado un pedido real;
//   - la `reference` del evento NO va firmada (Wompi firma id, estado e importe;
//     es su diseño): con la firma válida se consulta la transacción a la API de
//     Wompi (GET /v1/transactions/{id}) y la referencia, el estado y el importe
//     salen de ESA respuesta, no del cuerpo. Sin esto, reenviar un evento legítimo
//     con la referencia de otro pedido del mismo importe lo daría por pagado.
//     Si la API no responde se falla con 502 (Wompi reintenta), no se descarta.
//
//  Unidades: `amount-in-cents` siempre en céntimos (pesos × 100), aunque la
//  moneda no tenga decimales: la verificación declara `minorUnit: 2`.
//
//  Credenciales (server-only): Control Center → Pasarelas de pago → Wompi, con
//  WOMPI_PUBLIC_KEY / WOMPI_INTEGRITY_SECRET / WOMPI_EVENTS_SECRET del entorno
//  como respaldo. Son POR ENTORNO: las de sandbox y producción no se mezclan.
// ============================================================================

const WEB_CHECKOUT_URL = "https://checkout.wompi.co/p/";

/** API de Wompi por entorno (el de la llave pública). */
const API_URL = {
  prod: "https://production.wompi.co/v1",
  test: "https://sandbox.wompi.co/v1",
} as const;

/** Lo que una notificación válida tiene que firmar, como mínimo. */
const REQUIRED_SIGNED = [
  "transaction.id",
  "transaction.status",
  "transaction.amount_in_cents",
] as const;

interface WompiEvent {
  event?: string;
  data?: { transaction?: Record<string, unknown> };
  environment?: string;
  signature?: { properties?: unknown; checksum?: unknown };
  timestamp?: number | string;
}

/** Lee un valor de `data` a partir de una ruta con puntos (`transaction.id`). */
function readPath(root: unknown, path: string): unknown {
  return path.split(".").reduce<unknown>((acc, key) => {
    if (acc && typeof acc === "object") return (acc as Record<string, unknown>)[key];
    return undefined;
  }, root);
}

/** Entorno que implica la llave pública: `pub_prod_…` → prod, lo demás → test. */
function keyEnvironment(publicKey: string | undefined): "prod" | "test" {
  return publicKey?.startsWith("pub_prod_") ? "prod" : "test";
}

/**
 * Móvil colombiano a los 10 dígitos que espera Wompi (con el prefijo aparte).
 * Wompi solo opera en Colombia; si el número no tiene esa forma se omite (un
 * dato de cliente mal formado hace que Web Checkout rechace la URL entera).
 */
function colombianMobile(phone: string | undefined): string | null {
  const digits = (phone ?? "").replace(/\D/g, "");
  const local =
    digits.length === 12 && digits.startsWith("57") ? digits.slice(2) : digits;
  return /^3\d{9}$/.test(local) ? local : null;
}

export class WompiProvider implements PaymentProvider {
  readonly id = "wompi";

  /** Entorno de pruebas = cualquier llave pública que no sea `pub_prod_…`. */
  async isSandbox(): Promise<boolean> {
    return keyEnvironment((await getPaymentSettings()).wompi.publicKey) === "test";
  }

  async createCheckout(input: CreateCheckoutInput): Promise<CreateCheckoutResult> {
    const { publicKey, integritySecret } = (await getPaymentSettings()).wompi;
    if (!publicKey || !integritySecret) {
      throw new PaymentError(
        "Wompi no está configurado (Control Center → Pasarelas de pago, o WOMPI_* en el entorno).",
        500,
        "provider_misconfigured",
      );
    }

    const amount = String(input.amountMinor);
    const signature = sha256(
      `${input.reference}${amount}${input.currency}${integritySecret}`,
    );

    const url = new URL(WEB_CHECKOUT_URL);
    url.searchParams.set("public-key", publicKey);
    url.searchParams.set("currency", input.currency);
    url.searchParams.set("amount-in-cents", amount);
    url.searchParams.set("reference", input.reference);
    url.searchParams.set("signature:integrity", signature);
    url.searchParams.set("redirect-url", input.returnUrl);
    if (input.customer.email)
      url.searchParams.set("customer-data:email", input.customer.email);
    if (input.customer.fullName)
      url.searchParams.set("customer-data:full-name", input.customer.fullName);
    const mobile = colombianMobile(input.customer.phone);
    if (mobile) {
      url.searchParams.set("customer-data:phone-number", mobile);
      url.searchParams.set("customer-data:phone-number-prefix", "+57");
    }

    return {
      mode: "redirect",
      redirectUrl: url.toString(),
      providerReference: input.reference,
    };
  }

  async verifyWebhook(rawBody: string, headers: Headers): Promise<WebhookVerification> {
    const { publicKey, eventsSecret } = (await getPaymentSettings()).wompi;
    let event: WompiEvent;
    try {
      event = JSON.parse(rawBody) as WompiEvent;
    } catch {
      return { valid: false, reference: "", status: "error" };
    }

    const tx = event.data?.transaction ?? {};
    const reference = String(tx.reference ?? "");
    const status = this.mapStatus(String(tx.status ?? ""));
    const amountMinor = Number(tx.amount_in_cents);
    const currency = String(tx.currency ?? "");

    const properties = Array.isArray(event.signature?.properties)
      ? event.signature.properties.filter((p): p is string => typeof p === "string")
      : [];
    const checksum = String(
      event.signature?.checksum ?? headers.get("x-event-checksum") ?? "",
    );
    const signsWhatMatters = REQUIRED_SIGNED.every((p) => properties.includes(p));

    if (
      !eventsSecret ||
      event.event !== "transaction.updated" ||
      !signsWhatMatters ||
      checksum.length === 0
    ) {
      return { valid: false, reference, status };
    }

    const expected = sha256(
      properties.map((p) => String(readPath(event.data, p) ?? "")).join("") +
        String(event.timestamp ?? "") +
        eventsSecret,
    ).toUpperCase();

    const environment = keyEnvironment(publicKey);
    const signatureOk =
      safeEqual(checksum.toUpperCase(), expected) && event.environment === environment;
    const transactionId = typeof tx.id === "string" ? tx.id : "";
    if (!signatureOk || transactionId === "") {
      return { valid: false, reference, status, minorUnit: 2 };
    }

    // Consulta autoritativa (ver la cabecera): la referencia no está firmada.
    const confirmed = await this.fetchTransaction(transactionId, environment);
    const confirmedAmount = Number(confirmed?.amount_in_cents);
    const confirmedReference = String(confirmed?.reference ?? "");
    if (
      !confirmed ||
      confirmedReference === "" ||
      confirmedReference !== reference ||
      confirmedAmount !== amountMinor
    ) {
      return { valid: false, reference, status, minorUnit: 2 };
    }

    return {
      valid: true,
      reference: confirmedReference,
      status: this.mapStatus(String(confirmed.status ?? "")),
      providerTransactionId: transactionId,
      amountMinor: confirmedAmount,
      minorUnit: 2,
      currency: String(confirmed.currency ?? "") || currency || undefined,
    };
  }

  /**
   * `data` de GET /v1/transactions/{id}, o null si Wompi no la conoce (4xx). Un
   * fallo de red o un 5xx lanza `PaymentError` 502: el webhook responde no-2xx y
   * Wompi lo reintenta, en vez de descartar un cobro real.
   */
  private async fetchTransaction(
    id: string,
    environment: "prod" | "test",
  ): Promise<Record<string, unknown> | null> {
    const unavailable = () =>
      new PaymentError(
        "No se pudo confirmar la transacción con Wompi.",
        502,
        "provider_unavailable",
      );
    let res: Response;
    try {
      res = await fetch(
        `${API_URL[environment]}/transactions/${encodeURIComponent(id)}`,
        {
          headers: { Accept: "application/json" },
          signal: AbortSignal.timeout(15_000),
        },
      );
    } catch {
      throw unavailable();
    }
    if (res.status >= 500) throw unavailable();
    if (!res.ok) return null;
    try {
      const body = (await res.json()) as { data?: Record<string, unknown> };
      return body.data && typeof body.data === "object" ? body.data : null;
    } catch {
      throw unavailable();
    }
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
