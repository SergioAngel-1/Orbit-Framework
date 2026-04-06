<?php
/**
 * Reviews - Funciones auxiliares
 * 
 * Helpers compartidos por los endpoints de reseñas y calificación de pedidos.
 * Incluye: avatar, membresía, verified buyer, formato de comentario, estadísticas, rating update.
 * 
 * @package Starter
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener avatar de un usuario (custom → Gravatar fallback)
 * 
 * @param int $user_id
 * @return string URL del avatar
 */
function starter_reviews_get_avatar_url($user_id) {
    // Intentar avatar custom primero
    if (function_exists('starter_get_custom_avatar_url')) {
        $custom = starter_get_custom_avatar_url($user_id);
        if ($custom) {
            return $custom;
        }
    }
    // Fallback a Gravatar via WP
    return get_avatar_url($user_id, array('size' => 96));
}

/**
 * Obtener nivel de membresía de un usuario
 * 
 * @param int $user_id
 * @return int Nivel de membresía (0 = sin membresía)
 */
function starter_reviews_get_membership_level($user_id) {
    $level = get_user_meta($user_id, 'membership_level', true);
    return $level ? intval($level) : 0;
}

/**
 * Verificar si un usuario compró un producto específico
 * 
 * @param int $user_id
 * @param int $product_id
 * @return bool
 */
function starter_reviews_is_verified_buyer($user_id, $product_id) {
    if (!function_exists('wc_customer_bought_product')) {
        return false;
    }
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    return wc_customer_bought_product($user->user_email, $user_id, $product_id);
}

/**
 * Verificar si el usuario ya dejó una reseña en este producto
 * 
 * @param int $user_id
 * @param int $product_id
 * @return bool
 */
function starter_reviews_user_already_reviewed($user_id, $product_id) {
    $existing = get_comments(array(
        'post_id' => $product_id,
        'user_id' => $user_id,
        'type'    => 'review',
        'status'  => 'approve',
        'count'   => true,
        'parent'  => 0,
    ));
    return $existing > 0;
}

/**
 * Obtener todos los product_ids que un usuario ya reseñó (batch, un solo query)
 * Útil para evitar N+1 queries en pending-orders y rate-order.
 * 
 * @param int $user_id
 * @return array<int, true> Mapa associativo [product_id => true]
 */
function starter_reviews_get_user_reviewed_products($user_id) {
    global $wpdb;
    
    $product_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT comment_post_ID
        FROM {$wpdb->comments}
        WHERE user_id = %d
          AND comment_type = 'review'
          AND comment_approved = '1'
          AND comment_parent = 0
    ", $user_id));
    
    $map = array();
    foreach ($product_ids as $pid) {
        $map[intval($pid)] = true;
    }
    return $map;
}

/**
 * Formatear un comentario/reseña como objeto enriquecido
 * 
 * @param WP_Comment $comment
 * @param bool $include_replies Si incluir respuestas anidadas
 * @return array
 */
function starter_reviews_format_comment($comment, $include_replies = true) {
    $user_id = intval($comment->user_id);
    $is_registered = $user_id > 0;
    
    // Datos del autor
    $author_name = $comment->comment_author;
    $avatar = '';
    $membership_level = 0;
    $is_admin = false;
    
    if ($is_registered) {
        $user = get_userdata($user_id);
        if ($user) {
            $author_name = $user->display_name;
            $is_admin = user_can($user_id, 'manage_woocommerce');
        }
        $avatar = starter_reviews_get_avatar_url($user_id);
        $membership_level = starter_reviews_get_membership_level($user_id);
    }
    
    // Rating (solo reseñas padre tienen rating, no las respuestas)
    $rating = intval(get_comment_meta($comment->comment_ID, 'rating', true));
    
    // Verified buyer
    $verified = get_comment_meta($comment->comment_ID, 'verified', true);
    $is_verified_buyer = $verified === '1' || $verified === 1;
    
    $formatted = array(
        'id'               => intval($comment->comment_ID),
        'product_id'       => intval($comment->comment_post_ID),
        'author'           => array(
            'id'               => $user_id,
            'name'             => $author_name,
            'avatar'           => $avatar,
            'membership_level' => $membership_level,
            'is_admin'         => $is_admin,
        ),
        'rating'           => $rating,
        'review'           => wp_kses_post($comment->comment_content),
        'verified_buyer'   => $is_verified_buyer,
        'date_created'     => $comment->comment_date_gmt,
        'status'           => $comment->comment_approved === '1' ? 'approved' : 'pending',
    );
    
    // Respuestas anidadas (solo un nivel de profundidad)
    if ($include_replies) {
        $replies_raw = get_comments(array(
            'parent'  => $comment->comment_ID,
            'post_id' => $comment->comment_post_ID,
            'status'  => 'approve',
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
        ));
        
        $replies = array();
        foreach ($replies_raw as $reply) {
            $replies[] = starter_reviews_format_comment($reply, false);
        }
        $formatted['replies'] = $replies;
    }
    
    return $formatted;
}

/**
 * Calcular estadísticas de rating de un producto
 * 
 * @param int $product_id
 * @return array
 */
function starter_reviews_get_stats($product_id) {
    global $wpdb;
    
    // Query directa en DB para calcular distribución sin cargar todos los comentarios en memoria
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT CAST(cm.meta_value AS UNSIGNED) AS rating, COUNT(*) AS cnt
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        WHERE c.comment_post_ID = %d
          AND c.comment_type = 'review'
          AND c.comment_approved = '1'
          AND c.comment_parent = 0
          AND cm.meta_key = 'rating'
          AND cm.meta_value > 0
        GROUP BY rating
    ", $product_id));
    
    $distribution = array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0);
    $total = 0;
    $sum = 0;
    
    foreach ($rows as $row) {
        $r = intval($row->rating);
        $c = intval($row->cnt);
        if ($r >= 1 && $r <= 5) {
            $distribution[$r] = $c;
            $total += $c;
            $sum += $r * $c;
        }
    }
    
    $average = $total > 0 ? round($sum / $total, 1) : 0;
    
    // Puntos por reseña (informativo para el frontend)
    $review_points = 0;
    if (function_exists('starter_rp_is_points_event_enabled') && function_exists('Starter_RP') && starter_rp_is_points_event_enabled('review')) {
        $options = Starter_RP()->get_options();
        $review_points = intval($options['points_review'] ?? 50);
    }
    
    return array(
        'average'      => $average,
        'total'        => $total,
        'distribution' => $distribution,
        'review_points' => $review_points,
    );
}

/**
 * Actualizar el rating promedio y conteo de un producto en WooCommerce
 * WooCommerce almacena esto en post meta para rendimiento
 * 
 * @param int $product_id
 */
function starter_reviews_update_product_rating($product_id) {
    global $wpdb;
    
    $ratings = $wpdb->get_results($wpdb->prepare("
        SELECT cm.meta_value as rating
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        WHERE c.comment_post_ID = %d
          AND c.comment_type = 'review'
          AND c.comment_approved = '1'
          AND c.comment_parent = 0
          AND cm.meta_key = 'rating'
          AND cm.meta_value > 0
    ", $product_id));
    
    $count = count($ratings);
    $sum = 0;
    foreach ($ratings as $r) {
        $sum += intval($r->rating);
    }
    $average = $count > 0 ? round($sum / $count, 2) : 0;
    
    update_post_meta($product_id, '_wc_average_rating', $average);
    update_post_meta($product_id, '_wc_review_count', $count);
    update_post_meta($product_id, '_wc_rating_count', $count);
    
    // Limpiar cache de WooCommerce para este producto
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
}
