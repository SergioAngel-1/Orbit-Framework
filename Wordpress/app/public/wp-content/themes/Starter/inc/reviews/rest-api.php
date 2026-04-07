<?php
/**
 * Reviews - REST API Endpoints (Reseñas de Producto)
 * 
 * Endpoints para reseñas individuales de producto:
 *   GET  /starter/v1/reviews/{product_id}              → Lista de reseñas enriquecidas
 *   GET  /starter/v1/reviews/can-review/{product_id}   → ¿Puede el usuario reseñar?
 *   POST /starter/v1/reviews                            → Crear reseña (verified buyer only)
 *   POST /starter/v1/reviews/{review_id}/reply          → Responder a una reseña
 * 
 * @package Starter
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints de reseñas de producto
 */
function starter_register_product_reviews_endpoints() {
    // GET: Listar reseñas enriquecidas de un producto
    register_rest_route('starter/v1', '/reviews/(?P<product_id>\d+)', array(
        'methods'  => 'GET',
        'callback' => 'starter_get_product_reviews',
        'permission_callback' => '__return_true',
        'args' => array(
            'product_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && intval($param) > 0;
                },
                'sanitize_callback' => 'absint',
            ),
            'page' => array(
                'default' => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'default' => 10,
                'sanitize_callback' => 'absint',
            ),
            'rating' => array(
                'default' => 0,
                'sanitize_callback' => 'absint',
                'validate_callback' => function($param) {
                    $val = intval($param);
                    return $val >= 0 && $val <= 5;
                },
            ),
        ),
    ));

    // GET: ¿Puede el usuario crear reseña para este producto?
    register_rest_route('starter/v1', '/reviews/can-review/(?P<product_id>\d+)', array(
        'methods'  => 'GET',
        'callback' => 'starter_can_review_product',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
        'args' => array(
            'product_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && intval($param) > 0;
                },
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    // POST: Crear reseña (solo verified buyers)
    register_rest_route('starter/v1', '/reviews', array(
        'methods'  => 'POST',
        'callback' => 'starter_create_review',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
    ));

    // POST: Responder a una reseña
    register_rest_route('starter/v1', '/reviews/(?P<review_id>\d+)/reply', array(
        'methods'  => 'POST',
        'callback' => 'starter_reply_to_review',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
        'args' => array(
            'review_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && intval($param) > 0;
                },
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
}
add_action('rest_api_init', 'starter_register_product_reviews_endpoints');

// ─────────────────────────────────────────────────────────────
// Endpoint Callbacks
// ─────────────────────────────────────────────────────────────

/**
 * GET /starter/v1/reviews/{product_id}
 * Retorna reseñas enriquecidas con estadísticas
 */
function starter_get_product_reviews(WP_REST_Request $request) {
    global $wpdb;

    $product_id = $request->get_param('product_id');
    $page = max(1, $request->get_param('page'));
    $per_page = min(50, max(1, $request->get_param('per_page')));
    $rating_filter = intval($request->get_param('rating'));
    $offset = ($page - 1) * $per_page;
    
    // Verificar que el producto existe
    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('product_not_found', 'Producto no encontrado.', array('status' => 404));
    }
    
    // Obtener reseñas padre (no respuestas), con filtro opcional por rating
    if ($rating_filter >= 1 && $rating_filter <= 5) {
        // Con filtro: query SQL directa para filtrar por meta rating
        $comment_ids = $wpdb->get_col($wpdb->prepare("
            SELECT c.comment_ID
            FROM {$wpdb->comments} c
            INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
            WHERE c.comment_post_ID = %d
              AND c.comment_type = 'review'
              AND c.comment_approved = '1'
              AND c.comment_parent = 0
              AND cm.meta_key = 'rating'
              AND CAST(cm.meta_value AS UNSIGNED) = %d
            ORDER BY c.comment_date_gmt DESC
            LIMIT %d OFFSET %d
        ", $product_id, $rating_filter, $per_page, $offset));

        $reviews_raw = array();
        foreach ($comment_ids as $cid) {
            $c = get_comment($cid);
            if ($c) $reviews_raw[] = $c;
        }

        // Total filtrado para paginación
        $total = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->comments} c
            INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
            WHERE c.comment_post_ID = %d
              AND c.comment_type = 'review'
              AND c.comment_approved = '1'
              AND c.comment_parent = 0
              AND cm.meta_key = 'rating'
              AND CAST(cm.meta_value AS UNSIGNED) = %d
        ", $product_id, $rating_filter));
    } else {
        // Sin filtro: query estándar via get_comments
        $reviews_raw = get_comments(array(
            'post_id' => $product_id,
            'type'    => 'review',
            'status'  => 'approve',
            'parent'  => 0,
            'number'  => $per_page,
            'offset'  => $offset,
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ));
        $total = -1; // se calcula abajo con stats
    }
    
    $reviews = array();
    foreach ($reviews_raw as $review) {
        $reviews[] = starter_reviews_format_comment($review, true);
    }
    
    // Estadísticas (siempre sin filtro, muestra distribución completa)
    $stats = starter_reviews_get_stats($product_id);
    
    // Total para paginación: si no hay filtro, usar stats['total']
    if ($total < 0) {
        $total = $stats['total'];
    }
    
    return new WP_REST_Response(array(
        'reviews'       => $reviews,
        'stats'         => $stats,
        'page'          => $page,
        'per_page'      => $per_page,
        'total'         => $total,
        'pages'         => $total > 0 ? (int) ceil($total / $per_page) : 0,
        'active_filter' => $rating_filter > 0 ? $rating_filter : null,
    ), 200);
}

/**
 * GET /starter/v1/reviews/can-review/{product_id}
 * Verifica si el usuario actual puede dejar reseña
 */
function starter_can_review_product(WP_REST_Request $request) {
    $product_id = $request->get_param('product_id');
    $user_id = get_current_user_id();
    
    // Verificar que el producto existe
    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('product_not_found', 'Producto no encontrado.', array('status' => 404));
    }
    
    // Verificar si es verified buyer
    $is_buyer = starter_reviews_is_verified_buyer($user_id, $product_id);
    
    // Verificar si ya dejó reseña
    $already_reviewed = starter_reviews_user_already_reviewed($user_id, $product_id);
    
    // Puntos disponibles por reseña (informativo)
    $review_points = 0;
    if (function_exists('Starter_RP')) {
        $options = Starter_RP()->get_options();
        $review_points = intval($options['points_review'] ?? 50);
    }
    
    $can_review = $is_buyer && !$already_reviewed;
    
    $reason = null;
    if (!$is_buyer) {
        $reason = 'not_verified_buyer';
    } elseif ($already_reviewed) {
        $reason = 'already_reviewed';
    }
    
    return new WP_REST_Response(array(
        'can_review'       => $can_review,
        'is_verified_buyer' => $is_buyer,
        'already_reviewed' => $already_reviewed,
        'reason'           => $reason,
        'review_points'    => $review_points,
    ), 200);
}

/**
 * POST /starter/v1/reviews
 * Crear una nueva reseña de producto
 * 
 * Body esperado: { product_id: int, rating: int (1-5), review: string }
 */
function starter_create_review(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $params = $request->get_json_params();
    
    // Validar campos requeridos
    if (empty($params['product_id']) || empty($params['rating']) || empty($params['review'])) {
        return new WP_Error(
            'missing_fields',
            'Campos requeridos: product_id, rating, review.',
            array('status' => 400)
        );
    }
    
    $product_id = absint($params['product_id']);
    $rating = absint($params['rating']);
    $review_text = sanitize_textarea_field($params['review']);
    
    // Validar rating
    if ($rating < 1 || $rating > 5) {
        return new WP_Error('invalid_rating', 'El rating debe ser entre 1 y 5.', array('status' => 400));
    }
    
    // Validar longitud del texto
    if (mb_strlen($review_text) < 10) {
        return new WP_Error('review_too_short', 'La reseña debe tener al menos 10 caracteres.', array('status' => 400));
    }
    if (mb_strlen($review_text) > 2000) {
        return new WP_Error('review_too_long', 'La reseña no puede exceder 2000 caracteres.', array('status' => 400));
    }
    
    // Verificar que el producto existe
    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('product_not_found', 'Producto no encontrado.', array('status' => 404));
    }
    
    // Verificar que es verified buyer
    if (!starter_reviews_is_verified_buyer($user_id, $product_id)) {
        return new WP_Error(
            'not_verified_buyer',
            'Solo puedes reseñar productos que hayas comprado.',
            array('status' => 403)
        );
    }
    
    // Verificar que no haya reseñado ya
    if (starter_reviews_user_already_reviewed($user_id, $product_id)) {
        return new WP_Error(
            'already_reviewed',
            'Ya has dejado una reseña para este producto.',
            array('status' => 409)
        );
    }
    
    $user = get_userdata($user_id);
    
    // Crear el comentario como WooCommerce review
    $comment_data = array(
        'comment_post_ID'  => $product_id,
        'comment_author'   => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_content'  => $review_text,
        'comment_type'     => 'review',
        'comment_parent'   => 0,
        'user_id'          => $user_id,
        'comment_approved' => 1,
    );
    
    $comment_id = wp_insert_comment($comment_data);
    
    if (!$comment_id || is_wp_error($comment_id)) {
        return new WP_Error(
            'review_creation_failed',
            'No se pudo crear la reseña.',
            array('status' => 500)
        );
    }
    
    // Guardar rating como meta del comentario (estándar WooCommerce)
    update_comment_meta($comment_id, 'rating', $rating);
    
    // Marcar como verified buyer
    update_comment_meta($comment_id, 'verified', 1);
    
    // Actualizar el rating promedio del producto (WooCommerce lo espera)
    starter_reviews_update_product_rating($product_id);
    
    // Invalidar caché de reviews
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type('reviews');
    }
    
    // Obtener el comentario formateado para la respuesta
    $comment = get_comment($comment_id);
    $formatted = starter_reviews_format_comment($comment, true);
    
    // Los Virtual Coins se otorgan automáticamente via el hook wp_insert_comment
    // → starter_rp_process_review_points (points-events.php).
    // Solo leemos el meta que ese hook ya guardó para informar al frontend.
    $points_awarded = (int) get_comment_meta($comment_id, '_starter_review_points_awarded', true);

    return new WP_REST_Response(array(
        'success' => true,
        'review'  => $formatted,
        'points_awarded' => $points_awarded,
    ), 201);
}

