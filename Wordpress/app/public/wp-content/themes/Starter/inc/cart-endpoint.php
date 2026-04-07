<?php
/**
 * Endpoint personalizado para gestión del carrito del usuario
 * 
 * Este endpoint permite guardar y recuperar el carrito del usuario
 * usando user meta, permitiendo persistencia entre dispositivos.
 */

// Registrar el endpoint REST
add_action('rest_api_init', function () {
    // GET /starter/v1/cart - Obtener carrito del usuario
    register_rest_route('starter/v1', '/cart', array(
        'methods' => 'GET',
        'callback' => 'starter_get_user_cart',
        'permission_callback' => 'starter_cart_permission_check'
    ));

    // POST /starter/v1/cart - Guardar carrito del usuario
    register_rest_route('starter/v1', '/cart', array(
        'methods' => 'POST',
        'callback' => 'starter_save_user_cart',
        'permission_callback' => 'starter_cart_permission_check',
        'args' => array(
            'items' => array(
                'required' => true,
                'type' => 'array',
                'description' => 'Items del carrito a guardar'
            )
        )
    ));

    // DELETE /starter/v1/cart - Limpiar carrito del usuario
    register_rest_route('starter/v1', '/cart', array(
        'methods' => 'DELETE',
        'callback' => 'starter_clear_user_cart',
        'permission_callback' => 'starter_cart_permission_check'
    ));
});

/**
 * Verificar permisos para el endpoint del carrito
 * Solo usuarios autenticados pueden acceder
 */
function starter_cart_permission_check() {
    return is_user_logged_in();
}

/**
 * Obtener el carrito del usuario
 */
function starter_get_user_cart(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error(
            'not_authenticated',
            'Usuario no autenticado',
            array('status' => 401)
        );
    }

    // Obtener el carrito del user meta
    $cart_items = get_user_meta($user_id, '_starter_cart', true);
    
    // Si no existe, retornar array vacío
    if (empty($cart_items) || !is_array($cart_items)) {
        $cart_items = array();
    }

    $filtered_items = array();
    $removed_count = 0;
    
    foreach ($cart_items as $item) {
        if (!is_array($item)) {
            $removed_count++;
            continue;
        }

        $product_id = isset($item['id']) ? intval($item['id']) : 0;
        if ($product_id <= 0) {
            $removed_count++;
            continue;
        }

        $variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : 0;

        $is_instock = false;

        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if ($variation && $variation->get_status() === 'publish' && $variation->get_stock_status() === 'instock') {
                $is_instock = true;
            }
        } else {
            $product = wc_get_product($product_id);
            if ($product && $product->get_status() === 'publish' && $product->get_stock_status() === 'instock') {
                $is_instock = true;
            }
        }

        if ($is_instock) {
            $filtered_items[] = $item;
        } else {
            $removed_count++;
        }
    }

    if ($removed_count > 0) {
        update_user_meta($user_id, '_starter_cart', $filtered_items);
    }

    // Log para debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
            sprintf(
                'Starter Cart - GET: Usuario %d tenía %d items, %d removidos por stock/estado, %d devueltos',
                $user_id,
                count($cart_items),
                $removed_count,
                count($filtered_items)
            )
        );
    }

    return rest_ensure_response(array(
        'success' => true,
        'items' => $filtered_items,
        'count' => count($filtered_items),
        'removed' => $removed_count
    ));
}

/**
 * Guardar el carrito del usuario
 */
function starter_save_user_cart(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error(
            'not_authenticated',
            'Usuario no autenticado',
            array('status' => 401)
        );
    }

    $items = $request->get_param('items');
    
    // Validar que items sea un array
    if (!is_array($items)) {
        return new WP_Error(
            'invalid_data',
            'Los items deben ser un array',
            array('status' => 400)
        );
    }

    // Sanitizar cada item del carrito para evitar datos arbitrarios en user_meta
    $sanitized_items = array();
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }
        $sanitized_item = array(
            'id' => absint($item['id']),
            'quantity' => isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1,
        );
        if (!empty($item['variation_id'])) {
            $sanitized_item['variation_id'] = absint($item['variation_id']);
        }
        if (!empty($item['variation']) && is_array($item['variation'])) {
            $sanitized_item['variation'] = array_map('sanitize_text_field', $item['variation']);
        }
        $sanitized_items[] = $sanitized_item;
    }

    // Guardar en user meta
    $updated = update_user_meta($user_id, '_starter_cart', $sanitized_items);

    // Log para debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Starter Cart - POST: Usuario ' . $user_id . ' guardó ' . count($sanitized_items) . ' items (de ' . count($items) . ' recibidos)');
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Carrito guardado exitosamente',
        'count' => count($sanitized_items)
    ));
}

/**
 * Limpiar el carrito del usuario
 */
function starter_clear_user_cart(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error(
            'not_authenticated',
            'Usuario no autenticado',
            array('status' => 401)
        );
    }

    // Eliminar el carrito del user meta
    $deleted = delete_user_meta($user_id, '_starter_cart');

    // Log para debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Starter Cart - DELETE: Usuario ' . $user_id . ' limpió su carrito');
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Carrito limpiado exitosamente'
    ));
}
