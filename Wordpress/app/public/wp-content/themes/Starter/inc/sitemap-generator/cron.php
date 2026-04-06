<?php
/**
 * Sitemap Generator — Cron diario a medianoche.
 *
 * Bug #5 corregido: usa un transient de 1 hora para cachear el estado del cron,
 *   evitando una query a BD en cada request. Hook movido de 'init' a 'wp_loaded'
 *   (se ejecuta después de que plugins y tema están listos, más apropiado para WP-Cron).
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Programa el cron diario a medianoche si no está registrado.
 * Se re-verifica con un transient de 1 hora para evitar queries repetidas a BD.
 */
function starter_schedule_sitemap_cron() {
    $transient_key = 'starter_sitemap_cron_scheduled';

    // Saltar la query a BD si el transient confirma que el cron ya está registrado
    if (get_transient($transient_key)) {
        return;
    }

    if (!wp_next_scheduled(STARTER_SITEMAP_CRON_HOOK)) {
        $timezone = wp_timezone();
        $midnight  = new DateTime('tomorrow midnight', $timezone);
        wp_schedule_event($midnight->getTimestamp(), 'daily', STARTER_SITEMAP_CRON_HOOK);
    }

    // Cachear el resultado 1 hora para no repetir la query en cada request
    set_transient($transient_key, true, HOUR_IN_SECONDS);
}
add_action('wp_loaded', 'starter_schedule_sitemap_cron');

/**
 * Callback del cron: regenera categorías, productos y el sitemap-index.
 */
function starter_run_sitemap_cron() {
    $results = [
        starter_generate_categories_sitemap(),
        starter_generate_products_sitemap(),
        starter_update_sitemap_index(),
    ];

    foreach ($results as $result) {
        $status = $result['success'] ? 'OK' : 'ERROR';
        error_log("[Starter Sitemap Cron] {$status}: {$result['message']}");
    }
}
add_action(STARTER_SITEMAP_CRON_HOOK, 'starter_run_sitemap_cron');

/**
 * Elimina el cron al cambiar de tema para no dejar eventos huérfanos.
 */
function starter_deactivate_sitemap_cron() {
    wp_clear_scheduled_hook(STARTER_SITEMAP_CRON_HOOK);
    delete_transient('starter_sitemap_cron_scheduled');
}
add_action('switch_theme', 'starter_deactivate_sitemap_cron');
