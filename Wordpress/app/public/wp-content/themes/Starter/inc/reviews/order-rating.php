<?php
/**
 * Reviews - Confirmación y Calificación de Pedido
 * 
 * Endpoints:
 *   GET  /starter/v1/reviews/pending-orders    → Pedidos pendientes (processing sin confirmar + completed sin rating)
 *   POST /starter/v1/reviews/confirm-order      → Confirmar recepción (processing → completed)
 *   POST /starter/v1/reviews/rate-order          → Calificar pedido (crea reviews en productos)
 * 
 * @package Starter
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints de calificación de pedido
 */
function starter_register_order_rating_endpoints() {
    // GET: Pedidos completados pendientes de calificar
    register_rest_route('starter/v1', '/reviews/pending-orders', array(
        'methods'  => 'GET',
        'callback' => 'starter_get_pending_orders_for_rating',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
    ));

    // POST: Confirmar recepción de pedido (processing → completed)
    register_rest_route('starter/v1', '/reviews/confirm-order', array(
        'methods'  => 'POST',
        'callback' => 'starter_confirm_order_received',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
    ));

    // POST: Calificar un pedido (crea reviews en todos sus productos)
    register_rest_route('starter/v1', '/reviews/rate-order', array(
        'methods'  => 'POST',
        'callback' => 'starter_rate_order',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
    ));
}
add_action('rest_api_init', 'starter_register_order_rating_endpoints');

// ─────────────────────────────────────────────────────────────
// Endpoint Callbacks
// ─────────────────────────────────────────────────────────────

/**
 * Extraer items de producto de un pedido WC para la respuesta de la API
 * 
 * @param WC_Order $order Pedido de WooCommerce
 * @param int $user_id ID del usuario (0 = no verificar reseñas)
 * @param array|null $reviewed_map Mapa pre-cargado [product_id => true] para evitar N+1 queries.
 *                                 Si es null, se consulta individualmente (compatibilidad).
 */
function starter_extract_order_items($order, $user_id = 0, $reviewed_map = null) {
    $items = array();
    $all_reviewed = true;
    $has_products = false;
    
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        
        if (!$product) continue;
        $has_products = true;
        
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
        
        $already_reviewed = false;
        if ($user_id > 0) {
            if ($reviewed_map !== null) {
                $already_reviewed = isset($reviewed_map[$product_id]);
            } else {
                $already_reviewed = starter_reviews_user_already_reviewed($user_id, $product_id);
            }
            if (!$already_reviewed) {
                $all_reviewed = false;
            }
        } else {
            $all_reviewed = false;
        }
        
        $items[] = array(
            'product_id'       => $product_id,
            'name'             => $item->get_name(),
            'image'            => $image_url,
            'quantity'         => $item->get_quantity(),
            'already_reviewed' => $already_reviewed,
        );
    }
    
    return array(
        'items'                => $items,
        'all_products_reviewed' => $has_products && $all_reviewed,
    );
}

/**
 * GET /starter/v1/reviews/pending-orders
 * Retorna 2 listas:
 *   - pending_confirmation: pedidos en processing (sin meta _starter_order_rated)
 *   - completed_unrated: pedidos completed (sin meta _starter_order_rated)
 */
function starter_get_pending_orders_for_rating(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    
    if (!function_exists('wc_get_orders')) {
        return new WP_Error('wc_not_available', 'WooCommerce no está disponible.', array('status' => 500));
    }
    
    // Pre-cargar mapa de productos ya reseñados por el usuario (1 solo query SQL)
    $reviewed_map = starter_reviews_get_user_reviewed_products($user_id);
    
    $base_args = array(
        'customer_id' => $user_id,
        'limit'       => 50,
        'orderby'     => 'date',
        'order'       => 'ASC',
        'meta_query'  => array(
            array(
                'key'     => '_starter_order_rated',
                'compare' => 'NOT EXISTS',
            ),
        ),
    );
    
    // 1. Pedidos pendientes de confirmar recepción (solo processing)
    $processing_orders = wc_get_orders(array_merge($base_args, array('status' => 'processing')));
    $pending_confirmation = array();
    
    foreach ($processing_orders as $order) {
        $extracted = starter_extract_order_items($order, $user_id, $reviewed_map);
        if (!empty($extracted['items'])) {
            $pending_confirmation[] = array(
                'order_id'              => $order->get_id(),
                'date'                  => $order->get_date_created()->date('Y-m-d H:i:s'),
                'total'                 => $order->get_total(),
                'status'                => $order->get_status(),
                'items'                 => $extracted['items'],
                'all_products_reviewed' => $extracted['all_products_reviewed'],
            );
        }
    }
    
    // 2. Pedidos completed (pendientes de calificar)
    $completed_orders = wc_get_orders(array_merge($base_args, array('status' => 'completed')));
    $completed_unrated = array();
    
    foreach ($completed_orders as $order) {
        $extracted = starter_extract_order_items($order, $user_id, $reviewed_map);
        if (!empty($extracted['items'])) {
            $completed_unrated[] = array(
                'order_id'              => $order->get_id(),
                'date'                  => $order->get_date_completed() ? $order->get_date_completed()->date('Y-m-d H:i:s') : $order->get_date_created()->date('Y-m-d H:i:s'),
                'total'                 => $order->get_total(),
                'status'                => 'completed',
                'items'                 => $extracted['items'],
                'all_products_reviewed' => $extracted['all_products_reviewed'],
            );
        }
    }
    
    // Puntos por reseña (informativo)
    $review_points = 0;
    if (function_exists('Starter_RP')) {
        $options = Starter_RP()->get_options();
        $review_points = intval($options['points_review'] ?? 50);
    }
    
    return new WP_REST_Response(array(
        'pending_confirmation' => $pending_confirmation,
        'completed_unrated'   => $completed_unrated,
        'total'               => count($pending_confirmation) + count($completed_unrated),
        'review_points'       => $review_points,
    ), 200);
}

