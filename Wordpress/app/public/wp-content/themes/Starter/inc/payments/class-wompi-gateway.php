<?php
/**
 * Wompi Payment Gateway Implementation
 * 
 * Implementa Starter_Payment_Gateway para Wompi (Colombia).
 * Envuelve la configuración existente en Starter_Wompi_Config
 * manteniendo compatibilidad total con los flujos de pago actuales.
 * 
 * @package Starter
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Wompi_Gateway implements Starter_Payment_Gateway {

    /**
     * @return string
     */
    public function get_id(): string {
        return 'wompi';
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return 'Wompi';
    }

    /**
     * Obtener configuración pública (delega a Starter_Wompi_Config existente)
     * @return array
     */
    public function get_public_config(): array {
        if (class_exists('Starter_Wompi_Config')) {
            return Starter_Wompi_Config::get_public_config();
        }
        return [
            'public_key' => '',
            'sandbox'    => true,
            'currency'   => function_exists('site_get_currency_code') ? site_get_currency_code() : 'COP',
        ];
    }

    /**
     * Generar firma de integridad SHA-256
     * @param string $reference
     * @param int    $amount_cents
     * @param string $currency
     * @return string
     */
    public function generate_signature(string $reference, int $amount_cents, string $currency): string {
        $config = class_exists('Starter_Wompi_Config') ? Starter_Wompi_Config::get_config() : [];
        $integrity_secret = $config['integrity_secret'] ?? '';
        $concat = $reference . $amount_cents . $currency . $integrity_secret;
        return hash('sha256', $concat);
    }

    /**
     * Verificar transacción con la API de Wompi
     * @param string $transaction_id
     * @return array|WP_Error
     */
    public function verify_transaction(string $transaction_id) {
        $config = class_exists('Starter_Wompi_Config') ? Starter_Wompi_Config::get_config() : [];
        $api_url = $config['api_url'] ?? 'https://sandbox.wompi.co/v1';

        $response = wp_remote_get("{$api_url}/transactions/{$transaction_id}", [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . ($config['private_key'] ?? ''),
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['data'])) {
            return new WP_Error('wompi_invalid_response', 'Respuesta inválida de Wompi');
        }

        return $body['data'];
    }

    /**
     * Verificar autenticidad de webhook de Wompi
     * @param string $payload
     * @param array  $headers
     * @return bool
     */
    public function verify_webhook(string $payload, array $headers): bool {
        $config = class_exists('Starter_Wompi_Config') ? Starter_Wompi_Config::get_config() : [];
        $events_secret = $config['events_secret'] ?? '';

        if (empty($events_secret)) {
            return false;
        }

        // Wompi envía un checksum en el header
        $checksum = $headers['x-event-checksum'] ?? $headers['X-Event-Checksum'] ?? '';
        if (empty($checksum)) {
            return false;
        }

        $data = json_decode($payload, true);
        if (empty($data['signature']['properties']) || empty($data['data']['transaction'])) {
            return false;
        }

        // Construir string de verificación según propiedades indicadas por Wompi
        $transaction = $data['data']['transaction'];
        $properties = $data['signature']['properties'];
        $values = [];
        foreach ($properties as $prop) {
            $parts = explode('.', $prop);
            $value = $transaction;
            foreach ($parts as $part) {
                $value = $value[$part] ?? '';
            }
            $values[] = $value;
        }
        $values[] = $data['timestamp'] ?? '';
        $values[] = $events_secret;

        $concat = implode('', $values);
        $computed_checksum = hash('sha256', $concat);

        return hash_equals($computed_checksum, $checksum);
    }

    /**
     * @return string
     */
    public function get_api_url(): string {
        $config = class_exists('Starter_Wompi_Config') ? Starter_Wompi_Config::get_config() : [];
        return $config['api_url'] ?? 'https://sandbox.wompi.co/v1';
    }

    /**
     * @return bool
     */
    public function is_configured(): bool {
        $public_config = $this->get_public_config();
        return !empty($public_config['public_key']);
    }
}
