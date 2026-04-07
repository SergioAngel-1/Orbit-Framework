<?php
/**
 * Gestión de membresías de usuario
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar funciones de usuario
 */
function starter_memberships_init_user() {
    // Hook para verificar membresías expiradas diariamente
    add_action('starter_memberships_daily_check', 'starter_memberships_check_expired');
    
    // NOTA: Los FC se otorgan UNA ÚNICA VEZ al activar la membresía (en starter_memberships_process_order)
    // NO se otorgan mensualmente - la función starter_memberships_award_monthly_points fue eliminada
    
    // Hook cuando se completa una orden
    add_action('woocommerce_order_status_completed', 'starter_memberships_process_order', 10, 1);
    
    // Hook para mostrar membresía en perfil de usuario (admin)
    add_action('show_user_profile', 'starter_memberships_user_profile_fields');
    add_action('edit_user_profile', 'starter_memberships_user_profile_fields');
    
    // Hook para guardar membresía desde perfil de usuario (admin)
    add_action('personal_options_update', 'starter_memberships_save_user_profile_fields');
    add_action('edit_user_profile_update', 'starter_memberships_save_user_profile_fields');
}

/**
 * Obtener días según el periodo de renovación configurado
 * 
 * @param string $period Periodo de renovación (monthly, bimonthly, quarterly, biannual, annual)
 * @return int Días del periodo
 */
function starter_get_renewal_period_days($period) {
    $periods = [
        'monthly' => 30,
        'bimonthly' => 60,
        'quarterly' => 90,
        'biannual' => 180,
        'annual' => 365,
        'none' => 30, // Fallback a mensual si no hay renovación automática
    ];
    
    return $periods[$period] ?? 30;
}

/**
 * Obtener intervalo de fecha según el periodo de renovación
 * 
 * @param string $period Periodo de renovación
 * @return string Intervalo para strtotime (ej: '+1 month', '+3 months')
 */
function starter_get_renewal_period_interval($period) {
    $intervals = [
        'monthly' => '+1 month',
        'bimonthly' => '+2 months',
        'quarterly' => '+3 months',
        'biannual' => '+6 months',
        'annual' => '+1 year',
        'none' => '+1 month', // Fallback
    ];
    
    return $intervals[$period] ?? '+1 month';
}

/**
 * Activar membresía para un usuario
 * 
 * @param int $user_id ID del usuario
 * @param int $level Nivel de membresía
 * @param int $duration_days Duración en días
 * @param int $product_id ID del producto (opcional)
 * @param int $order_id ID de la orden (opcional)
 * @param bool $is_referral_bonus Si es true, la membresía NO otorga Virtual Coins (bono de referido)
 * @return int|false ID de la membresía o false
 */
function starter_activate_user_membership($user_id, $level, $duration_days = 30, $product_id = null, $order_id = null, $is_referral_bonus = false) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    
    // Obtener membresía actual si existe
    $current = starter_memberships_get_user_membership($user_id);
    $old_level = $current ? $current->membership_level : 0;
    
    // Calcular fechas
    $start_date = current_time('mysql');
    $end_date = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));
    
    // Si hay membresía activa del mismo nivel o inferior, extender
    if ($current && $current->membership_level <= $level) {
        // Extender desde la fecha de fin actual
        $end_date = date('Y-m-d H:i:s', strtotime($current->end_date . " +{$duration_days} days"));
        
        // Preparar datos de actualización
        // NOTA: next_points_date ya no se usa - los FC se otorgan una única vez al activar
        $update_data = [
            'membership_level' => $level,
            'end_date' => $end_date,
            'product_id' => $product_id,
            'order_id' => $order_id,
            'updated_at' => current_time('mysql')
        ];
        $update_format = ['%d', '%s', '%d', '%d', '%s'];
        
        // Actualizar membresía existente
        $wpdb->update(
            $table,
            $update_data,
            ['id' => $current->id],
            $update_format,
            ['%d']
        );
        
        $membership_id = $current->id;
        $action = $level > $old_level ? 'upgrade' : 'renewal';
    } else {
        // Desactivar membresías anteriores
        $wpdb->update(
            $table,
            ['status' => 'expired'],
            ['user_id' => $user_id, 'status' => 'active'],
            ['%s'],
            ['%d', '%s']
        );
        
        // Crear nueva membresía
        // NOTA: next_points_date ya no se usa - los FC se otorgan una única vez al activar
        // is_referral_bonus indica si la membresía fue otorgada por bono de referido (sin FC)
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'membership_level' => $level,
                'product_id' => $product_id,
                'order_id' => $order_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'is_referral_bonus' => $is_referral_bonus ? 1 : 0,
                'status' => 'active',
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        
        $membership_id = $wpdb->insert_id;
        $action = $old_level > 0 ? 'upgrade' : 'activation';
    }
    
    // Actualizar user meta
    update_user_meta($user_id, '_membership_level', $level);
    update_user_meta($user_id, '_membership_end_date', $end_date);
    
    // Registrar en historial
    starter_memberships_log_action(
        $user_id,
        $action,
        $old_level,
        $level,
        [
            'product_id' => $product_id,
            'order_id' => $order_id,
            'duration_days' => $duration_days,
            'end_date' => $end_date
        ],
        $membership_id
    );
    
    // Disparar acción
    do_action('starter_membership_activated', $user_id, $level, $membership_id);
    
    $bonus_text = $is_referral_bonus ? ' (BONO REFERIDO - sin FC)' : '';
    error_log(sprintf(
        'Starter Memberships: Usuario %d activó membresía nivel %d hasta %s%s',
        $user_id, $level, $end_date, $bonus_text
    ));
    
    return $membership_id;
}

