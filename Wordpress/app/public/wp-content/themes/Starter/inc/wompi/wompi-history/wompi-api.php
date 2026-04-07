<?php
/**
 * Wompi History API — Consultas a la API de Wompi
 * 
 * Responsabilidades:
 * - Consultar transacciones individuales por referencia
 * - Caché con transients para no saturar la API
 * - Batch fetch para las transacciones de una página
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consultar la API de Wompi por referencia de transacción
 * Cachea el resultado en un transient por 5 minutos para no saturar la API
 *
 * @param string $reference Referencia de la transacción
 * @return array|null Datos de la transacción Wompi o null si no se encuentra
 */
function starter_wompi_fetch_transaction_by_reference($reference) {
    if (empty($reference)) {
        return null;
    }

    // Cache en transient (5 min)
    $cache_key = 'wompi_tx_' . md5($reference);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached === 'not_found' ? null : $cached;
    }

    $config = Starter_Wompi_Config::get_config();
    if (empty($config['private_key'])) {
        return null;
    }

    $response = wp_remote_get($config['api_url'] . '/transactions?reference=' . urlencode($reference), [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['private_key'],
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        return null;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        set_transient($cache_key, 'not_found', 60);
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $transactions = $body['data'] ?? [];

    if (empty($transactions)) {
        set_transient($cache_key, 'not_found', 60);
        return null;
    }

    // Tomar la transacción más reciente
    $tx = $transactions[0];
    $result = [
        'id'                  => $tx['id'] ?? null,
        'status'              => $tx['status'] ?? null,
        'amount_in_cents'     => $tx['amount_in_cents'] ?? null,
        'currency'            => $tx['currency'] ?? (function_exists('site_get_currency_code') ? site_get_currency_code() : 'COP'),
        'payment_method_type' => $tx['payment_method_type'] ?? null,
        'payment_method'      => $tx['payment_method'] ?? [],
        'created_at'          => $tx['created_at'] ?? null,
        'finalized_at'        => $tx['finalized_at'] ?? null,
    ];

    set_transient($cache_key, $result, 300);
    return $result;
}

/**
 * Obtener datos Wompi para un lote de referencias (las de la página actual)
 *
 * @param array $references Lista de referencias
 * @return array Mapa reference => datos Wompi
 */
function starter_wompi_batch_fetch($references) {
    $map = [];
    foreach ($references as $ref) {
        if (!empty($ref)) {
            $map[$ref] = starter_wompi_fetch_transaction_by_reference($ref);
        }
    }
    return $map;
}
