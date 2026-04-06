/**
 * Tipos para la integración con Wompi
 * 
 * @see https://docs.wompi.co/docs/colombia/widget-checkout-web/
 */

/**
 * Configuración pública de Wompi (segura para frontend)
 */
export interface WompiConfig {
  public_key: string;
  sandbox: boolean;
  currency: string;
}

/**
 * Datos del cliente para Wompi
 */
export interface WompiCustomerData {
  email: string;
  fullName: string;
  phoneNumber: string;
  phoneNumberPrefix?: string;
  legalId?: string;
  legalIdType?: 'CC' | 'CE' | 'NIT' | 'PP' | 'TI' | 'DNI';
}

/**
 * Dirección de envío para Wompi
 */
export interface WompiShippingAddress {
  addressLine1: string;
  addressLine2?: string;
  city: string;
  region?: string;
  country: string;
  phoneNumber?: string;
  name?: string;
}

/**
 * Impuestos para Wompi
 */
export interface WompiTaxInCents {
  vat?: number;
  consumption?: number;
}

/**
 * Parámetros para crear una transacción con el widget
 */
export interface WompiWidgetParams {
  currency: string;
  amountInCents: number;
  reference: string;
  publicKey: string;
  signature: {
    integrity: string;
  };
  redirectUrl?: string;
  expirationTime?: string;
  taxInCents?: WompiTaxInCents;
  customerData?: WompiCustomerData;
  shippingAddress?: WompiShippingAddress;
  /** Métodos de pago permitidos (ej: ['CARD'] para solo tarjeta) */
  paymentMethods?: WompiPaymentMethod[];
}

/**
 * Estados posibles de una transacción
 */
export type WompiTransactionStatus = 
  | 'PENDING'
  | 'APPROVED'
  | 'DECLINED'
  | 'VOIDED'
  | 'ERROR';

/**
 * Métodos de pago disponibles
 */
export type WompiPaymentMethod = 
  | 'CARD'
  | 'NEQUI'
  | 'PSE'
  | 'BANCOLOMBIA_TRANSFER'
  | 'BANCOLOMBIA_COLLECT';

/**
 * Información de la transacción retornada por Wompi
 */
export interface WompiTransaction {
  id: string;
  created_at: string;
  finalized_at?: string;
  amount_in_cents: number;
  reference: string;
  customer_email: string;
  currency: string;
  payment_method_type: WompiPaymentMethod;
  payment_method?: {
    type: WompiPaymentMethod;
    extra?: Record<string, any>;
    installments?: number;
  };
  status: WompiTransactionStatus;
  status_message?: string;
  billing_data?: {
    legal_id?: string;
    legal_id_type?: string;
  };
  shipping_address?: WompiShippingAddress;
  redirect_url?: string;
  payment_source_id?: string;
  payment_link_id?: string;
  merchant?: {
    name: string;
    legal_name: string;
    contact_name: string;
    phone_number: string;
    logo_url?: string;
    legal_id_type: string;
    email: string;
    legal_id: string;
  };
}

/**
 * Resultado del callback del widget
 */
export interface WompiWidgetResult {
  transaction: WompiTransaction;
}

/**
 * Respuesta de la API de firma
 */
export interface WompiSignatureResponse {
  success: boolean;
  data?: {
    signature: string;
    reference: string;
    amount_in_cents: number;
    currency: string;
    public_key: string;
  };
  message?: string;
}

/**
 * Respuesta de la API de configuración
 */
export interface WompiConfigResponse {
  success: boolean;
  data?: WompiConfig;
  message?: string;
}

/**
 * Respuesta de la API de transacción
 */
export interface WompiTransactionResponse {
  success: boolean;
  data?: WompiTransaction;
  message?: string;
}

/**
 * Opciones para abrir el widget
 */
export interface WompiOpenWidgetOptions {
  amountInCents: number;
  reference: string;
  customerData?: WompiCustomerData;
  shippingAddress?: WompiShippingAddress;
  redirectUrl?: string;
  expirationTime?: string;
  /** Métodos de pago permitidos (ej: ['CARD'] para solo tarjeta) */
  paymentMethods?: WompiPaymentMethod[];
}

/**
 * Estado del hook useWompi
 */
export interface UseWompiState {
  isLoading: boolean;
  isWidgetLoading: boolean;
  error: string | null;
  config: WompiConfig | null;
  lastTransaction: WompiTransaction | null;
}

/**
 * Retorno del hook useWompi
 */
export interface UseWompiReturn extends UseWompiState {
  openWidget: (options: WompiOpenWidgetOptions) => Promise<WompiTransaction | null>;
  getTransaction: (transactionId: string) => Promise<WompiTransaction | null>;
  generateReference: (prefix?: string) => string;
  isConfigured: boolean;
}

/**
 * Paquete de Virtual Coins
 */
export interface VirtualCoinsPackage {
  id: number;
  name: string;
  slug: string;
  price: number;
  regular_price?: number;
  sale_price?: number | null;
  is_on_sale?: boolean;
  coins: number;
  bonus: number;
  total_coins: number;
  popular: boolean;
  description?: string;
  image?: string;
  min_membership?: number;
}

/**
 * Respuesta de la API de paquetes de Virtual Coins
 */
export interface VirtualCoinsPackagesResponse {
  success: boolean;
  data?: VirtualCoinsPackage[];
  message?: string;
}

/**
 * Declaración global para el widget de Wompi
 */
declare global {
  interface Window {
    WidgetCheckout: new (params: WompiWidgetParams) => {
      open: (callback: (result: WompiWidgetResult) => void) => void;
    };
  }
}