/**
 * Procesar orden completada para activar membresías
 */
function starter_memberships_process_order($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    $user_id = $order->get_user_id();
    
    if (!$user_id) {
        return; // Orden de invitado, no procesar membresía
    }
    
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        
        if (!starter_is_membership_product($product_id)) {
            continue;
        }
        
        $level = starter_get_product_membership_level($product_id);
        $duration = intval(get_post_meta($product_id, '_membership_duration_days', true)) ?: 30;
        $monthly_points = intval(get_post_meta($product_id, '_membership_monthly_points', true));
        
        // Activar membresía
        $membership_id = starter_activate_user_membership(
            $user_id,
            $level,
            $duration,
            $product_id,
            $order_id
        );
        
        // Otorgar Virtual Coins UNA ÚNICA VEZ al activar la membresía
        // Los FC se definen en el producto WC (meta: _membership_monthly_points)
        if ($membership_id && $monthly_points > 0 && function_exists('starter_rp_add_points')) {
            starter_rp_add_points(
                $user_id,
                $monthly_points,
                'membership_activation',
                sprintf('Virtual Coins por activación de membresía nivel %d (orden #%d)', $level, $order_id),
                $membership_id
            );
            
            // Registrar puntos otorgados
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'starter_membership_points',
                [
                    'user_id' => $user_id,
                    'membership_id' => $membership_id,
                    'points_amount' => $monthly_points,
                    'period_month' => date('n'),
                    'period_year' => date('Y'),
                    'awarded_at' => current_time('mysql')
                ],
                ['%d', '%d', '%d', '%d', '%d', '%s']
            );
        }
        
        error_log(sprintf(
            'Starter Memberships: Orden %d procesada - Usuario %d recibió membresía nivel %d',
            $order_id, $user_id, $level
        ));
    }
}

/**
 * Verificar y expirar membresías vencidas
 */
function starter_memberships_check_expired() {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    
    // Obtener membresías expiradas
    $expired = $wpdb->get_results(
        "SELECT * FROM $table 
         WHERE status = 'active' 
         AND end_date < NOW()"
    );
    
    foreach ($expired as $membership) {
        // Actualizar estado
        $wpdb->update(
            $table,
            ['status' => 'expired'],
            ['id' => $membership->id],
            ['%s'],
            ['%d']
        );
        
        // Actualizar user meta
        update_user_meta($membership->user_id, '_membership_level', 0);
        delete_user_meta($membership->user_id, '_membership_end_date');
        
        // Registrar en historial
        starter_memberships_log_action(
            $membership->user_id,
            'expiration',
            $membership->membership_level,
            0,
            ['expired_at' => current_time('mysql')],
            $membership->id
        );
        
        // Disparar acción
        do_action('starter_membership_expired', $membership->user_id, $membership->id);
        
        error_log(sprintf(
            'Starter Memberships: Membresía %d del usuario %d expirada',
            $membership->id, $membership->user_id
        ));
    }
}

