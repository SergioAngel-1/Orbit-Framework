<?php
/**
 * Funciones adicionales de usuario para membresías
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cancelar membresía de un usuario
 * 
 * @param int $user_id ID del usuario
 * @param string $reason Razón de cancelación
 * @return bool True si se canceló
 */
function starter_cancel_user_membership($user_id, $reason = '') {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    $membership = starter_memberships_get_user_membership($user_id);
    
    if (!$membership) {
        return false;
    }
    
    // Actualizar estado
    $wpdb->update(
        $table,
        ['status' => 'cancelled'],
        ['id' => $membership->id],
        ['%s'],
        ['%d']
    );
    
    // Actualizar user meta
    update_user_meta($user_id, '_membership_level', 0);
    delete_user_meta($user_id, '_membership_end_date');
    
    // Registrar en historial
    starter_memberships_log_action(
        $user_id,
        'cancellation',
        $membership->membership_level,
        0,
        ['reason' => $reason],
        $membership->id
    );
    
    // Disparar acción
    do_action('starter_membership_cancelled', $user_id, $membership->id, $reason);
    
    return true;
}

/**
 * Verificar si un usuario puede comprar una membresía específica
 * 
 * @param int $user_id ID del usuario
 * @param int $level Nivel de membresía
 * @return array ['can_purchase' => bool, 'reason' => string]
 */
function starter_can_user_purchase_membership($user_id, $level) {
    // Verificar si el usuario está logueado
    if (!$user_id) {
        return [
            'can_purchase' => false,
            'reason' => __('Debes iniciar sesión para comprar una membresía.', 'starter-memberships')
        ];
    }
    
    // Verificar si el usuario está aprobado
    $pending = get_user_meta($user_id, 'pending_approval', true);
    if ($pending === '1') {
        return [
            'can_purchase' => false,
            'reason' => __('Tu cuenta está pendiente de aprobación.', 'starter-memberships')
        ];
    }
    
    // Verificar membresía actual
    $current_level = starter_get_user_membership_level($user_id);
    
    if ($current_level >= $level) {
        return [
            'can_purchase' => true,
            'reason' => __('Puedes renovar o extender tu membresía actual.', 'starter-memberships'),
            'is_renewal' => true
        ];
    }
    
    return [
        'can_purchase' => true,
        'reason' => '',
        'is_upgrade' => $current_level > 0
    ];
}

/**
 * Obtener estadísticas de membresía de un usuario
 * 
 * @param int $user_id ID del usuario
 * @return array Estadísticas
 */
function starter_get_user_membership_stats($user_id) {
    global $wpdb;
    
    $memberships_table = $wpdb->prefix . 'starter_user_memberships';
    $points_table = $wpdb->prefix . 'starter_membership_points';
    
    // Total de membresías
    $total_memberships = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $memberships_table WHERE user_id = %d",
        $user_id
    ));
    
    // Total de puntos recibidos por membresía
    $total_points = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(points_amount) FROM $points_table WHERE user_id = %d",
        $user_id
    ));
    
    // Membresía actual
    $current = starter_memberships_get_user_membership($user_id);
    
    // Días restantes
    $days_remaining = 0;
    if ($current && $current->end_date) {
        $days_remaining = max(0, ceil((strtotime($current->end_date) - time()) / DAY_IN_SECONDS));
    }
    
    return [
        'total_memberships' => intval($total_memberships),
        'total_points_received' => intval($total_points),
        'current_level' => $current ? $current->membership_level : 0,
        'days_remaining' => $days_remaining,
        'is_active' => $current && $current->status === 'active',
        'auto_renew' => $current ? (bool)$current->auto_renew : false
    ];
}

/**
 * Agregar columna de membresía en lista de usuarios (admin)
 */
add_filter('manage_users_columns', 'starter_memberships_user_columns');
add_filter('manage_users_custom_column', 'starter_memberships_user_column_content', 10, 3);

