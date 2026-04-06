<?php
/**
 * Reviews - Listado general de reseñas
 * 
 * Endpoint público para obtener todas las reseñas del sitio con filtros:
 *   GET /starter/v1/reviews/listing  → Listado paginado con filtro por estrellas
 * 
 * @package Starter
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoint de listado general de reseñas
 */
function starter_register_reviews_listing_endpoint() {
    register_rest_route('starter/v1', '/reviews/listing', array(
        'methods'  => 'GET',
        'callback' => 'starter_get_reviews_listing',
        'permission_callback' => '__return_true',
        'args' => array(
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
            'orderby' => array(
                'default' => 'date',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return in_array($param, array('date', 'rating'), true);
                },
            ),
            'order' => array(
                'default' => 'DESC',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return in_array(strtoupper($param), array('ASC', 'DESC'), true);
                },
            ),
        ),
    ));
}
add_action('rest_api_init', 'starter_register_reviews_listing_endpoint');

// ─────────────────────────────────────────────────────────────
// Endpoint Callback
// ─────────────────────────────────────────────────────────────

/**
 * GET /starter/v1/reviews/listing
 * 
 * Retorna un listado paginado de reseñas de todos los productos,
 * con filtro opcional por cantidad de estrellas y estadísticas globales.
 * 
 * Params:
 *   - page     (int)    Página actual (default 1)
 *   - per_page (int)    Items por página (default 10, max 50)
 *   - rating   (int)    Filtrar por estrellas: 1-5 (0 = todas)
 *   - orderby  (string) Ordenar por: date | rating
 *   - order    (string) Dirección: ASC | DESC
 */
function starter_get_reviews_listing(WP_REST_Request $request) {
    global $wpdb;

    $page     = max(1, $request->get_param('page'));
    $per_page = min(50, max(1, $request->get_param('per_page')));
    $rating   = intval($request->get_param('rating'));
    $orderby  = $request->get_param('orderby');
    $order    = strtoupper($request->get_param('order'));
    $offset   = ($page - 1) * $per_page;

    // ── Construir WHERE dinámico ──
    $where_clauses = array(
        "c.comment_type = 'review'",
        "c.comment_approved = '1'",
        "c.comment_parent = 0",
        "cm.meta_key = 'rating'",
        "cm.meta_value > 0",
    );
    $where_values = array();

    // Filtro por estrellas
    if ($rating >= 1 && $rating <= 5) {
        $where_clauses[] = "CAST(cm.meta_value AS UNSIGNED) = %d";
        $where_values[] = $rating;
    }

    $where_sql = implode(' AND ', $where_clauses);

    // ── ORDER BY ──
    if ($orderby === 'rating') {
        $order_sql = "CAST(cm.meta_value AS UNSIGNED) {$order}, c.comment_date_gmt DESC";
    } else {
        $order_sql = "c.comment_date_gmt {$order}";
    }

    // ── Contar total (con filtro aplicado) ──
    $count_query = "
        SELECT COUNT(*)
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        WHERE {$where_sql}
    ";
    if (!empty($where_values)) {
        $count_query = $wpdb->prepare($count_query, ...$where_values);
    }
    $total = (int) $wpdb->get_var($count_query);

    // ── Obtener reseñas paginadas ──
    $select_query = "
        SELECT c.comment_ID
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        WHERE {$where_sql}
        ORDER BY {$order_sql}
        LIMIT %d OFFSET %d
    ";
    $select_values = array_merge($where_values, array($per_page, $offset));
    $comment_ids = $wpdb->get_col($wpdb->prepare($select_query, ...$select_values));

    // Formatear cada reseña con datos enriquecidos + nombre del producto
    $reviews = array();
    foreach ($comment_ids as $comment_id) {
        $comment = get_comment($comment_id);
        if (!$comment) continue;

        $formatted = starter_reviews_format_comment($comment, true);

        // Agregar datos del producto (nombre + imagen)
        $product_id = intval($comment->comment_post_ID);
        $product = wc_get_product($product_id);
        if ($product) {
            $image_id = $product->get_image_id();
            $formatted['product'] = array(
                'id'    => $product_id,
                'name'  => $product->get_name(),
                'slug'  => $product->get_slug(),
                'image' => $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '',
            );
        } else {
            $formatted['product'] = array(
                'id'    => $product_id,
                'name'  => get_the_title($product_id) ?: '',
                'slug'  => '',
                'image' => '',
            );
        }

        $reviews[] = $formatted;
    }

    // ── Estadísticas globales (sin filtro de rating) ──
    $stats = starter_reviews_get_global_stats();

    return new WP_REST_Response(array(
        'reviews'      => $reviews,
        'stats'        => $stats,
        'page'         => $page,
        'per_page'     => $per_page,
        'total'        => $total,
        'pages'        => $total > 0 ? (int) ceil($total / $per_page) : 0,
        'active_filter' => $rating > 0 ? $rating : null,
    ), 200);
}

/**
 * Estadísticas globales de reseñas (todos los productos)
 * 
 * @return array { average, total, distribution: {1:n, 2:n, 3:n, 4:n, 5:n} }
 */
function starter_reviews_get_global_stats() {
    global $wpdb;

    $rows = $wpdb->get_results("
        SELECT CAST(cm.meta_value AS UNSIGNED) AS rating, COUNT(*) AS cnt
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        WHERE c.comment_type = 'review'
          AND c.comment_approved = '1'
          AND c.comment_parent = 0
          AND cm.meta_key = 'rating'
          AND cm.meta_value > 0
        GROUP BY rating
    ");

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

    return array(
        'average'      => $average,
        'total'        => $total,
        'distribution' => $distribution,
    );
}
