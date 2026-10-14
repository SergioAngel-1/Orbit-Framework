import "server-only";
import { PaymentError, type PaymentProvider } from "./types";
import { getPaymentSettings } from "./credentials";

// ============================================================================
//  Registro extensible de pasarelas.
//
//  Patrón REPETIBLE para añadir una pasarela (cero cambios en el checkout):
//    1. Crear `providers/<nombre>.ts` que implemente `PaymentProvider`.
//    2. Registrarlo en `providers/index.ts` con `registerProvider(...)`.
//    3. Elegirla en Control Center → Pasarelas de pago, o forzarla con
//       `PAYMENT_PROVIDER=<nombre>` en el entorno.
//
//  Los Route Handlers importan `@/lib/payments/providers` (efecto secundario)
//  para poblar este registro antes de resolver la pasarela activa.
// ============================================================================

const registry = new Map<string, PaymentProvider>();

/** Registra una pasarela. Idempotente: la última registrada gana. */
export function registerProvider(provider: PaymentProvider): void {
  registry.set(provider.id, provider);
}

/** Identificadores de las pasarelas registradas. */
export function listProviders(): string[] {
  return [...registry.keys()];
}

/**
 * Id de la pasarela activa: la del Control Center, salvo anulación con
 * `PAYMENT_PROVIDER` (precedencia completa en `credentials.ts`). `null` en
 * producción si no hay ninguna decidida.
 */
async function activeProviderId(): Promise<string | null> {
  return (await getPaymentSettings()).provider;
}

/**
 * Pasarelas de PRUEBAS que firman con un secreto conocido (`noop` cae a
 * `noop-sandbox-secret`, escrito en el repo). Su webhook solo se atiende si son
 * la pasarela activa: atendidas siempre, cualquiera podía firmar un «aprobado»
 * para su propio pedido y marcarlo pagado sin pagar.
 */
const TEST_ONLY_PROVIDERS = new Set(["noop"]);

/**
 * ¿Se atienden los webhooks de esta pasarela? Las reales, siempre (una
 * notificación tardía de la anterior tras cambiar de pasarela es legítima y va
 * firmada con SU secreto; sin secreto configurado, su verificación falla); las
 * de pruebas, solo si están activas.
 */
export async function acceptsWebhooksFrom(id: string): Promise<boolean> {
  if (!registry.has(id)) return false;
  return !TEST_ONLY_PROVIDERS.has(id) || (await activeProviderId()) === id;
}

/** ¿La pasarela activa cobra en su entorno de pruebas? */
export async function isActiveGatewaySandbox(): Promise<boolean> {
  const id = await activeProviderId();
  const provider = id ? registry.get(id) : undefined;
  return provider ? provider.isSandbox() : false;
}

/**
 * Pasarela con la que se cobra ahora.
 * @throws PaymentError 503 si no hay ninguna decidida; 404 si no está registrada.
 */
export async function getActiveProvider(): Promise<PaymentProvider> {
  const id = await activeProviderId();
  if (!id) {
    throw new PaymentError(
      "No hay pasarela de pago configurada (Control Center → Pasarelas de pago).",
      503,
      "provider_unavailable",
    );
  }
  return getProvider(id);
}

/**
 * Pasarela por id.
 * @throws PaymentError 404 si el id no está registrado.
 */
export function getProvider(id: string): PaymentProvider {
  const provider = registry.get(id);
  if (!provider) {
    throw new PaymentError(
      `Pasarela de pago no registrada: "${id}".`,
      404,
      "provider_not_found",
    );
  }
  return provider;
}
