/**
 * Tipos genéricos para el sistema de pasarelas de pago
 * 
 * Define interfaces que cualquier pasarela (Wompi, Stripe, MercadoPago, etc.)
 * debe implementar para ser compatible con el sistema.
 * 
 * @package Starter
 */

// ============================================
// ESTADOS Y ENUMS
// ============================================

/**
 * Estados normalizados de una transacción (independientes de la pasarela)
 */
export type PaymentTransactionStatus =
  | 'PENDING'
  | 'APPROVED'
  | 'DECLINED'
  | 'VOIDED'
  | 'ERROR';

/**
 * Identificadores de pasarelas soportadas
 */
export type PaymentGatewayId = 'wompi' | 'stripe' | 'mercadopago';

// ============================================
// DATOS DEL CLIENTE
// ============================================

/**
 * Datos del cliente para la pasarela
 */
export interface PaymentCustomerData {
  email: string;
  fullName: string;
  phoneNumber: string;
  phoneNumberPrefix?: string;
  legalId?: string;
  legalIdType?: string;
}

/**
 * Dirección de envío
 */
export interface PaymentShippingAddress {
  addressLine1: string;
  addressLine2?: string;
  city: string;
  region?: string;
  country: string;
  phoneNumber?: string;
  name?: string;
}

// ============================================
// CONFIGURACIÓN DE LA PASARELA
// ============================================

/**
 * Configuración pública de la pasarela (segura para frontend)
 */
export interface PaymentGatewayConfig {
  /** Llave pública de la pasarela */
  publicKey: string;
  /** Si está en modo sandbox/test */
  sandbox: boolean;
  /** Moneda configurada */
  currency: string;
  /** Datos extra específicos de la pasarela */
  extra?: Record<string, any>;
}

// ============================================
// TRANSACCIONES
// ============================================

/**
 * Información normalizada de una transacción
 */
export interface PaymentTransaction {
  /** ID de la transacción en la pasarela */
  id: string;
  /** Referencia interna de la transacción */
  reference: string;
  /** Monto en centavos */
  amountInCents: number;
  /** Moneda */
  currency: string;
  /** Estado normalizado */
  status: PaymentTransactionStatus;
  /** Mensaje de estado (opcional) */
  statusMessage?: string;
  /** Tipo de método de pago usado */
  paymentMethodType?: string;
  /** Email del cliente */
  customerEmail?: string;
  /** Fecha de creación */
  createdAt?: string;
  /** Datos crudos de la pasarela (para acceso directo si es necesario) */
  raw?: Record<string, any>;
}

// ============================================
// OPCIONES DEL WIDGET
// ============================================

/**
 * Opciones para abrir el widget de pago
 */
export interface PaymentWidgetOptions {
  /** Monto en centavos */
  amountInCents: number;
  /** Referencia única de la transacción */
  reference: string;
  /** Datos del cliente (opcional) */
  customerData?: PaymentCustomerData;
  /** Dirección de envío (opcional) */
  shippingAddress?: PaymentShippingAddress;
  /** URL de redirección post-pago (opcional) */
  redirectUrl?: string;
  /** Tiempo de expiración ISO 8601 (opcional) */
  expirationTime?: string;
  /** Métodos de pago permitidos (opcional, específico de pasarela) */
  paymentMethods?: string[];
}

// ============================================
// RESPUESTAS DE API
// ============================================

/**
 * Respuesta genérica de la API del backend
 */
export interface PaymentApiResponse<T = any> {
  success: boolean;
  data?: T;
  message?: string;
}

/**
 * Datos de firma/integridad
 */
export interface PaymentSignatureData {
  signature: string;
  reference: string;
  amountInCents: number;
  currency: string;
  publicKey: string;
}

// ============================================
// INTERFAZ PRINCIPAL DE LA PASARELA
// ============================================

/**
 * Interfaz que toda pasarela de pago debe implementar.
 * 
 * Los métodos de negocio específicos (virtual-coins, memberships, checkout)
 * se mantienen en sus servicios respectivos y usan la pasarela internamente.
 * Esta interfaz solo cubre las operaciones core de la pasarela.
 */
export interface PaymentGateway {
  /** Identificador de la pasarela */
  readonly id: PaymentGatewayId;
  /** Nombre legible de la pasarela */
  readonly name: string;
  /** URL del script del widget (si aplica) */
  readonly widgetScriptUrl: string | null;

  /**
   * Obtener configuración pública de la pasarela desde el backend
   */
  getConfig(): Promise<PaymentApiResponse<PaymentGatewayConfig>>;

  /**
   * Generar firma de integridad para una transacción
   */
  generateSignature(
    reference: string,
    amountInCents: number,
    expirationTime?: string
  ): Promise<PaymentApiResponse<PaymentSignatureData>>;

  /**
   * Consultar estado de una transacción por ID
   */
  getTransaction(transactionId: string): Promise<PaymentApiResponse<PaymentTransaction>>;

  /**
   * Generar una referencia única para la transacción
   */
  generateReference(prefix?: string): string;

  /**
   * Cargar el script del widget de la pasarela en el DOM
   */
  loadWidgetScript(): Promise<void>;

  /**
   * Abrir el widget de pago y esperar resultado
   * @returns Transacción completada o null si el usuario cerró el widget
   */
  openWidget(
    config: PaymentGatewayConfig,
    signatureData: PaymentSignatureData,
    options: PaymentWidgetOptions
  ): Promise<PaymentTransaction | null>;
}

// ============================================
// HOOK DE PASARELA
// ============================================

/**
 * Estado del hook de pasarela de pago
 */
export interface UsePaymentGatewayState {
  isLoading: boolean;
  isWidgetLoading: boolean;
  error: string | null;
  config: PaymentGatewayConfig | null;
  lastTransaction: PaymentTransaction | null;
}

/**
 * Retorno del hook de pasarela de pago
 */
export interface UsePaymentGatewayReturn extends UsePaymentGatewayState {
  /** Abrir el widget de pago (maneja firma + widget internamente) */
  openWidget: (options: PaymentWidgetOptions) => Promise<PaymentTransaction | null>;
  /** Consultar una transacción por ID */
  getTransaction: (transactionId: string) => Promise<PaymentTransaction | null>;
  /** Generar referencia única */
  generateReference: (prefix?: string) => string;
  /** Si la pasarela está configurada y lista */
  isConfigured: boolean;
  /** ID de la pasarela activa */
  gatewayId: PaymentGatewayId;
}
