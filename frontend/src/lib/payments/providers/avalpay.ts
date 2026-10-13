import "server-only";
import crypto from "node:crypto";
import { PaymentError } from "../types";
import { safeEqual } from "../signature";
import { getPaymentSettings } from "../credentials";
import { getSiteConfig } from "@/lib/config";
import type {
  CreateCheckoutInput,
  CreateCheckoutResult,
  PaymentProvider,
  PaymentStatus,
  WebhookVerification,
} from "../types";

// ============================================================================
//  Aval Pay (Colombia): marca blanca de PlacetoPay Gateway API.
//  Docs: https://docs.placetopay.dev (auth, checkout/create-session,
//  checkout/query-session, checkout/notification).
//
//  Checkout alojado (sesión → processUrl), sin captura de tarjeta en el BFF.
//
//  Auth (todas las llamadas): { login, tranKey, nonce, seed }
//   - seed = fecha ISO-8601 actual (tolerancia de 5 min en el servidor);
//   - rawNonce = bytes aleatorios por petición; nonce = Base64(rawNonce);
//   - tranKey = Base64(SHA-256(rawNonce + seed + secretKey)).
//
//  Unidades: PlacetoPay usa unidades MAYORES con decimales (100 = cien), no
//  céntimos: total = amountMinor / 100.
//
//  verifyWebhook. La firma de la notificación cubre SOLO
//    sessionId + status.status + status.date + secretKey
//  (hex; algoritmo según el prefijo: `sha256:<hex>` o, sin prefijo, SHA-1 de
//  legado). La `reference` del cuerpo NO va firmada: tomarla de ahí permitía
//  reenviar una notificación legítima de «aprobado» con la referencia de OTRO
//  pedido del mismo importe y darlo por pagado. Por eso, con la firma válida, se
//  consulta la sesión a la pasarela (POST /api/session/{requestId}) y de ESA
//  respuesta salen la referencia, el estado y el importe. Nunca se marca pagado
//  solo por el cuerpo del webhook.
//
//  El id de la sesión en la notificación es `requestId`; `internalReference`
//  solo aparece en las de pagos recurrentes (donde lo sustituye).
//
//  Credenciales (server-only): Control Center → Pasarelas de pago → Aval Pay,
//  con AVALPAY_LOGIN / AVALPAY_SECRET_KEY / AVALPAY_BASE_URL como respaldo.
// ============================================================================

interface AvalPayStatus {
  status?: string;
  reason?: string;
  message?: string;
  date?: string;
}

interface AvalPaySessionResponse {
  status?: AvalPayStatus;
  requestId?: number;
  processUrl?: string;
}

interface AvalPayNotification {
  status?: AvalPayStatus;
  requestId?: number | string;
  internalReference?: number | string;
  reference?: string;
  signature?: string;
}

interface AvalPayQueryResponse {
  status?: AvalPayStatus;
  request?: {
    payment?: { reference?: string; amount?: { total?: number; currency?: string } };
  };
}

/** Fecha ISO-8601 sin milisegundos, con offset (formato exigido por PlacetoPay). */
function isoSeed(): string {
  return new Date().toISOString().replace(/\.\d{3}Z$/, "+00:00");
}

export function buildAvalPayAuth(login: string, secretKey: string) {
  const rawNonce = crypto.randomBytes(16);
  const seed = isoSeed();
  const tranKey = crypto
    .createHash("sha256")
    .update(Buffer.concat([rawNonce, Buffer.from(seed + secretKey, "utf8")]))
    .digest("base64");
  return { login, tranKey, nonce: rawNonce.toString("base64"), seed };
}

/** Firma esperada de una notificación (ver cabecera). */
export function avalPaySignature(
  sessionId: string,
  status: string,
  date: string,
  secretKey: string,
  algorithm: "sha256" | "sha1",
): string {
  return crypto
    .createHash(algorithm)
    .update(`${sessionId}${status}${date}${secretKey}`)
    .digest("hex");
}

async function requiredCredentials() {
  const { login, secretKey, baseUrl } = (await getPaymentSettings()).avalpay;
  if (!login || !secretKey || !baseUrl) {
    throw new PaymentError(
      "Aval Pay no está configurado (Control Center → Pasarelas de pago, o AVALPAY_* en el entorno).",
      500,
      "provider_misconfigured",
    );
  }
  return { login, secretKey, baseUrl };
}

/** Tipos de documento de PlacetoPay: el pasaporte es `PPN`, no `PP`. */
function placetopayDocumentType(type: string | undefined): string {
  const code = (type ?? "CC").toUpperCase();
  return code === "PP" ? "PPN" : code;
}