function starter_memberships_user_columns($columns) {
    $columns['membership'] = __('Membresía', 'starter-memberships');
    return $columns;
}

function starter_memberships_user_column_content($value, $column_name, $user_id) {
    if ($column_name !== 'membership') {
        return $value;
    }
    
    $membership = starter_memberships_get_user_membership($user_id);
    $output = '';
    
    // Si está congelada, mostrar membresía original tachada + "Congelada"
    if ($membership && $membership->status === 'frozen') {
        $original_level = intval($membership->membership_level);
        $original_info = Starter_Memberships::get_membership_level($original_level);
        
        // Membresía original tachada
        $output .= '<span style="color: ' . esc_attr($original_info['color']) . '; text-decoration: line-through; opacity: 0.6;">';
        $output .= esc_html($original_info['icon'] . ' ' . $original_info['name']);
        $output .= '</span>';
        
        // Estado congelado
        $output .= '<br><span style="color: #0073aa; font-weight: bold;">❄️ ' . __('Congelada', 'starter-memberships') . '</span>';
        
        return $output;
    }
    
    // Membresía normal (activa o sin membresía)
    $level = starter_get_user_membership_level($user_id);
    $level_info = Starter_Memberships::get_membership_level($level);
    
    $output = '<span style="color: ' . esc_attr($level_info['color']) . ';">';
    $output .= esc_html($level_info['icon'] . ' ' . $level_info['name']);
    $output .= '</span>';
    
    if ($membership && $membership->end_date) {
        $days = ceil((strtotime($membership->end_date) - time()) / DAY_IN_SECONDS);
        if ($days > 0) {
            $output .= '<br><small>' . sprintf(__('%d días restantes', 'starter-memberships'), $days) . '</small>';
        } else {
            $output .= '<br><small style="color: #dc3545;">' . __('Expirada', 'starter-memberships') . '</small>';
        }
    }
    
    // Si no tiene membresía activa, mostrar la última expirada como referencia
    if (!$membership && $level === 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'starter_user_memberships';
        $last_expired = $wpdb->get_row($wpdb->prepare(
            "SELECT membership_level, end_date FROM $table
             WHERE user_id = %d AND (status = 'expired' OR (status = 'active' AND end_date <= NOW()))
             ORDER BY end_date DESC LIMIT 1",
            $user_id
        ));
        if ($last_expired) {
            $expired_level = intval($last_expired->membership_level);
            $expired_info = Starter_Memberships::get_membership_level($expired_level);
            $expired_date = date_i18n('j M Y', strtotime($last_expired->end_date));
            $output .= '<br><small style="color: #996800; opacity: 0.8;">';
            $output .= sprintf(
                __('Antes: %s (expiró %s)', 'starter-memberships'),
                esc_html($expired_info['icon'] . ' ' . $expired_info['name']),
                $expired_date
            );
            $output .= '</small>';
        }
    }
    
    return $output;
}

/**
 * Filtrar usuarios por membresía (admin)
 */
add_action('restrict_manage_users', 'starter_memberships_user_filter');
add_filter('pre_get_users', 'starter_memberships_filter_users_query');

