<?php
/**
 * Recálculo: FC por Membresía
 * 
 * Corrige transacciones de tipo 'membership_monthly' y 'membership_activation'
 * cuyos FC otorgados no coinciden con los configurados en el producto de membresía WC.
 * 
 * Lógica:
 * 1. Obtiene transacciones membership_monthly/activation no recalculadas
 * 2. Determina el nivel de membresía y product_id por múltiples fallbacks:
 *    reference_id → descripción → membresía activa en fecha → última membresía
 * 3. Compara FC otorgados vs configurados (_membership_monthly_points o nivel)
 * 4. Muestra vista previa y al confirmar ajusta transacciones y saldos
 * 
 * @package Starter
 * @since 1.1.0
 * @since 1.2.0 Extraído a módulo independiente
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analizar transacciones de membership_monthly y comparar con FC configurados en el producto
 * 
 * @return array Lista de correcciones necesarias
 */
function starter_rp_analyze_membership_corrections() {
    global $wpdb;
    
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    $memberships_table = $wpdb->prefix . 'starter_user_memberships';
    
    // Obtener transacciones de tipo membership_monthly y membership_activation (excluir ya recalculadas)
    $txs = $wpdb->get_results("
        SELECT id, user_id, points, type, description, reference_id, created_at
        FROM $transactions_table
        WHERE type IN ('membership_monthly', 'membership_activation')
        AND description NOT LIKE '%[RECALCULADO]%'
        ORDER BY created_at ASC
    ");
    
    if (empty($txs)) {
        return [];
    }
    
    $corrections = [];
    
    // Cachear productos de membresía: nivel → FC correctos
    $level_points_cache = [];
    
    foreach ($txs as $tx) {
        // Intentar determinar el nivel de membresía y product_id
        $membership_level = 0;
        $product_id = 0;
        
        // 1. reference_id puede ser membership_id → buscar en tabla de membresías
        if (!empty($tx->reference_id)) {
            $membership = $wpdb->get_row($wpdb->prepare("
                SELECT membership_level, product_id FROM $memberships_table WHERE id = %d
            ", $tx->reference_id));
            
            if ($membership) {
                $membership_level = (int) $membership->membership_level;
                $product_id = (int) $membership->product_id;
            }
        }
        
        // 2. Extraer nivel de la descripción: "nivel X" o "Nivel X"
        if ($membership_level === 0 && preg_match('/nivel\s*(\d+)/i', $tx->description, $m)) {
            $membership_level = (int) $m[1];
        }
        
        // 3. Fallback: buscar la membresía del usuario activa en la fecha de la transacción
        if ($membership_level === 0) {
            $active_membership = $wpdb->get_row($wpdb->prepare("
                SELECT membership_level, product_id FROM $memberships_table
                WHERE user_id = %d
                AND start_date <= %s
                AND (end_date >= %s OR end_date IS NULL)
                ORDER BY membership_level DESC
                LIMIT 1
            ", $tx->user_id, $tx->created_at, $tx->created_at));
            
            if ($active_membership) {
                $membership_level = (int) $active_membership->membership_level;
                $product_id = (int) $active_membership->product_id;
            }
        }
        
        // 4. Último fallback: buscar la membresía más reciente del usuario antes de la TX
        if ($membership_level === 0) {
            $last_membership = $wpdb->get_row($wpdb->prepare("
                SELECT membership_level, product_id FROM $memberships_table
                WHERE user_id = %d
                AND start_date <= %s
                ORDER BY start_date DESC
                LIMIT 1
            ", $tx->user_id, $tx->created_at));
            
            if ($last_membership) {
                $membership_level = (int) $last_membership->membership_level;
                $product_id = (int) $last_membership->product_id;
            }
        }
        
        // Obtener FC correctos del producto
        $correct_points = 0;
        if ($product_id > 0) {
            $correct_points = intval(get_post_meta($product_id, '_membership_monthly_points', true));
        }
        // Si no hay product_id o el producto no tiene FC, buscar por nivel
        if ($correct_points <= 0 && $membership_level > 0) {
            if (!isset($level_points_cache[$membership_level])) {
                $level_points_cache[$membership_level] = starter_rp_get_membership_level_points($membership_level);
            }
            $correct_points = $level_points_cache[$membership_level];
        }
        
        if ($correct_points <= 0) {
            continue; // No se puede determinar el monto correcto
        }
        
        $current_points = (int) $tx->points;
        $difference = $current_points - $correct_points;
        
        if ($difference === 0) {
            continue; // Ya está correcto
        }
        
        $user_info = get_userdata($tx->user_id);
        
        $corrections[] = [
            'tx_id'            => $tx->id,
            'user_id'          => $tx->user_id,
            'user_name'        => $user_info ? $user_info->display_name : "#{$tx->user_id}",
            'membership_level' => $membership_level,
            'product_id'       => $product_id,
            'current_points'   => $current_points,
            'correct_points'   => $correct_points,
            'difference'       => $difference,
            'date'             => $tx->created_at,
            'description'      => $tx->description,
        ];
    }
    
    return $corrections;
}

/**
 * Obtener FC configurados para un nivel de membresía buscando el producto WC asociado
 * 
 * @param int $level Nivel de membresía
 * @return int FC mensuales configurados para ese nivel
 */
function starter_rp_get_membership_level_points($level) {
    // Usar la clase Starter_Memberships que ya carga niveles desde productos WC
    if (class_exists('Starter_Memberships')) {
        $level_info = Starter_Memberships::get_membership_level($level);
        $points = isset($level_info['monthly_points']) ? intval($level_info['monthly_points']) : 0;
        return $points;
    }
    
    return 0;
}

/**
 * Aplicar correcciones de membresía
 * 
 * @param array $corrections Lista de correcciones (de starter_rp_analyze_membership_corrections)
 * @return array Resultado con totales
 */
function starter_rp_apply_membership_corrections($corrections) {
    global $wpdb;
    
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    $points_table = $wpdb->prefix . 'starter_user_points';
    
    $results = [
        'corrected'      => 0,
        'total_excess'   => 0,
        'users_affected' => [],
        'errors'         => [],
    ];
    
    $user_adjustments = [];
    
    foreach ($corrections as $c) {
        $new_description = $c['description'];
        if (strpos($new_description, '[RECALCULADO]') === false) {
            $new_description .= ' [RECALCULADO]';
        }
        
        $updated = $wpdb->update(
            $transactions_table,
            [
                'points'      => $c['correct_points'],
                'description' => $new_description,
            ],
            ['id' => $c['tx_id']],
            ['%d', '%s'],
            ['%d']
        );
        
        if ($updated !== false) {
            $results['corrected']++;
            $results['total_excess'] += $c['difference'];
            
            if (!isset($user_adjustments[$c['user_id']])) {
                $user_adjustments[$c['user_id']] = 0;
            }
            $user_adjustments[$c['user_id']] += $c['difference'];
        } else {
            $results['errors'][] = "Error al actualizar transacción #{$c['tx_id']}";
        }
    }
    
    // Aplicar ajustes de saldo por usuario
    foreach ($user_adjustments as $user_id => $excess) {
        if ($excess == 0) continue;
        
        $wpdb->query($wpdb->prepare("
            UPDATE $points_table SET 
                points = points - %d,
                last_update = %s
            WHERE user_id = %d
        ", $excess, current_time('mysql'), $user_id));
        
        $adj_description = $excess > 0
            ? sprintf('Ajuste por recálculo de FC de membresía: -%d FC (exceso corregido)', $excess)
            : sprintf('Ajuste por recálculo de FC de membresía: +%d FC (déficit corregido)', abs($excess));
        
        $wpdb->insert($transactions_table, [
            'user_id'         => $user_id,
            'points'          => -$excess,
            'type'            => 'admin_deduct',
            'description'     => $adj_description,
            'reference_id'    => null,
            'expiration_date' => null,
            'created_at'      => current_time('mysql'),
        ]);
        
        $user_info = get_userdata($user_id);
        $results['users_affected'][] = [
            'user_id'    => $user_id,
            'name'       => $user_info ? $user_info->display_name : "#{$user_id}",
            'adjustment' => -$excess,
        ];
    }
    
    return $results;
}

/**
 * Render subtab: FC por Membresía
 */
function starter_rp_render_memberships_subtab() {
    $action_performed = false;
    $apply_results = null;
    
    if (isset($_POST['starter_rp_apply_membership_corrections']) && check_admin_referer('starter_rp_recalculate_memberships')) {
        $corrections = starter_rp_analyze_membership_corrections();
        $apply_results = starter_rp_apply_membership_corrections($corrections);
        $action_performed = true;
    }
    
    $all_corrections = $action_performed ? [] : starter_rp_analyze_membership_corrections();
    $all_corrections = array_values(array_filter($all_corrections, function($c) {
        return isset($c['difference']) && intval($c['difference']) !== 0;
    }));
    $total = count($all_corrections);
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total_pages = max(1, ceil($total / $per_page));
    $corrections = array_slice($all_corrections, ($current_page - 1) * $per_page, $per_page);
    
    if ($action_performed && $apply_results):
        starter_rp_render_apply_results($apply_results);
    elseif ($total === 0): ?>
        <div class="starter-rp-notice info"><p><?php _e('No se encontraron transacciones de FC por membresía que necesiten corrección.', 'starter-rp'); ?></p></div>
    <?php else: ?>
        <div class="starter-rp-notice warning">
            <p><strong><?php printf(__('Se encontraron %d transacciones de membresía con FC incorrectos.', 'starter-rp'), $total); ?></strong></p>
            <p><?php _e('Los FC otorgados no coinciden con los configurados en el producto de membresía.', 'starter-rp'); ?></p>
        </div>
        
        <?php starter_rp_render_pagination($current_page, $total_pages, 'memberships'); ?>
        
        <table class="widefat striped" id="membership-corrections-table">
            <thead>
                <tr>
                    <th><?php _e('TX #', 'starter-rp'); ?></th>
                    <th><?php _e('Fecha', 'starter-rp'); ?></th>
                    <th><?php _e('Usuario', 'starter-rp'); ?></th>
                    <th><?php _e('Nivel', 'starter-rp'); ?></th>
                    <th><?php _e('Descripción', 'starter-rp'); ?></th>
                    <th><?php _e('FC Otorgados', 'starter-rp'); ?></th>
                    <th><?php _e('FC Correctos', 'starter-rp'); ?></th>
                    <th style="cursor:pointer;" onclick="starter_sort_table()"><?php _e('Diferencia ⇅', 'starter-rp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($corrections as $c): ?>
                <tr>
                    <td><?php echo $c['tx_id']; ?></td>
                    <td><?php echo date_i18n('Y-m-d H:i', strtotime($c['date'])); ?></td>
                    <td><?php echo esc_html($c['user_name']); ?> (#<?php echo $c['user_id']; ?>)</td>
                    <td><?php echo $c['membership_level']; ?></td>
                    <td><?php echo esc_html($c['description']); ?></td>
                    <td><?php echo number_format($c['current_points']); ?></td>
                    <td><?php echo number_format($c['correct_points']); ?></td>
                    <td data-sort="<?php echo $c['difference']; ?>" style="color: <?php echo $c['difference'] > 0 ? 'red' : 'green'; ?>; font-weight: bold;">
                        <?php echo $c['difference'] > 0 ? '-' : '+'; ?><?php echo number_format(abs($c['difference'])); ?> FC
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php starter_rp_render_pagination($current_page, $total_pages, 'memberships'); ?>
        
        <script>
        var starter_sort_asc = true;
        function starter_sort_table() {
            var table = document.getElementById('membership-corrections-table');
            var tbody = table.querySelector('tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b) {
                var aVal = parseInt(a.querySelector('td:last-child').getAttribute('data-sort')) || 0;
                var bVal = parseInt(b.querySelector('td:last-child').getAttribute('data-sort')) || 0;
                return starter_sort_asc ? aVal - bVal : bVal - aVal;
            });
            starter_sort_asc = !starter_sort_asc;
            rows.forEach(function(row) { tbody.appendChild(row); });
        }
        </script>
        
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=starter-rp-recalculate&subtab=memberships')); ?>" style="margin-top: 20px;">
            <?php wp_nonce_field('starter_rp_recalculate_memberships'); ?>
            <input type="hidden" name="subtab" value="memberships" />
            <p class="description" style="margin-bottom: 10px;">
                <?php _e('Esta acción modificará las transacciones y ajustará los saldos de los usuarios afectados.', 'starter-rp'); ?>
            </p>
            <button type="submit" name="starter_rp_apply_membership_corrections" value="1" class="button button-primary button-hero" 
                onclick="return confirm('<?php esc_attr_e('¿Estás seguro? Esta acción no se puede deshacer.', 'starter-rp'); ?>');">
                <?php printf(__('Aplicar %d correcciones', 'starter-rp'), $total); ?>
            </button>
        </form>
    <?php endif;
}