/**
 * POST /starter/v1/reviews/confirm-order
 * Confirmar recepción de un pedido: cambia status processing → completed
 * 
 * Body esperado: { order_id: int }
 */
function starter_confirm_order_received(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $params = $request->get_json_params();
    
    if (empty($params['order_id'])) {
        return new WP_Error('missing_order_id', 'Campo requerido: order_id.', array('status' => 400));
    }
    
    $order_id = absint($params['order_id']);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return new WP_Error('order_not_found', 'Pedido no encontrado.', array('status' => 404));
    }
    
    // Verificar que el pedido pertenece al usuario
    if (intval($order->get_customer_id()) !== $user_id) {
        return new WP_Error('not_order_owner', 'Este pedido no te pertenece.', array('status' => 403));
    }
    
    // Verificar que está en processing
    $status = $order->get_status();
    if ($status === 'completed') {
        // Idempotente: ya está completed
        return new WP_REST_Response(array(
            'success'    => true,
            'order_id'   => $order_id,
            'new_status' => 'completed',
        ), 200);
    }
    if ($status !== 'processing') {
        return new WP_Error('invalid_order_status', 'Solo se pueden confirmar pedidos en proceso.', array('status' => 400));
    }
    
    // Cambiar status a completed
    $order->update_status('completed', 'Pedido confirmado como recibido por el cliente vía app.');
    
    // Invalidar caché de orders y reviews
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type(array('orders', 'reviews'));
    }
    
    return new WP_REST_Response(array(
        'success'    => true,
        'order_id'   => $order_id,
        'new_status' => 'completed',
    ), 200);
}

/**
 * POST /starter/v1/reviews/rate-order
 * Calificar un pedido: crea reviews WC en cada producto del pedido
 * 
 * Body esperado: { order_id: int, rating: int (1-5), observation: string, review_text: string, received_ok: bool }
 */
