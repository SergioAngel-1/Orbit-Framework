<?php
/**
 * Recálculo: FC Checkout Invisible
 * 
 * Corrige transacciones de tipo 'checkout_purchase' que tienen FC acreditados
 * pero no tienen la transacción de débito ('used') compensatoria correspondiente.
 * 
 * Lógica:
 * 1. Obtiene transacciones checkout_purchase con points > 0 no recalculadas
 * 2. Para cada una, busca la transacción 'used' vinculada al mismo pedido
 * 3. Si no existe o es menor, calcula el exceso (FC acreditados - FC debitados)
 * 4. Muestra vista previa y al confirmar descuenta el exceso del saldo
 * 
 * @package Starter
 * @since 1.1.0
 * @since 1.2.0 Extraído a módulo independiente
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analizar transacciones de checkout_purchase sin débito compensatorio
 * 
 * @return array Lista de correcciones necesarias
 */
function starter_rp_analyze_checkout_corrections() {
    global $wpdb;
    
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    
    $txs = $wpdb->get_results(" 
        SELECT id, user_id, points, type, description, reference_id, created_at
        FROM $transactions_table
        WHERE type = 'checkout_purchase'
        AND points > 0
        AND description NOT LIKE '%[RECALCULADO]%'
        ORDER BY created_at ASC
    ");
    
    if (empty($txs)) {
        return [];
    }
    
    $corrections = [];
    
    foreach ($txs as $tx) {
        $order_id = (int) $tx->reference_id;
        
        if ($order_id <= 0 && preg_match('/aporte\s*#(\d+)/i', $tx->description, $m)) {
            $order_id = (int) $m[1];
        }
        
        if ($order_id <= 0) {
            continue;
        }
        
        $matching_used = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(ABS(SUM(points)), 0)
            FROM $transactions_table
            WHERE user_id = %d
            AND type = 'used'
            AND reference_id = %d
            AND points < 0
            AND description LIKE %s",
            $tx->user_id,
            $order_id,
            'Aporte%Virtual Coins%'
        ));
        
        $used_points = (int) $matching_used;
        $missing_points = (int) $tx->points - $used_points;
        
        if ($missing_points <= 0) {
            continue;
        }
        
        $user_info = get_userdata($tx->user_id);
        $order = wc_get_order($order_id);
        
        $corrections[] = [
            'tx_id'          => (int) $tx->id,
            'user_id'        => (int) $tx->user_id,
            'user_name'      => $user_info ? $user_info->display_name : "#{$tx->user_id}",
            'order_id'       => $order_id,
            'order_total'    => $order ? (float) $order->get_total() : 0,
            'purchased_points'=> (int) $tx->points,
            'used_points'    => $used_points,
            'missing_points' => $missing_points,
            'date'           => $tx->created_at,
            'description'    => $tx->description,
        ];
    }
    
    return $corrections;
}

/**
 * Aplicar correcciones de checkout invisible
 * 
 * Marca las transacciones como recalculadas y descuenta del saldo
 * los FC que fueron acreditados sin débito compensatorio.
 * 
 * @param array $corrections Lista de correcciones (de starter_rp_analyze_checkout_corrections)
 * @return array Resultado con totales
 */