/**
 * POST /starter/v1/reviews/{review_id}/reply
 * Responder a una reseña existente
 * 
 * Body esperado: { content: string }
 */
function starter_reply_to_review(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $review_id = $request->get_param('review_id');
    $params = $request->get_json_params();
    
    // Validar contenido
    if (empty($params['content'])) {
        return new WP_Error('missing_content', 'El contenido de la respuesta es requerido.', array('status' => 400));
    }
    
    $content = sanitize_textarea_field($params['content']);
    
    if (mb_strlen($content) < 10) {
        return new WP_Error('reply_too_short', 'La respuesta debe tener al menos 10 caracteres.', array('status' => 400));
    }
    if (mb_strlen($content) > 1000) {
        return new WP_Error('reply_too_long', 'La respuesta no puede exceder 1000 caracteres.', array('status' => 400));
    }
    
    // Verificar que la reseña padre existe y está aprobada
    $parent_comment = get_comment($review_id);
    if (!$parent_comment || $parent_comment->comment_approved !== '1') {
        return new WP_Error('review_not_found', 'Reseña no encontrada.', array('status' => 404));
    }
    
    // Solo permitir respuestas a reseñas padre (no a respuestas de respuestas)
    if (intval($parent_comment->comment_parent) !== 0) {
        return new WP_Error('invalid_parent', 'Solo puedes responder a reseñas, no a otras respuestas.', array('status' => 400));
    }
    
    // Limitar a máximo 3 respuestas por reseña
    $existing_replies = get_comments(array(
        'parent'  => $review_id,
        'post_id' => $parent_comment->comment_post_ID,
        'count'   => true,
    ));
    if ($existing_replies >= 3) {
        return new WP_Error('max_replies_reached', 'Esta reseña ya tiene el máximo de respuestas permitidas.', array('status' => 400));
    }
    
    $user = get_userdata($user_id);
    
    $comment_data = array(
        'comment_post_ID'      => $parent_comment->comment_post_ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_content'      => $content,
        'comment_type'         => 'review',
        'comment_parent'       => $review_id,
        'user_id'              => $user_id,
        'comment_approved'     => 1,
    );
    
    $comment_id = wp_insert_comment($comment_data);
    
    if (!$comment_id || is_wp_error($comment_id)) {
        return new WP_Error(
            'reply_creation_failed',
            'No se pudo crear la respuesta.',
            array('status' => 500)
        );
    }
    
    // Invalidar caché de reviews
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type('reviews');
    }
    
    $comment = get_comment($comment_id);
    $formatted = starter_reviews_format_comment($comment, false);
    
    return new WP_REST_Response(array(
        'success' => true,
        'reply'   => $formatted,
    ), 201);
}
