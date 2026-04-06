<?php
/**
 * Eventos que otorgan puntos
 * 
 * Funciones para procesar puntos por diferentes eventos configurables.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar eventos de puntos
 */
function starter_rp_init_points_events() {
    // Solo registrar hooks para eventos que estén habilitados en la configuración.
    // Esto evita ejecución innecesaria y logs de "acceso denegado" cuando
    // el evento está deshabilitado (ej: registration no está en point_triggers).
    
    if (starter_rp_is_points_event_enabled('registration')) {
        add_action('user_register', 'starter_rp_process_registration_points');
    }
    
    if (starter_rp_is_points_event_enabled('review')) {
        add_action('wp_insert_comment', 'starter_rp_process_review_points', 10, 2);
    }
    
    if (starter_rp_is_points_event_enabled('birthday')) {
        add_action('starter_rp_daily_maintenance', 'starter_rp_process_birthday_points');
    }
}

/**
 * Verificar si un evento está habilitado para otorgar puntos
 * 
 * @param string $event Evento a verificar (registration, review, birthday, purchase)
 * @return bool
 */
function starter_rp_is_points_event_enabled($event) {
    // Verificar si el sistema de puntos está habilitado globalmente
    if (!starter_rp_is_points_system_enabled()) {
        return false;
    }
    
    $options = Starter_RP()->get_options();
    $enabled_triggers = $options['point_triggers'] ?? ['purchase'];
    
    return in_array($event, $enabled_triggers);
}

/**
 * Procesar puntos por registro de usuario
 * 
 * @param int $user_id ID del usuario registrado
 */
function starter_rp_process_registration_points($user_id) {
    // El hook solo se registra si el evento está habilitado (ver init_points_events),
    // pero verificamos de nuevo como guard defensivo por si se llama manualmente.
    if (!starter_rp_is_points_event_enabled('registration')) {
        return;
    }
    
    // Verificar si el usuario puede participar en el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return;
    }
    
    $options = Starter_RP()->get_options();
    $registration_points = (int) ($options['points_registration'] ?? 100);
    
    if ($registration_points > 0) {
        $description = 'Puntos de bienvenida por registro en la tienda';
        
        $result = starter_rp_add_points(
            $user_id,
            $registration_points,
            'registration',
            $description,
            null,
            $options['points_expiry_days'] ?? 365
        );
        
        if ($result) {
            starter_rp_log("Starter RP: Otorgados $registration_points puntos por registro al usuario ID: $user_id");
        }
    }
}

/**
 * Procesar puntos por escribir reseña
 * 
 * @param int $comment_id ID del comentario
 * @param object $comment Objeto del comentario
 */
function starter_rp_process_review_points($comment_id, $comment) {
    // Solo procesar comentarios en productos
    if ($comment->comment_type !== 'review') {
        return;
    }
    
    // Verificar si es una reseña de producto de WooCommerce
    $post = get_post($comment->comment_post_ID);
    if (!$post || $post->post_type !== 'product') {
        return;
    }
    
    // Verificar si el evento está habilitado
    if (!starter_rp_is_points_event_enabled('review')) {
        return;
    }
    
    $user_id = $comment->user_id;
    
    // Solo para usuarios registrados
    if (!$user_id) {
        return;
    }
    
    // Verificar si el usuario puede participar en el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        starter_rp_log_access_denied('review_points', $user_id, 'Usuario no puede participar en sistema de puntos');
        return;
    }
    
    // Verificar que no se hayan otorgado puntos ya por esta reseña
    $existing_points = get_comment_meta($comment_id, '_starter_review_points_awarded', true);
    if ($existing_points) {
        return;
    }
    
    $options = Starter_RP()->get_options();
    $review_points = (int) ($options['points_review'] ?? 50);
    
    if ($review_points > 0) {
        $product_name = get_the_title($comment->comment_post_ID);
        $description = sprintf('Puntos por escribir reseña del producto: %s', $product_name);
        
        $result = starter_rp_add_points(
            $user_id,
            $review_points,
            'review',
            $description,
            $comment_id,
            $options['points_expiry_days'] ?? 365
        );
        
        if ($result) {
            // Marcar que se otorgaron puntos por esta reseña
            update_comment_meta($comment_id, '_starter_review_points_awarded', $review_points);
            starter_rp_log("Starter RP: Otorgados $review_points puntos por reseña al usuario ID: $user_id");
        }
    }
}

