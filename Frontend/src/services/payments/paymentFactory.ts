/**
 * Factory de pasarelas de pago
 * 
 * Selecciona la implementación correcta de PaymentGateway basándose
 * en la configuración del sitio (SiteConfigContext → payment_gateway).
 * 
 * Para añadir una nueva pasarela:
 * 1. Crear archivo XxxGateway.ts implementando PaymentGateway
 * 2. Registrarla en el registry de este archivo
 * 3. Configurar 'payment_gateway' en Site Settings del backend
 * 
 * @package Starter
 */

import type { PaymentGateway, PaymentGatewayId } from '../../types/payment';
import { wompiGateway } from './WompiGateway';

/**
 * Registro de pasarelas disponibles
 * Agregar nuevas pasarelas aquí al implementarlas
 */
const gatewayRegistry: Record<string, PaymentGateway> = {
  wompi: wompiGateway,
  // stripe: stripeGateway,       // Futuro
  // mercadopago: mpGateway,      // Futuro
};

/** Pasarela por defecto si no hay configuración */
const DEFAULT_GATEWAY: PaymentGatewayId = 'wompi';

/**
 * Obtener la instancia de pasarela de pago según el ID configurado
 * 
 * @param gatewayId - ID de la pasarela (viene de site-settings → payment_gateway)
 * @returns Instancia de PaymentGateway
 * @throws Error si la pasarela no está registrada
 */
export function getPaymentGateway(gatewayId?: string): PaymentGateway {
  const id = gatewayId || DEFAULT_GATEWAY;
  const gateway = gatewayRegistry[id];

  if (!gateway) {
    console.warn(
      `[paymentFactory] Pasarela "${id}" no registrada. Usando "${DEFAULT_GATEWAY}" como fallback.`
    );
    return gatewayRegistry[DEFAULT_GATEWAY];
  }

  return gateway;
}

/**
 * Obtener la lista de pasarelas disponibles (para UI de admin o configuración)
 */
export function getAvailableGateways(): Array<{ id: string; name: string }> {
  return Object.values(gatewayRegistry).map((gw) => ({
    id: gw.id,
    name: gw.name,
  }));
}

export default getPaymentGateway;