/**
 * FUNCIÓN ELIMINADA: starter_memberships_award_monthly_points()
 * 
 * Los Virtual Coins se otorgan UNA ÚNICA VEZ al activar la membresía,
 * NO mensualmente. El otorgamiento ocurre en starter_memberships_process_order()
 * cuando se completa la orden de compra de la membresía.
 * 
 * La cantidad de FC se define en el producto WooCommerce de membresía
 * (meta: _membership_monthly_points - nombre legacy, pero es por periodo completo).
 */

/**
 * Mostrar campos de membresía en perfil de usuario (admin)
 */
function starter_memberships_user_profile_fields($user) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $membership_info = starter_get_user_membership_info($user->ID);
    $levels = Starter_Memberships::get_all_membership_levels();
    ?>
    <h3><?php _e('Membresía Starter', 'starter-memberships'); ?></h3>
    
    <table class="form-table">
        <tr>
            <th><label for="membership_level"><?php _e('Nivel de Membresía', 'starter-memberships'); ?></label></th>
            <td>
                <?php 
                // Usar original_level para el select (no el efectivo que puede ser 0 si está congelada)
                $selected_level = isset($membership_info['original_level']) ? $membership_info['original_level'] : $membership_info['level'];
                ?>
                <select name="membership_level" id="membership_level">
                    <?php foreach ($levels as $level_id => $level) : ?>
                        <option value="<?php echo esc_attr($level_id); ?>" <?php selected($selected_level, $level_id); ?>>
                            <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php if ($membership_info['status'] === 'active' || $membership_info['status'] === 'frozen') : ?>
                        <?php if ($membership_info['end_date']) : ?>
                            <?php printf(
                                __('Expira: %s', 'starter-memberships'),
                                date_i18n(get_option('date_format'), strtotime($membership_info['end_date']))
                            ); ?>
                        <?php else : ?>
                            <?php _e('Sin fecha de expiración', 'starter-memberships'); ?>
                        <?php endif; ?>
                        <?php if ($membership_info['granted_by_admin']) : ?>
                            <?php 
                            $admin_user = get_userdata($membership_info['granted_by_admin']);
                            $admin_name = $admin_user ? $admin_user->display_name : 'Admin #' . $membership_info['granted_by_admin'];
                            ?>
                            <br><span style="color: #0073aa;">🎁 <?php printf(__('Cedida por: %s', 'starter-memberships'), esc_html($admin_name)); ?></span>
                        <?php endif; ?>
                        <?php if ($membership_info['status'] === 'frozen') : ?>
                            <br><span style="color: #dc3545;">❄️ <?php _e('Membresía congelada', 'starter-memberships'); ?></span>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php _e('Sin membresía activa', 'starter-memberships'); ?>
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="membership_end_date"><?php _e('Fecha de Expiración', 'starter-memberships'); ?></label></th>
            <td>
                <input type="date" 
                       name="membership_end_date" 
                       id="membership_end_date" 
                       value="<?php echo $membership_info['end_date'] ? date('Y-m-d', strtotime($membership_info['end_date'])) : ''; ?>"
                       class="regular-text">
                <p class="description"><?php _e('Dejar vacío para membresía sin expiración manual.', 'starter-memberships'); ?></p>
            </td>
        </tr>
        <?php
        // Mostrar entregas gratis si el usuario tiene el beneficio
        $free_deliveries = null;
        if (function_exists('starter_benefit_registry')) {
            $handler = starter_benefit_registry()->get('free_deliveries');
            if ($handler) {
                $free_deliveries = $handler->apply($user->ID, []);
            }
        }
        if ($free_deliveries && $free_deliveries['total_allowed'] > 0) :
        ?>
        <tr>
            <th><?php _e('Entregas Gratis', 'starter-memberships'); ?></th>
            <td>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span style="font-size: 16px; font-weight: bold; color: <?php echo $free_deliveries['remaining'] > 0 ? '#28a745' : '#dc3545'; ?>;">
                        <?php echo esc_html($free_deliveries['remaining']); ?> / <?php echo esc_html($free_deliveries['total_allowed']); ?>
                    </span>
                    <span style="color: #666;">
                        (<?php printf(__('%d usadas', 'starter-memberships'), $free_deliveries['used']); ?>)
                    </span>
                </div>
                <p class="description">
                    <?php _e('Entregas gratis disponibles en el período actual de membresía.', 'starter-memberships'); ?>
                </p>
            </td>
        </tr>
        <?php endif; ?>
    </table>
    <?php
}

