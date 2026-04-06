<?php
/**
 * Gestión de códigos de referido
 * 
 * Funciones para generar, obtener y actualizar códigos de referido.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generar código de referido para un usuario
 */
function starter_rp_generate_referral_code($user_id) {
    global $wpdb;
    
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        starter_rp_log_access_denied('generate_referral_code', $user_id, 'Sistema de referidos deshabilitado');
        return false;
    }
    
    // NOTA: NO verificamos starter_rp_can_user_use_referrals porque un usuario nuevo
    // tiene nivel 0 (Zanahoria) y no pasaría la validación de membresía.
    // Solo verificamos que el usuario puede participar según su rol.
    // Todos los usuarios pueden tener un código de referido, pero solo los de nivel 1+
    // pueden ganar comisiones por referir a otros.
    if (!starter_rp_can_user_participate($user_id)) {
        starter_rp_log_access_denied('generate_referral_code', $user_id, 'Rol no permitido para referidos');
        return false;
    }
    
    // Obtener información del usuario
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    
    $table = $wpdb->prefix . 'starter_referrals';
    
    // IMPORTANTE: Si el usuario ya tiene un código válido, retornarlo sin modificar
    $existing_code = $wpdb->get_var($wpdb->prepare("
        SELECT referral_code FROM $table WHERE user_id = %d
    ", $user_id));
    
    if ($existing_code) {
        return $existing_code;
    }
    
    // Generar código basado en el username
    $username = $user->user_login;
    $base = sanitize_title($username);
    $random = mt_rand(1000, 9999);
    $code = $base . $random;
    
    // Verificar que no exista
    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $table WHERE referral_code = %s
    ", $code));
    
    // Si existe, generar otro
    if ($exists > 0) {
        $random = mt_rand(1000, 9999);
        $code = $base . $random;
    }
    
    // Verificar si el usuario tiene un registro sin código (caso raro: registro con referral_code NULL)
    $user_exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $table WHERE user_id = %d
    ", $user_id));
    
    if ($user_exists > 0) {
        // Registro existe pero sin código (caso raro), actualizar
        $wpdb->update(
            $table,
            ['referral_code' => $code],
            ['user_id' => $user_id]
        );
        
        // Log para depuración
        starter_rp_log("Código de referido asignado para usuario ID $user_id: $code");
    } else {
        // Crear nuevo registro
        $wpdb->insert($table, [
            'user_id' => $user_id,
            'referral_code' => $code,
            'signup_date' => current_time('mysql')
        ]);
        
        // Log para depuración
        starter_rp_log("Nuevo código de referido generado para usuario ID $user_id: $code");
    }
    
    return $code;
}

/**
 * Obtener el código de referido de un usuario
 * 
 * @param int $user_id ID del usuario
 * @return string|false Código de referido o false si no se encuentra
 */
function starter_rp_get_user_referral_code($user_id = 0) {
    global $wpdb;
    
    // Si no se especifica usuario, usar el actual
    if (!$user_id && is_user_logged_in()) {
        $user_id = get_current_user_id();
    }
    
    if (!$user_id) {
        return false;
    }
    
    $table = $wpdb->prefix . 'starter_referrals';
    
    $code = $wpdb->get_var($wpdb->prepare("
        SELECT referral_code FROM $table WHERE user_id = %d
    ", $user_id));
    
    return $code;
}

/**
 * Actualizar el código de referido de un usuario
 * 
 * @param int $user_id ID del usuario
 * @param string $new_code Nuevo código de referido
 * @return bool True si se actualizó correctamente, false en caso contrario
 */
function starter_rp_update_user_referral_code($user_id, $new_code) {
    global $wpdb;
    
    // Validar usuario
    if (!$user_id || !get_userdata($user_id)) {
        starter_rp_log("Error al actualizar código de referido: Usuario ID $user_id no existe");
        return false;
    }
    
    // Validar código
    if (empty($new_code) || !preg_match('/^[a-z0-9\-]+$/', $new_code)) {
        starter_rp_log("Error al actualizar código de referido: Código '$new_code' inválido");
        return false;
    }
    
    $table = $wpdb->prefix . 'starter_referrals';
    
    // Verificar que el código no exista para otro usuario
    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $table WHERE referral_code = %s AND user_id != %d
    ", $new_code, $user_id));
    
    if ($exists > 0) {
        starter_rp_log("Error al actualizar código de referido: Código '$new_code' ya está en uso");
        return false;
    }
    
    // Verificar si el usuario ya tiene un registro
    $user_exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $table WHERE user_id = %d
    ", $user_id));
    
    if ($user_exists > 0) {
        // Actualizar código
        $result = $wpdb->update(
            $table,
            ['referral_code' => $new_code],
            ['user_id' => $user_id]
        );
        
        starter_rp_log("Código de referido actualizado para usuario ID $user_id: $new_code (Resultado: " . ($result !== false ? 'Éxito' : 'Error') . ")");
        return ($result !== false);
    } else {
        // Crear registro nuevo
        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'referral_code' => $new_code,
                'signup_date' => current_time('mysql')
            ]
        );
        
        starter_rp_log("Nuevo registro de referido creado para usuario ID $user_id con código $new_code (Resultado: " . ($result !== false ? 'Éxito' : 'Error') . ")");
        return ($result !== false);
    }
}

/**
 * Obtener ID de usuario por código de referido
 * 
 * @param string $code Código de referido
 * @return int|false ID del usuario o false si no se encuentra
 */
function starter_rp_get_user_by_referral_code($code) {
    global $wpdb;
    
    if (empty($code)) {
        return false;
    }
    
    $table = $wpdb->prefix . 'starter_referrals';
    
    // Primero intentamos una coincidencia exacta
    $user_id = $wpdb->get_var($wpdb->prepare("
        SELECT user_id FROM $table WHERE referral_code = %s
    ", $code));
    
    // Solo permitimos coincidencias exactas para mayor seguridad
    return $user_id ? intval($user_id) : false;
}

/**
 * Asignar códigos de referido a todos los usuarios que no tienen uno
 * 
 * @return array Resultado de la operación con contadores
 */
function starter_rp_assign_missing_referral_codes() {
    global $wpdb;
    
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        return [
            'success' => false,
            'message' => __('El sistema de referidos está deshabilitado.', 'starter-rp'),
            'assigned' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
    }
    
    // Obtener todos los usuarios sin código de referido
    $users_without_code = $wpdb->get_results("
        SELECT u.ID, u.user_login
        FROM {$wpdb->users} u
        LEFT JOIN {$wpdb->prefix}starter_referrals r ON u.ID = r.user_id
        WHERE r.referral_code IS NULL
        ORDER BY u.ID ASC
    ");
    
    if (empty($users_without_code)) {
        return [
            'success' => true,
            'message' => __('No hay usuarios sin código de referido.', 'starter-rp'),
            'assigned' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
    }
    
    $assigned = 0;
    $skipped = 0;
    $errors = 0;
    
    starter_rp_log("Iniciando asignación masiva de códigos para " . count($users_without_code) . " usuarios");
    
    foreach ($users_without_code as $user) {
        $user_id = $user->ID;
        
        // Obtener información del usuario
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            starter_rp_log("Usuario ID $user_id no existe, omitiendo");
            $skipped++;
            continue;
        }
        
        // Generar código basado en el username directamente (sin validación de permisos)
        // porque esta es una asignación administrativa masiva
        $username = $user_data->user_login;
        $base = sanitize_title($username);
        $random = mt_rand(1000, 9999);
        $code = $base . $random;
        
        // Verificar que el código no exista
        $table = $wpdb->prefix . 'starter_referrals';
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $table WHERE referral_code = %s
        ", $code));
        
        // Si existe, generar otro
        if ($exists > 0) {
            $random = mt_rand(1000, 9999);
            $code = $base . $random;
        }
        
        // Insertar directamente en la tabla
        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'referral_code' => $code,
                'signup_date' => current_time('mysql')
            ]
        );
        
        if ($result !== false) {
            starter_rp_log("Código asignado masivamente para usuario ID $user_id: $code");
            $assigned++;
        } else {
            starter_rp_log("Error al asignar código para usuario ID $user_id: " . $wpdb->last_error);
            $errors++;
        }
    }
    
    starter_rp_log("Asignación masiva completada: $assigned asignados, $skipped omitidos, $errors errores");
    
    return [
        'success' => true,
        'message' => sprintf(
            __('Proceso completado: %d códigos asignados, %d usuarios omitidos, %d errores.', 'starter-rp'),
            $assigned,
            $skipped,
            $errors
        ),
        'assigned' => $assigned,
        'skipped' => $skipped,
        'errors' => $errors
    ];
}
