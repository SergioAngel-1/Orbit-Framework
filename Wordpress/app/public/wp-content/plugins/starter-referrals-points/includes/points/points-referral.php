<?php
/**
 * Funciones de puntos relacionadas con referidos
 * 
 * Gestión de puntos otorgados por referidos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener la comisión de referidos para un usuario según su nivel de membresía
 * 
 * @param int $user_id ID del usuario referidor
 * @param string $type Tipo de comisión: 'first', 'subsequent', 'level2'
 * @param array $options Opciones del plugin (opcional)
 * @return float Porcentaje de comisión
 */
function starter_rp_get_commission_for_user($user_id, $type = 'first', $options = null) {
    if ($options === null) {
        $options = Starter_RP()->get_options();
    }
    
    // Obtener nivel de membresía del usuario
    $membership_level = 0;
    if (function_exists('starter_get_user_membership_level')) {
        $membership_level = starter_get_user_membership_level($user_id);
    }
    
    // Nivel 0 (sin membresía) no tiene acceso al sistema de referidos
    // Requiere mínimo nivel 1 (Bronce) para ganar comisiones
    if ($membership_level == 0) {
        return 0;
    }
    
    // Obtener configuración de comisiones por membresía
    $membership_commissions = $options['membership_commissions'] ?? [];
    $level_config = $membership_commissions[$membership_level] ?? [];
    
    // Valores por defecto según nivel (solo niveles 1-5)
    // NOTA: Estos son fallbacks, los valores reales se configuran en:
    // Virtual Coins → Configuración → Comisiones por Membresía
    $defaults = [
        1 => ['first' => 3, 'subsequent' => 1, 'level2' => 0.2],
        2 => ['first' => 4, 'subsequent' => 2, 'level2' => 0.5],
        3 => ['first' => 6, 'subsequent' => 3, 'level2' => 1],
        4 => ['first' => 8, 'subsequent' => 4, 'level2' => 1.5],
        5 => ['first' => 10, 'subsequent' => 5, 'level2' => 2],
    ];
    
    $default = $defaults[$membership_level] ?? $defaults[1];
    
    // Mapear tipo a campo de configuración
    $field_map = [
        'first' => 'first_commission',
        'subsequent' => 'subsequent_commission',
        'level2' => 'level2_commission'
    ];
    
    $field = $field_map[$type] ?? 'first_commission';
    $default_key = $type === 'level2' ? 'level2' : $type;
    
    return floatval($level_config[$field] ?? $default[$default_key]);
}

/**
 * Procesar puntos para referidos según la compra
 */