function starter_rp_apply_checkout_corrections($corrections) {
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
                'description' => $new_description,
            ],
            ['id' => $c['tx_id']],
            ['%s'],
            ['%d']
        );
        
        if ($updated === false) {
            $results['errors'][] = "Error al marcar transacción #{$c['tx_id']}";
            continue;
        }
        
        $results['corrected']++;
        $results['total_excess'] += $c['missing_points'];
        
        if (!isset($user_adjustments[$c['user_id']])) {
            $user_adjustments[$c['user_id']] = 0;
        }
        $user_adjustments[$c['user_id']] += $c['missing_points'];
    }
    
    foreach ($user_adjustments as $user_id => $excess) {
        if ($excess <= 0) continue;
        
        $wpdb->query($wpdb->prepare("
            UPDATE $points_table SET 
                points = points - %d,
                last_update = %s
            WHERE user_id = %d
        ", $excess, current_time('mysql'), $user_id));
        
        $wpdb->insert($transactions_table, [
            'user_id'         => $user_id,
            'points'          => -$excess,
            'type'            => 'admin_deduct',
            'description'     => sprintf('Ajuste por recálculo de checkout invisible: -%d FC (checkout_purchase sin débito compensatorio)', $excess),
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
 * Render subtab: FC Checkout Invisible
 */
function starter_rp_render_checkout_subtab() {
    $action_performed = false;
    $apply_results = null;
    
    if (isset($_POST['starter_rp_apply_checkout_corrections']) && check_admin_referer('starter_rp_recalculate_checkout')) {
        $corrections = starter_rp_analyze_checkout_corrections();
        $apply_results = starter_rp_apply_checkout_corrections($corrections);
        $action_performed = true;
    }
    
    $all_corrections = $action_performed ? [] : starter_rp_analyze_checkout_corrections();
    $all_corrections = array_values(array_filter($all_corrections, function($c) {
        return isset($c['missing_points']) && intval($c['missing_points']) > 0;
    }));
    $total = count($all_corrections);
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total_pages = max(1, ceil($total / $per_page));
    $corrections = array_slice($all_corrections, ($current_page - 1) * $per_page, $per_page);
    
    if ($action_performed && $apply_results):
        starter_rp_render_apply_results($apply_results);
    elseif ($total === 0): ?>
        <div class="starter-rp-notice info"><p><?php _e('No se encontraron transacciones de checkout invisible pendientes de corrección.', 'starter-rp'); ?></p></div>
    <?php else: ?>
        <div class="starter-rp-notice warning">
            <p><strong><?php printf(__('Se encontraron %d compras invisibles de FC sin débito compensatorio.', 'starter-rp'), $total); ?></strong></p>
            <p><?php _e('Estas correcciones descontarán del saldo actual los FC que fueron acreditados por checkout_purchase pero nunca tuvieron su salida correspondiente.', 'starter-rp'); ?></p>
        </div>
        
        <?php starter_rp_render_pagination($current_page, $total_pages, 'checkout'); ?>
        
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('TX #', 'starter-rp'); ?></th>
                    <th><?php _e('Fecha', 'starter-rp'); ?></th>
                    <th><?php _e('Usuario', 'starter-rp'); ?></th>
                    <th><?php _e('Pedido', 'starter-rp'); ?></th>
                    <th><?php _e('FC Acreditados', 'starter-rp'); ?></th>
                    <th><?php _e('FC Debitados', 'starter-rp'); ?></th>
                    <th><?php _e('FC a Corregir', 'starter-rp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($corrections as $c): ?>
                <tr>
                    <td><?php echo $c['tx_id']; ?></td>
                    <td><?php echo date_i18n('Y-m-d H:i', strtotime($c['date'])); ?></td>
                    <td><?php echo esc_html($c['user_name']); ?> (#<?php echo $c['user_id']; ?>)</td>
                    <td><a href="<?php echo admin_url('post.php?post=' . $c['order_id'] . '&action=edit'); ?>">#<?php echo $c['order_id']; ?></a></td>
                    <td><?php echo number_format($c['purchased_points']); ?></td>
                    <td><?php echo number_format($c['used_points']); ?></td>
                    <td style="color: red; font-weight: bold;">-<?php echo number_format($c['missing_points']); ?> FC</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php starter_rp_render_pagination($current_page, $total_pages, 'checkout'); ?>
        
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=starter-rp-recalculate&subtab=checkout')); ?>" style="margin-top: 20px;">
            <?php wp_nonce_field('starter_rp_recalculate_checkout'); ?>
            <input type="hidden" name="subtab" value="checkout" />
            <p class="description" style="margin-bottom: 10px;">
                <?php _e('Esta acción marcará las transacciones checkout_purchase como recalculadas y descontará del saldo actual los FC faltantes.', 'starter-rp'); ?>
            </p>
            <button type="submit" name="starter_rp_apply_checkout_corrections" value="1" class="button button-primary button-hero" 
                onclick="return confirm('<?php esc_attr_e('¿Estás seguro? Esta acción no se puede deshacer.', 'starter-rp'); ?>');">
                <?php printf(__('Aplicar %d correcciones', 'starter-rp'), $total); ?>
            </button>
        </form>
    <?php endif;
}