/**
 * Guardar campos de membresía desde perfil de usuario (admin)
 */
function starter_memberships_save_user_profile_fields($user_id) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $new_level = isset($_POST['membership_level']) ? absint($_POST['membership_level']) : 0;
    $new_end_date = isset($_POST['membership_end_date']) ? sanitize_text_field($_POST['membership_end_date']) : '';
    
    $current_level = starter_get_user_membership_level($user_id);
    
    // Si cambió el nivel, activar nueva membresía
    if ($new_level !== $current_level) {
        if ($new_level > 0) {
            // Activar membresía cedida por admin
            starter_activate_admin_membership($user_id, $new_level, $new_end_date);
        } else {
            // Desactivar membresía
            update_user_meta($user_id, '_membership_level', 0);
            delete_user_meta($user_id, '_membership_end_date');
            
            // Revocar token de verificación del carné digital
            if (function_exists('starter_invalidate_verification_token')) {
                starter_invalidate_verification_token($user_id);
            }
            
            starter_memberships_log_action(
                $user_id,
                'admin_deactivation',
                $current_level,
                0,
                ['admin_id' => get_current_user_id()]
            );
        }
    } elseif ($new_end_date && $new_level > 0) {
        // Solo actualizar fecha de expiración
        global $wpdb;
        $table = $wpdb->prefix . 'starter_user_memberships';
        
        $wpdb->update(
            $table,
            ['end_date' => $new_end_date . ' 23:59:59', 'updated_at' => current_time('mysql')],
            ['user_id' => $user_id, 'status' => 'active'],
            ['%s', '%s'],
            ['%d', '%s']
        );
        
        update_user_meta($user_id, '_membership_end_date', $new_end_date . ' 23:59:59');
    }
}

/**
 * Activar membresía cedida por admin
 * 
 * @param int $user_id ID del usuario
 * @param int $level Nivel de membresía
 * @param string $end_date Fecha de expiración (YYYY-MM-DD) o vacío para 1 año
 * @return int|false ID de la membresía o false
 */
function starter_activate_admin_membership($user_id, $level, $end_date = '') {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    
    // Obtener membresía actual si existe
    $current = starter_memberships_get_user_membership($user_id);
    $old_level = $current ? $current->membership_level : 0;
    
    // Calcular fechas
    $start_date = current_time('mysql');
    if ($end_date) {
        $end_date_full = $end_date . ' 23:59:59';
    } else {
        // Por defecto 1 año
        $end_date_full = date('Y-m-d H:i:s', strtotime('+1 year'));
    }
    
    // Desactivar membresías anteriores
    $wpdb->update(
        $table,
        ['status' => 'expired', 'updated_at' => current_time('mysql')],
        ['user_id' => $user_id, 'status' => 'active'],
        ['%s', '%s'],
        ['%d', '%s']
    );
    
    // También desactivar congeladas
    $wpdb->update(
        $table,
        ['status' => 'expired', 'updated_at' => current_time('mysql')],
        ['user_id' => $user_id, 'status' => 'frozen'],
        ['%s', '%s'],
        ['%d', '%s']
    );
    
    // Crear nueva membresía cedida por admin
    $result = $wpdb->insert(
        $table,
        [
            'user_id' => $user_id,
            'membership_level' => $level,
            'start_date' => $start_date,
            'end_date' => $end_date_full,
            'status' => 'active',
            'granted_by_admin' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ],
        ['%d', '%d', '%s', '%s', '%s', '%d', '%s']
    );
    
    if ($result === false) {
        error_log('Starter Memberships: Error al insertar membresía - ' . $wpdb->last_error);
        return false;
    }
    
    $membership_id = $wpdb->insert_id;
    
    // Actualizar user meta
    update_user_meta($user_id, '_membership_level', $level);
    update_user_meta($user_id, '_membership_end_date', $end_date_full);
    
    // Registrar en historial
    starter_memberships_log_action(
        $user_id,
        'admin_grant',
        $old_level,
        $level,
        [
            'admin_id' => get_current_user_id(),
            'end_date' => $end_date_full
        ],
        $membership_id
    );
    
    error_log(sprintf(
        'Starter Memberships: Admin %d cedió membresía nivel %d a usuario %d hasta %s',
        get_current_user_id(), $level, $user_id, $end_date_full
    ));
    
    return $membership_id;
}
