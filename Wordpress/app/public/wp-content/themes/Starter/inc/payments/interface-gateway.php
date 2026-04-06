<?php
/**
 * Payment Gateway Interface
 * 
 * Define el contrato que toda pasarela de pago debe implementar.
 * Las pasarelas concretas (Wompi, Stripe, etc.) heredan de esta interfaz.
 * 
 * @package Starter
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface Starter_Payment_Gateway {

    /**
     * Obtener el identificador único de la pasarela
     * @return string Ej: 'wompi', 'stripe', 'mercadopago'
     */
    public function get_id(): string;

    /**
     * Obtener el nombre legible de la pasarela
     * @return string Ej: 'Wompi', 'Stripe', 'MercadoPago'
     */
    public function get_name(): string;

    /**
     * Obtener la configuración pública (segura para frontend)
     * @return array { public_key: string, sandbox: bool, currency: string }
     */
    public function get_public_config(): array;

    /**
     * Generar firma de integridad para una transacción
     * @param string $reference    Referencia única
     * @param int    $amount_cents Monto en centavos
     * @param string $currency     Código de moneda ISO 4217
     * @return string Hash de integridad
     */
    public function generate_signature(string $reference, int $amount_cents, string $currency): string;

    /**
     * Verificar una transacción con la API de la pasarela
     * @param string $transaction_id ID de la transacción en la pasarela
     * @return array|WP_Error Datos de la transacción o error
     */
    public function verify_transaction(string $transaction_id);

    /**
     * Verificar la autenticidad de un webhook/evento
     * @param string $payload  Body crudo del request
     * @param array  $headers  Headers del request
     * @return bool True si el webhook es auténtico
     */
    public function verify_webhook(string $payload, array $headers): bool;

    /**
     * Obtener la URL base de la API de la pasarela
     * @return string
     */
    public function get_api_url(): string;

    /**
     * Verificar si la pasarela está correctamente configurada
     * @return bool
     */
    public function is_configured(): bool;
}