function starter_rp_process_referral_points($user_id, $order_id, $order_total) {
    if ($order_total <= 0) {
        return;
    }
    
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        starter_rp_log_access_denied('process_referral_points', $user_id, 'Sistema de referidos deshabilitado');
        return;
    }
    
    global $wpdb;
    $options = Starter_RP()->get_options();
    
    // Obtener referidor (nivel 1)
    $referrals_table = $wpdb->prefix . 'starter_referrals';
    $referrer_data = $wpdb->get_row($wpdb->prepare("
        SELECT referrer_id, signup_date FROM $referrals_table WHERE user_id = %d AND referrer_id IS NOT NULL
    ", $user_id));
    
    if (!$referrer_data) {
        return; // No hay referidor
    }
    
    $referrer_id = $referrer_data->referrer_id;
    $signup_date = $referrer_data->signup_date;
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    // Verificar si la comisión aún está dentro del período válido
    $commission_duration = (int) ($options['referral_commission_duration'] ?? 365);
    if ($commission_duration > 0) {
        $signup_timestamp = strtotime($signup_date);
        $current_timestamp = current_time('timestamp');
        $days_since_signup = ($current_timestamp - $signup_timestamp) / (24 * 60 * 60);
        
        if ($days_since_signup > $commission_duration) {
            starter_rp_log("Comisión de referido expirada para usuario $user_id. Días transcurridos: $days_since_signup, Duración permitida: $commission_duration");
            return; // La comisión ha expirado
        }
    }
    
    // Verificar si es la primera compra del referido
    // Usa wc_get_orders() para compatibilidad con HPOS (High-Performance Order Storage)
    $previous_orders = wc_get_orders([
        'customer_id' => $user_id,
        'status'      => ['wc-completed', 'wc-processing'],
        'limit'       => 2, // Solo necesitamos saber si hay más de 1
        'return'      => 'ids',
        'exclude'     => [$order_id], // Excluir el pedido actual
        'type'        => 'shop_order',
        'date_before' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
    ]);
    
    $is_first_purchase = (count($previous_orders) === 0);
    
    starter_rp_log(sprintf(
        'Starter RP: Detección de compra - Usuario %d, Pedido #%d, Pedidos previos: %d, ¿Primera compra?: %s',
        $user_id, $order_id, count($previous_orders), $is_first_purchase ? 'SÍ' : 'NO'
    ));
    
    // Obtener comisión según nivel de membresía del referidor
    $commission_percentage = starter_rp_get_commission_for_user($referrer_id, $is_first_purchase ? 'first' : 'subsequent', $options);
    
    // Calcular puntos para referidor nivel 1 basado en el total del pedido
    $level1_points = floor(($order_total * $commission_percentage) / 100);
        
    if ($level1_points > 0) {
        $user_info = get_userdata($user_id);
        $user_name = $user_info ? $user_info->display_name : "Usuario #$user_id";
        
        // Obtener nivel de membresía del referidor para la descripción
        $referrer_level = 0;
        if (function_exists('starter_get_user_membership_level')) {
            $referrer_level = starter_get_user_membership_level($referrer_id);
        }
        $level_text = $referrer_level > 0 ? " [Nivel $referrer_level]" : '';
        
        $description = sprintf(
            'Comisión %s por aporte de referido: %s (Pedido #%d - %s%% de %s)%s', 
            $is_first_purchase ? '(primer aporte)' : '(aporte posterior)',
            $user_name,
            $order_id,
            $commission_percentage,
            wc_price($order_total),
            $level_text
        );
        
        $result = starter_rp_add_points(
            $referrer_id, 
            $level1_points, 
            'referral_commission', 
            $description, 
            $user_id, 
            $options['points_expiry_days'] ?? 365
        );
        
        // Agregar nota al pedido para trazabilidad
        if ($result) {
            if ($order) {
                $order->add_order_note(sprintf(
                    'Comisión N1: %d FC otorgados al referidor #%d (%s%% - Membresía nivel %d)',
                    $level1_points,
                    $referrer_id,
                    $commission_percentage,
                    $referrer_level
                ));
            }
        }
        
        starter_rp_log("Puntos de comisión nivel 1 otorgados: $level1_points puntos para referidor $referrer_id por aporte de $user_id");
    }
    
    // Procesar comisión de segundo nivel si está habilitado
    $enable_second_level = (bool) ($options['enable_second_level'] ?? false);
    
    if ($enable_second_level) {
        // Obtener referidor de nivel 2
        $level2_referrer_id = $wpdb->get_var($wpdb->prepare("
            SELECT referrer_id FROM $referrals_table WHERE user_id = %d AND referrer_id IS NOT NULL
        ", $referrer_id));
        
        if ($level2_referrer_id) {
            // Obtener comisión nivel 2 según membresía del referidor nivel 2
            $level2_commission_percentage = starter_rp_get_commission_for_user($level2_referrer_id, 'level2', $options);
            $level2_points = floor(($order_total * $level2_commission_percentage) / 100);
            
            if ($level2_points > 0) {
                $user_info = get_userdata($user_id);
                $user_name = $user_info ? $user_info->display_name : "Usuario #$user_id";
                
                // Obtener nivel de membresía del referidor nivel 2 para la descripción
                $level2_referrer_level = 0;
                if (function_exists('starter_get_user_membership_level')) {
                    $level2_referrer_level = starter_get_user_membership_level($level2_referrer_id);
                }
                $level2_text = $level2_referrer_level > 0 ? " [Nivel $level2_referrer_level]" : '';
                
                $description = sprintf(
                    'Comisión nivel 2 por aporte de referido indirecto: %s (Pedido #%d - %s%% de %s)%s', 
                    $user_name,
                    $order_id,
                    $level2_commission_percentage,
                    wc_price($order_total),
                    $level2_text
                );
                
                $result_l2 = starter_rp_add_points(
                    $level2_referrer_id, 
                    $level2_points, 
                    'referral_commission_level2', 
                    $description, 
                    $user_id, 
                    $options['points_expiry_days'] ?? 365
                );
                
                // Agregar nota al pedido para trazabilidad
                if ($result_l2) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $order->add_order_note(sprintf(
                            'Comisión N2: %d FC otorgados al referidor indirecto #%d (%s%% - Membresía nivel %d)',
                            $level2_points,
                            $level2_referrer_id,
                            $level2_commission_percentage,
                            $level2_referrer_level
                        ));
                    }
                }
                
                starter_rp_log("Puntos de comisión nivel 2 otorgados: $level2_points puntos para referidor $level2_referrer_id por aporte de $user_id");
            }
        }
    }
}

/**
 * Asignar puntos por registro de un nuevo referido
 */
function starter_rp_assign_referral_signup_points($referrer_id, $user_id) {
    // Los puntos se asignarán cuando el usuario sea aprobado
    update_user_meta($user_id, '_starter_pending_referral_points', $referrer_id);
}

/**
 * Verificar si un usuario ha sido aprobado para asignar puntos de referido
 */
function starter_rp_check_user_approval($user_id, $new_role, $old_roles) {
    // Verificar si el nuevo rol indica que el usuario ha sido aprobado
    $approved_roles = array('customer', 'subscriber', 'author', 'contributor', 'editor', 'administrator');
    
    if (in_array($new_role, $approved_roles)) {
        // Verificar si hay puntos pendientes por asignar
        $referrer_id = get_user_meta($user_id, '_starter_pending_referral_points', true);
        
        if ($referrer_id) {
            // Obtener opciones
            $options = Starter_RP()->get_options();
            
            // Procesar puntos por registro
            starter_rp_process_signup_points($user_id, $referrer_id);
            
            // Marcar como procesado
            delete_user_meta($user_id, '_starter_pending_referral_points');
        }
    }
}

/**
 * Procesar puntos por registro de nuevos referidos (niveles 1 y 2)
 */
function starter_rp_process_signup_points($user_id, $direct_referrer_id) {
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        starter_rp_log_access_denied('process_signup_points', $user_id, 'Sistema de referidos deshabilitado');
        return;
    }
    
                global $wpdb;
    $options = Starter_RP()->get_options();
    $referrals_table = $wpdb->prefix . 'starter_referrals';
                
    // Obtener información del nuevo usuario
    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : "Usuario #$user_id";
    
    // Verificar si el usuario existe en la tabla de referidos
                $exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $referrals_table WHERE user_id = %d
    ", $user_id));
                
                if (!$exists) {
                    // El usuario no tiene registro en la tabla, crear uno
                    $code = substr(md5($user_id . time()), 0, 8);
                    
                    $wpdb->insert(
            $referrals_table,
                        array(
                            'user_id' => $user_id,
                'referrer_id' => $direct_referrer_id,
                            'referral_code' => $code,
                            'signup_date' => current_time('mysql')
                        )
                    );
        starter_rp_log("Creando nuevo registro de referido durante aprobación: Usuario ID $user_id, Referidor ID $direct_referrer_id, Código $code");
                }
                
    // Puntos para referidor de nivel 1 (directo)
    $level1_points = (int) ($options['signup_points_level1'] ?? 100);
    if ($level1_points > 0) {
        $description = sprintf('Puntos por nuevo referido directo: %s', $user_name);
        
        starter_rp_add_points(
            $direct_referrer_id,
            $level1_points,
            'referral_signup_level1',
            $description,
            $user_id,
            $options['points_expiry_days'] ?? 365
        );
        
        starter_rp_log("Puntos nivel 1 por registro asignados: $level1_points puntos para referidor $direct_referrer_id por nuevo usuario $user_id");
    }
    
    // Procesar puntos de nivel 2 si está habilitado
    $enable_second_level = (bool) ($options['enable_second_level'] ?? false);
    
    if ($enable_second_level) {
        // Obtener referidor de nivel 2 (referidor del referidor directo)
        $level2_referrer_id = $wpdb->get_var($wpdb->prepare("
            SELECT referrer_id FROM $referrals_table WHERE user_id = %d AND referrer_id IS NOT NULL
        ", $direct_referrer_id));
        
        if ($level2_referrer_id) {
            $level2_points = (int) ($options['signup_points_level2'] ?? 50);
            
            if ($level2_points > 0) {
                $description = sprintf('Puntos por nuevo referido indirecto: %s', $user_name);
                    
                    starter_rp_add_points(
                    $level2_referrer_id,
                    $level2_points,
                    'referral_signup_level2',
                        $description,
                        $user_id,
                    $options['points_expiry_days'] ?? 365
                    );
                    
                starter_rp_log("Puntos nivel 2 por registro asignados: $level2_points puntos para referidor $level2_referrer_id por nuevo usuario $user_id");
            }
        }
    }
}
