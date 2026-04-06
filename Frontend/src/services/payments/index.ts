/**
 * Payment Gateway Module
 * 
 * Punto de entrada para el sistema modular de pasarelas de pago.
 * 
 * Uso:
 *   import { getPaymentGateway, getAvailableGateways } from '../services/payments';
 *   const gateway = getPaymentGateway('wompi');
 * 
 * @package Starter
 */

export { getPaymentGateway, getAvailableGateways } from './paymentFactory';
export { wompiGateway } from './WompiGateway';
export type { PaymentGateway } from '../../types/payment';