function starter_rate_order(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $params = $request->get_json_params();
    
    // Validar campos requeridos base
    if (empty($params['order_id']) || empty($params['rating'])) {
        return new WP_Error(
            'missing_fields',
            'Campos requeridos: order_id, rating.',
            array('status' => 400)
        );
    }
    
    $order_id = absint($params['order_id']);
    $rating = absint($params['rating']);
    $observation = sanitize_textarea_field($params['observation'] ?? '');
    $review_text = sanitize_textarea_field($params['review_text'] ?? '');
    $received_ok = isset($params['received_ok']) ? (bool) $params['received_ok'] : null;
    
    // Validar rating
    if ($rating < 1 || $rating > 5) {
        return new WP_Error('invalid_rating', 'El rating debe ser entre 1 y 5.', array('status' => 400));
    }
    
    // Obtener y validar el pedido
    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('order_not_found', 'Pedido no encontrado.', array('status' => 404));
    }
    
    // Verificar que el pedido pertenece al usuario
    if (intval($order->get_customer_id()) !== $user_id) {
        return new WP_Error('not_order_owner', 'Este pedido no te pertenece.', array('status' => 403));
    }
    
    // Verificar status completed
    if ($order->get_status() !== 'completed') {
        return new WP_Error('order_not_completed', 'Solo se pueden calificar pedidos completados.', array('status' => 400));
    }
    
    // Verificar que no fue calificado ya
    $already_rated = $order->get_meta('_starter_order_rated');
    if ($already_rated) {
        return new WP_Error('already_rated', 'Este pedido ya fue calificado.', array('status' => 409));
    }
    
    // Pre-cargar mapa de productos ya reseñados (1 solo query SQL)
    $reviewed_map = starter_reviews_get_user_reviewed_products($user_id);
    
    // Verificar si todos los productos ya fueron reseñados (para decidir si review_text es obligatorio)
    $all_already_reviewed = true;
    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        if ($pid && wc_get_product($pid) && !isset($reviewed_map[$pid])) {
            $all_already_reviewed = false;
            break;
        }
    }
    
    // Validar review_text solo si hay productos sin reseñar
    if (!$all_already_reviewed) {
        if (mb_strlen($review_text) < 10) {
            return new WP_Error('review_too_short', 'La reseña debe tener al menos 10 caracteres.', array('status' => 400));
        }
        if (mb_strlen($review_text) > 2000) {
            return new WP_Error('review_too_long', 'La reseña no puede exceder 2000 caracteres.', array('status' => 400));
        }
    }
    
    $user = get_userdata($user_id);
    $reviews_created = 0;
    $skipped_products = 0;
    
    // Desconectar el hook de puntos por reseña individual para evitar doble otorgamiento.
    // El hook starter_rp_process_review_points se dispara DURANTE wp_insert_comment(),
    // ANTES de que podamos pre-marcar el meta. Los FC se otorgan UNA vez después del loop.
    remove_action('wp_insert_comment', 'starter_rp_process_review_points', 10);
    
    // Crear una reseña por cada producto del pedido
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        
        if (!$product) continue;
        
        // Verificar si el usuario ya reseñó este producto (usando mapa pre-cargado)
        if (isset($reviewed_map[$product_id])) {
            $skipped_products++;
            continue;
        }
        
        // Crear el comentario como WooCommerce review
        $comment_data = array(
            'comment_post_ID'      => $product_id,
            'comment_author'       => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_content'      => $review_text,
            'comment_type'         => 'review',
            'comment_parent'       => 0,
            'user_id'              => $user_id,
            'comment_approved'     => 1,
        );
        
        $comment_id = wp_insert_comment($comment_data);
        
        if ($comment_id && !is_wp_error($comment_id)) {
            // Guardar rating
            update_comment_meta($comment_id, 'rating', $rating);
            // Marcar como verified buyer
            update_comment_meta($comment_id, 'verified', 1);
            // Marcar como creado via order rating (para referencia)
            update_comment_meta($comment_id, '_starter_from_order_rating', $order_id);
            // Marcar puntos como gestionados por order-rating (no por el hook individual)
            update_comment_meta($comment_id, '_starter_review_points_awarded', 0);
            
            // Actualizar rating promedio del producto
            starter_reviews_update_product_rating($product_id);
            
            $reviews_created++;
        }
    }
    
    // Reconectar el hook de puntos por reseña individual
    add_action('wp_insert_comment', 'starter_rp_process_review_points', 10, 2);
    
    // Marcar el pedido como calificado (usar WC order meta API para compatibilidad con HPOS)
    $order->update_meta_data('_starter_order_rated', 1);
    $order->update_meta_data('_starter_order_rating', $rating);
    if ($received_ok !== null) {
        $order->update_meta_data('_starter_order_received_ok', $received_ok ? 1 : 0);
    }
    if (!empty($observation)) {
        $order->update_meta_data('_starter_order_observation', $observation);
    }
    $order->save();
    
    // Agregar nota al pedido (visible en WP Admin → Pedido → Notas)
    $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    $note_parts = array();
    $note_parts[] = sprintf('Pedido calificado por el cliente: %s (%d/5)', $stars, $rating);
    if ($received_ok !== null) {
        $note_parts[] = sprintf('Recibió a conformidad: %s', $received_ok ? 'Sí' : 'No');
    }
    if (!empty($observation)) {
        $note_parts[] = sprintf('Observación: %s', $observation);
    }
    $note_parts[] = sprintf('Reseñas creadas: %d | Productos omitidos (ya reseñados): %d', $reviews_created, $skipped_products);
    $order->add_order_note(implode("\n", $note_parts), 0, false);
    
    // Otorgar Virtual Coins UNA sola vez por calificar el pedido
    // Se otorgan siempre que el usuario califique, incluso si todos los productos ya tenían reseña
    $points_awarded = 0;
    if (function_exists('starter_rp_add_points') && function_exists('Starter_RP')) {
        if (function_exists('starter_rp_is_points_event_enabled') && starter_rp_is_points_event_enabled('review')) {
            $options = Starter_RP()->get_options();
            $review_points = intval($options['points_review'] ?? 50);
            
            if ($review_points > 0 && function_exists('starter_rp_can_user_use_points') && starter_rp_can_user_use_points($user_id)) {
                if ($reviews_created > 0) {
                    $description = sprintf('Puntos por calificar pedido #%d (%d productos reseñados)', $order_id, $reviews_created);
                } else {
                    $description = sprintf('Puntos por calificar pedido #%d (productos ya reseñados)', $order_id);
                }
                
                $result = starter_rp_add_points(
                    $user_id,
                    $review_points,
                    'review',
                    $description,
                    $order_id,
                    $options['points_expiry_days'] ?? 365
                );
                
                if ($result) {
                    $points_awarded = $review_points;
                    error_log("Starter Reviews: Otorgados $review_points FC por calificación de pedido #$order_id al usuario ID: $user_id ($reviews_created reviews creados)");
                }
            }
        }
    }
    
    // Invalidar caché de reviews y orders
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type(array('reviews', 'orders'));
    }
    
    return new WP_REST_Response(array(
        'success'          => true,
        'reviews_created'  => $reviews_created,
        'skipped_products' => $skipped_products,
        'points_awarded'   => $points_awarded,
    ), 201);
}