function starter_memberships_user_filter() {
    $levels = Starter_Memberships::get_all_membership_levels();
    $selected = isset($_GET['membership_level']) ? sanitize_text_field($_GET['membership_level']) : '';
    
    // Contar usuarios por estado
    global $wpdb;
    $table = $wpdb->prefix . 'starter_user_memberships';
    $frozen_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'frozen'");
    $active_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'active' AND end_date > NOW()");
    $expiring_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table m
         INNER JOIN (SELECT user_id, MAX(id) AS max_id FROM $table GROUP BY user_id) latest ON m.id = latest.max_id
         WHERE m.status = 'active' AND m.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
        7
    ));
    $expired_count = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table m
         INNER JOIN (SELECT user_id, MAX(id) AS max_id FROM $table GROUP BY user_id) latest ON m.id = latest.max_id
         WHERE (m.status = 'active' AND m.end_date <= NOW()) OR m.status = 'expired'"
    );
    ?>
    <select name="membership_level" id="starter-membership-filter" style="float: none; margin-left: 10px;">
        <option value=""><?php _e('Todas las membresías', 'starter-memberships'); ?></option>
        <option value="frozen" <?php selected($selected, 'frozen'); ?>>
            ❄️ <?php printf(__('Membresía congelada (%d)', 'starter-memberships'), $frozen_count); ?>
        </option>
        <option value="active" <?php selected($selected, 'active'); ?>>
            ✅ <?php printf(__('Membresía activa (%d)', 'starter-memberships'), $active_count); ?>
        </option>
        <option value="expiring" <?php selected($selected, 'expiring'); ?>>
            ⏰ <?php printf(__('Por expirar - 7 días (%d)', 'starter-memberships'), $expiring_count); ?>
        </option>
        <option value="expired" <?php selected($selected, 'expired'); ?>>
            ❌ <?php printf(__('Expiradas (%d)', 'starter-memberships'), $expired_count); ?>
        </option>
        <?php foreach ($levels as $level_id => $level) : ?>
            <option value="<?php echo esc_attr($level_id); ?>" <?php selected($selected, (string)$level_id); ?>>
                <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <script>
    document.getElementById('starter-membership-filter').addEventListener('change', function() {
        var url = new URL(window.location.href);
        if (this.value === '') {
            url.searchParams.delete('membership_level');
        } else {
            url.searchParams.set('membership_level', this.value);
        }
        url.searchParams.delete('paged');
        window.location.href = url.toString();
    });
    </script>
    <?php
}

function starter_memberships_filter_users_query($query) {
    global $pagenow, $wpdb;
    
    if (!is_admin() || $pagenow !== 'users.php') {
        return $query;
    }
    
    if (!isset($_GET['membership_level']) || $_GET['membership_level'] === '') {
        return $query;
    }
    
    $filter_value = sanitize_text_field($_GET['membership_level']);
    $table = $wpdb->prefix . 'starter_user_memberships';
    
    // Filtrar por estado (frozen/active/expiring/expired)
    if ($filter_value === 'frozen' || $filter_value === 'active') {
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $table WHERE status = %s",
            $filter_value
        ));
        
        if (!empty($user_ids)) {
            $query->set('include', $user_ids);
        } else {
            $query->set('include', [0]);
        }
    } elseif ($filter_value === 'expiring') {
        // Solo la membresía más reciente de cada usuario
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT m.user_id FROM $table m
             INNER JOIN (SELECT user_id, MAX(id) AS max_id FROM $table GROUP BY user_id) latest ON m.id = latest.max_id
             WHERE m.status = 'active' 
             AND m.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
            7
        ));
        
        if (!empty($user_ids)) {
            $query->set('include', $user_ids);
        } else {
            $query->set('include', [0]);
        }
    } elseif ($filter_value === 'expired') {
        // Solo la membresía más reciente de cada usuario
        $user_ids = $wpdb->get_col(
            "SELECT m.user_id FROM $table m
             INNER JOIN (SELECT user_id, MAX(id) AS max_id FROM $table GROUP BY user_id) latest ON m.id = latest.max_id
             WHERE (m.status = 'active' AND m.end_date <= NOW())
                OR m.status = 'expired'"
        );
        
        if (!empty($user_ids)) {
            $query->set('include', $user_ids);
        } else {
            $query->set('include', [0]);
        }
    } else {
        // Filtrar por nivel de membresía (buscar en tabla de membresías activas)
        $level = intval($filter_value);
        
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $table WHERE membership_level = %d AND status = 'active'",
            $level
        ));
        
        if (!empty($user_ids)) {
            $query->set('include', $user_ids);
        } else {
            // No hay usuarios con este nivel, forzar resultado vacío
            $query->set('include', [0]);
        }
    }
    
    return $query;
}