/**
 * Procesar puntos por cumpleaños (ejecutado diariamente)
 */
function starter_rp_process_birthday_points() {
    // Verificar si el evento está habilitado
    if (!starter_rp_is_points_event_enabled('birthday')) {
        return;
    }
    
    $options = Starter_RP()->get_options();
    $birthday_points = (int) ($options['points_birthday'] ?? 200);
    
    if ($birthday_points <= 0) {
        return;
    }
    
    // Obtener la fecha actual
    $today = date('m-d'); // Formato MM-DD
    
    // Buscar usuarios que cumplan años hoy
    $users = get_users([
        'meta_query' => [
            [
                'key' => 'birthday',
                'value' => $today,
                'compare' => 'LIKE'
            ]
        ]
    ]);
    
    foreach ($users as $user) {
        // Verificar si el usuario puede participar en el sistema de puntos
        if (!starter_rp_can_user_use_points($user->ID)) {
            continue;
        }
        
        // Verificar si ya se otorgaron puntos este año
        $current_year = date('Y');
        $birthday_year_meta = get_user_meta($user->ID, '_starter_birthday_points_' . $current_year, true);
        
        if ($birthday_year_meta) {
            continue; // Ya se otorgaron puntos este año
        }
        
        $description = sprintf('Puntos por cumpleaños (%s)', date('d/m/Y'));
        
        $result = starter_rp_add_points(
            $user->ID,
            $birthday_points,
            'birthday',
            $description,
            null,
            $options['points_expiry_days'] ?? 365
        );
        
        if ($result) {
            // Marcar que se otorgaron puntos este año
            update_user_meta($user->ID, '_starter_birthday_points_' . $current_year, $birthday_points);
            starter_rp_log("Starter RP: Otorgados $birthday_points puntos por cumpleaños al usuario ID: {$user->ID}");
        }
    }
}

/**
 * Otorgar puntos manualmente por evento específico
 * 
 * @param int $user_id ID del usuario
 * @param string $event Tipo de evento
 * @param int $points Cantidad de puntos (opcional, usa configuración si no se especifica)
 * @param string $description Descripción personalizada (opcional)
 * @return bool Éxito de la operación
 */
function starter_rp_award_event_points($user_id, $event, $points = null, $description = null) {
    // Verificar si el evento está habilitado
    if (!starter_rp_is_points_event_enabled($event)) {
        return false;
    }
    
    // Verificar si el usuario puede participar
    if (!starter_rp_can_user_use_points($user_id)) {
        return false;
    }
    
    $options = Starter_RP()->get_options();
    
    // Obtener puntos de configuración si no se especifica
    if ($points === null) {
        switch ($event) {
            case 'registration':
                $points = (int) ($options['points_registration'] ?? 100);
                break;
            case 'review':
                $points = (int) ($options['points_review'] ?? 50);
                break;
            case 'birthday':
                $points = (int) ($options['points_birthday'] ?? 200);
                break;
            default:
                return false;
        }
    }
    
    if ($points <= 0) {
        return false;
    }
    
    // Descripción por defecto si no se especifica
    if ($description === null) {
        $event_names = [
            'registration' => 'registro',
            'review' => 'escribir reseña',
            'birthday' => 'cumpleaños'
        ];
        $description = sprintf('Puntos por %s', $event_names[$event] ?? $event);
    }
    
    return starter_rp_add_points(
        $user_id,
        $points,
        $event,
        $description,
        null,
        $options['points_expiry_days'] ?? 365
    );
}

/**
 * Obtener estadísticas de puntos por eventos
 * 
 * @return array Estadísticas por tipo de evento
 */
function starter_rp_get_points_events_stats() {
    global $wpdb;
    
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    
    $stats = $wpdb->get_results("
        SELECT 
            type,
            COUNT(*) as total_transactions,
            SUM(points) as total_points,
            AVG(points) as average_points
        FROM $transactions_table 
        WHERE type IN ('registration', 'review', 'birthday', 'earned')
        GROUP BY type
        ORDER BY total_points DESC
    ", ARRAY_A);
    
    $formatted_stats = [];
    foreach ($stats as $stat) {
        $formatted_stats[$stat['type']] = [
            'total_transactions' => (int) $stat['total_transactions'],
            'total_points' => (int) $stat['total_points'],
            'average_points' => round((float) $stat['average_points'], 2)
        ];
    }
    
    return $formatted_stats;
} 