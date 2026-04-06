<?php
/**
 * Funciones para gestionar direcciones de usuarios en WordPress
 * Añadir este código en el archivo functions.php del tema activo
 */

/**
 * Registrar endpoint para obtener direcciones del usuario
 */
function register_user_addresses_endpoint() {
    register_rest_route('starter/v1', '/user/addresses', array(
        'methods' => 'GET',
        'callback' => 'get_user_addresses_callback',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
}
add_action('rest_api_init', 'register_user_addresses_endpoint');

/**
 * Registrar endpoint para guardar direcciones del usuario
 */
function register_save_user_address_endpoint() {
    register_rest_route('starter/v1', '/user/addresses', array(
        'methods' => 'POST',
        'callback' => 'save_user_address_callback',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
}
add_action('rest_api_init', 'register_save_user_address_endpoint');

/**
 * Registrar endpoint para eliminar una dirección
 */
function register_delete_user_address_endpoint() {
    register_rest_route('starter/v1', '/user/addresses/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'delete_user_address_callback',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
}
add_action('rest_api_init', 'register_delete_user_address_endpoint');

/**
 * Registrar endpoint para establecer dirección predeterminada
 */
function register_set_default_address_endpoint() {
    register_rest_route('starter/v1', '/user/addresses/default/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => 'set_default_address_callback',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
}
add_action('rest_api_init', 'register_set_default_address_endpoint');

/**
 * Callback para obtener las direcciones del usuario actual
 */
function get_user_addresses_callback($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $addresses = get_user_meta($user_id, 'user_addresses', true);
    
    if (empty($addresses)) {
        $addresses = array();
    }
    
    return $addresses;
}

/**
 * Callback para guardar una dirección del usuario
 */
function save_user_address_callback($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $params = $request->get_params();
    
    // Lista de campos requeridos (postalCode ahora es opcional)
    $required_fields = array('name', 'address', 'city', 'state', 'country', 'phone');
    
    // Validar campos requeridos
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return new WP_Error('missing_field', 'Campo requerido: ' . $field, array('status' => 400));
        }
    }
    
    // Asegurar que postalCode exista aunque sea vacío para evitar undefined
    if (!isset($params['postalCode'])) {
        $params['postalCode'] = '';
    }
    
    // Obtener direcciones existentes
    $addresses = get_user_meta($user_id, 'user_addresses', true);
    if (empty($addresses)) {
        $addresses = array();
    }
    
    // Limitar a 3 direcciones
    if (count($addresses) >= 3 && empty($params['id'])) {
        return new WP_Error('max_addresses', 'Máximo 3 direcciones permitidas', array('status' => 400));
    }
    
    // Preparar datos de la dirección
    $address_data = array(
        'name' => sanitize_text_field($params['name']),
        'address' => sanitize_text_field($params['address']),
        'city' => sanitize_text_field($params['city']),
        'state' => sanitize_text_field($params['state']),
        'postalCode' => sanitize_text_field($params['postalCode']),
        'country' => sanitize_text_field($params['country']),
        'phone' => sanitize_text_field($params['phone']),
        'isDefault' => isset($params['isDefault']) ? (bool) $params['isDefault'] : false
    );
    
    // Si es una actualización
    if (!empty($params['id'])) {
        $address_id = intval($params['id']);
        $found = false;
        
        foreach ($addresses as $key => $existing_address) {
            if ($existing_address['id'] == $address_id) {
                $address_data['id'] = $address_id;
                $addresses[$key] = $address_data;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return new WP_Error('address_not_found', 'Dirección no encontrada', array('status' => 404));
        }
    } else {
        // Nueva dirección
        $address_data['id'] = time() . rand(100, 999); // ID único
        
        // Si es la primera dirección o se marca como predeterminada
        if (empty($addresses) || $address_data['isDefault']) {
            $address_data['isDefault'] = true;
            
            // Desmarcar otras direcciones como predeterminadas
            foreach ($addresses as $key => $existing_address) {
                $addresses[$key]['isDefault'] = false;
            }
        }
        
        $addresses[] = $address_data;
    }
    
    // Guardar direcciones actualizadas
    update_user_meta($user_id, 'user_addresses', $addresses);
    
    return array(
        'success' => true,
        'message' => !empty($params['id']) ? 'Dirección actualizada correctamente' : 'Dirección agregada correctamente',
        'address' => $address_data,
        'addresses' => $addresses
    );
}

/**
 * Callback para eliminar una dirección
 */
function delete_user_address_callback($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $address_id = $request['id'];
    
    // Obtener direcciones existentes
    $addresses = get_user_meta($user_id, 'user_addresses', true);
    if (empty($addresses)) {
        return new WP_Error('no_addresses', 'No hay direcciones para eliminar', array('status' => 404));
    }
    
    $found = false;
    $was_default = false;
    $updated_addresses = array();
    
    foreach ($addresses as $address) {
        if ($address['id'] == $address_id) {
            $found = true;
            $was_default = isset($address['isDefault']) && $address['isDefault'];
        } else {
            $updated_addresses[] = $address;
        }
    }
    
    if (!$found) {
        return new WP_Error('address_not_found', 'Dirección no encontrada', array('status' => 404));
    }
    
    // Si la dirección eliminada era la predeterminada, establecer la primera como predeterminada
    if ($was_default && !empty($updated_addresses)) {
        $updated_addresses[0]['isDefault'] = true;
    }
    
    // Guardar direcciones actualizadas
    update_user_meta($user_id, 'user_addresses', $updated_addresses);
    
    return array(
        'success' => true,
        'message' => 'Dirección eliminada correctamente',
        'addresses' => $updated_addresses
    );
}

/**
 * Callback para establecer una dirección como predeterminada
 */
function set_default_address_callback($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $address_id = $request['id'];
    
    // Obtener direcciones existentes
    $addresses = get_user_meta($user_id, 'user_addresses', true);
    if (empty($addresses)) {
        return new WP_Error('no_addresses', 'No hay direcciones disponibles', array('status' => 404));
    }
    
    $found = false;
    
    foreach ($addresses as $key => $address) {
        if ($address['id'] == $address_id) {
            $addresses[$key]['isDefault'] = true;
            $found = true;
        } else {
            $addresses[$key]['isDefault'] = false;
        }
    }
    
    if (!$found) {
        return new WP_Error('address_not_found', 'Dirección no encontrada', array('status' => 404));
    }
    
    // Guardar direcciones actualizadas
    update_user_meta($user_id, 'user_addresses', $addresses);
    
    return array(
        'success' => true,
        'message' => 'Dirección establecida como predeterminada',
        'addresses' => $addresses
    );
}

/**
 * Añadir direcciones a la respuesta del usuario en la API
 */
function add_addresses_to_user_response($response, $user, $request) {
    if (!empty($response->data) && $request->get_route() === '/wp/v2/users/me') {
        $user_id = $user->ID;
        $addresses = get_user_meta($user_id, 'user_addresses', true);
        
        if (empty($addresses)) {
            $addresses = array();
        }
        
        $response->data['addresses'] = $addresses;
        
        // Añadir dirección predeterminada
        $default_address = null;
        foreach ($addresses as $address) {
            if (isset($address['isDefault']) && $address['isDefault']) {
                $default_address = $address;
                break;
            }
        }
        
        $response->data['defaultAddress'] = $default_address;
    }
    
    return $response;
}
add_filter('rest_prepare_user', 'add_addresses_to_user_response', 10, 3);
