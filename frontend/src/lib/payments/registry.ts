import "server-only";
import { PaymentError, type PaymentProvider } from "./types";

// ============================================================================
//  Registro extensible de pasarelas.
//
//  Patrón REPETIBLE para añadir una pasarela (cero cambios en el checkout):
//    1. Crear `providers/<nombre>.ts` que implemente `PaymentProvider`.
//    2. Registrarlo en `providers/index.ts` con `registerProvider(...)`.
//    3. Poner `PAYMENT_PROVIDER=<nombre>` en el entorno.
//
//  Los Route Handlers importan `@/lib/payments/providers` (efecto secundario)
//  para poblar este registro antes de resolver el proveedor activo.
// ============================================================================

const registry = new Map<string, PaymentProvider>();

/** Registra una pasarela. Idempotente: la última registrada gana. */
export function registerProvider(provider: PaymentProvider): void {
  registry.set(provider.id, provider);
}

/** Lista los identificadores de las pasarelas registradas. */
export function listProviders(): string[] {
  return [...registry.keys()];
}

/** Id de la pasarela activa según el entorno (por defecto `noop`). */
export function activeProviderId(): string {
  return process.env.PAYMENT_PROVIDER || "noop";
}

/**
 * Resuelve una pasarela por id (o la activa si se omite).
 * @throws PaymentError 404 si el id no está registrado.
 */
export function getProvider(id: string = activeProviderId()): PaymentProvider {
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