export class AvalPayProvider implements PaymentProvider {
  readonly id = "avalpay";

  /** Entorno de pruebas = URL base de pruebas (`checkout.test.…`). */
  async isSandbox(): Promise<boolean> {
    return /\.test\./i.test((await getPaymentSettings()).avalpay.baseUrl);
  }

  async createCheckout(input: CreateCheckoutInput): Promise<CreateCheckoutResult> {
    const { login, secretKey, baseUrl } = await requiredCredentials();
    const config = await getSiteConfig();
    const total = Number((input.amountMinor / 100).toFixed(2));
    const [firstName, ...rest] = input.customer.fullName.trim().split(/\s+/);

    const response = await fetch(`${baseUrl}/api/session`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        locale: "es_CO",
        auth: buildAvalPayAuth(login, secretKey),
        payment: {
          reference: input.reference,
          description: `${config.brand.name} · #${input.reference}`,
          amount: { currency: input.currency, total },
        },
        buyer: {
          // Sin documento en el pedido, la pasarela lo pide en su formulario.
          document: input.customer.document?.number ?? "0",
          documentType: placetopayDocumentType(input.customer.document?.type),
          name: firstName || "Cliente",
          surname: rest.join(" ") || firstName || "Cliente",
          email: input.customer.email,
          mobile: input.customer.phone,
        },
        returnUrl: input.returnUrl,
        ipAddress: "0.0.0.0",
        userAgent: `${config.brand.name} BFF`,
      }),
      signal: AbortSignal.timeout(15_000),
    });

    let body: AvalPaySessionResponse;
    try {
      body = (await response.json()) as AvalPaySessionResponse;
    } catch {
      throw new PaymentError("Respuesta inválida de Aval Pay.", 502, "provider_error");
    }
    if (!response.ok || body.status?.status !== "OK" || !body.processUrl) {
      throw new PaymentError(
        body.status?.message || "Aval Pay rechazó la creación de la sesión.",
        502,
        "provider_error",
      );
    }

    return {
      mode: "redirect",
      redirectUrl: body.processUrl,
      providerReference: String(body.requestId ?? ""),
    };
  }

  // Aval Pay firma el cuerpo (`signature`), no una cabecera.
  async verifyWebhook(rawBody: string): Promise<WebhookVerification> {
    let event: AvalPayNotification;
    try {
      event = JSON.parse(rawBody) as AvalPayNotification;
    } catch {
      return { valid: false, reference: "", status: "error" };
    }

    const sessionId = String(event.requestId ?? event.internalReference ?? "");
    const status = String(event.status?.status ?? "");
    const date = String(event.status?.date ?? "");
    const rawSignature = String(event.signature ?? "");
    const claimedReference = String(event.reference ?? "");
    const invalid: WebhookVerification = {
      valid: false,
      reference: claimedReference,
      status: this.mapStatus(status),
    };

    const { login, secretKey, baseUrl } = await requiredCredentials();

    const isSha256 = rawSignature.toLowerCase().startsWith("sha256:");
    const signature = isSha256 ? rawSignature.slice("sha256:".length) : rawSignature;
    const expected = avalPaySignature(
      sessionId,
      status,
      date,
      secretKey,
      isSha256 ? "sha256" : "sha1",
    );
    if (!/^\d+$/.test(sessionId) || !safeEqual(signature.toLowerCase(), expected)) {
      return invalid;
    }

    // Consulta autoritativa: referencia, estado e importe salen de aquí.
    let query: AvalPayQueryResponse;
    try {
      const res = await fetch(`${baseUrl}/api/session/${sessionId}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ auth: buildAvalPayAuth(login, secretKey) }),
        signal: AbortSignal.timeout(15_000),
      });
      if (!res.ok) return invalid;
      query = (await res.json()) as AvalPayQueryResponse;
    } catch {
      return invalid;
    }

    const payment = query.request?.payment;
    const reference = String(payment?.reference ?? "");
    if (!reference || (claimedReference !== "" && claimedReference !== reference)) {
      return invalid;
    }
    const amount = payment?.amount;
    return {
      valid: true,
      reference,
      status: this.mapStatus(String(query.status?.status ?? status)),
      providerTransactionId: sessionId,
      amountMinor:
        typeof amount?.total === "number" ? Math.round(amount.total * 100) : undefined,
      minorUnit: 2,
      currency: amount?.currency,
    };
  }

  mapStatus(providerStatus: string): PaymentStatus {
    switch (String(providerStatus).toUpperCase()) {
      case "APPROVED":
        return "approved";
      case "REJECTED":
        return "declined";
      case "PENDING":
        return "pending";
      default:
        return "error";
    }
  }
}
