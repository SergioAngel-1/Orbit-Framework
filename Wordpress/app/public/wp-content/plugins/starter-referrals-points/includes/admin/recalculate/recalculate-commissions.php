<?php
/**
 * Recálculo: Comisiones de Referidos
 * 
 * Corrige transacciones de tipo 'referral_commission' y 'referral_commission_level2'
 * que fueron calculadas incorrectamente (siempre usaron % de primera compra).
 * 
 * Lógica:
 * 1. Obtiene todas las transacciones de comisión
 * 2. Para cada una, extrae order_id de la descripción y buyer_id del reference_id
 * 3. Determina si era primera compra o posterior usando wc_get_orders()
 * 4. Recalcula el monto correcto con el % adecuado según membresía del referidor
 * 5. Muestra vista previa de diferencias antes de aplicar
 * 6. Al confirmar: ajusta las transacciones y actualiza saldos
 * 
 * @package Starter
 * @since 1.1.0
 * @since 1.2.0 Extraído a módulo independiente
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analizar transacciones de comisión y calcular correcciones necesarias
 * 
 * @return array Lista de correcciones con detalles
 */
function starter_rp_analyze_commission_corrections() {
    global $wpdb;
    
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    
    // Obtener todas las transacciones de comisión nivel 1 y nivel 2 (excluir ya recalculadas)
    $commissions = $wpdb->get_results("
        SELECT id, user_id, points, type, description, reference_id, created_at
        FROM $transactions_table
        WHERE type IN ('referral_commission', 'referral_commission_level2')
        AND description NOT LIKE '%[RECALCULADO]%'
        ORDER BY created_at ASC
    ");
    
    if (empty($commissions)) {
        return [];
    }
    
    $corrections = [];
    $options = Starter_RP()->get_options();
    
    foreach ($commissions as $tx) {
        // Extraer order_id de la descripción: "... (Pedido #12345 - ..."
        if (!preg_match('/Pedido #(\d+)/', $tx->description, $order_match)) {
            continue; // No se puede determinar el pedido
        }
        $order_id = (int) $order_match[1];
        
        // Extraer porcentaje usado de la descripción: "... - 10% de ..."
        $old_percentage = 0;
        if (preg_match('/- ([\d.,]+)% de/', $tx->description, $pct_match)) {
            $old_percentage = floatval(str_replace(',', '.', $pct_match[1]));
        }
        
        // buyer_id está en reference_id
        $buyer_id = (int) $tx->reference_id;
        if ($buyer_id <= 0) {
            continue;
        }
        
        // referrer_id es el user_id de la transacción (quien recibió la comisión)
        $referrer_id = (int) $tx->user_id;
        
        // Obtener el pedido para saber el total
        $order = wc_get_order($order_id);
        if (!$order) {
            continue; // Pedido no encontrado
        }
        $order_total = (float) $order->get_total();
        
        // Determinar si era primera compra del buyer ANTES de este pedido
        $previous_orders = wc_get_orders([
            'customer_id' => $buyer_id,
            'status'      => ['wc-completed', 'wc-processing'],
            'limit'       => 2,
            'return'      => 'ids',
            'exclude'     => [$order_id],
            'type'        => 'shop_order',
            'date_before' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
        ]);
        
        $is_first_purchase = (count($previous_orders) === 0);
        
        // Calcular el porcentaje correcto según tipo de transacción
        if ($tx->type === 'referral_commission') {
            $correct_percentage = starter_rp_get_commission_for_user(
                $referrer_id, 
                $is_first_purchase ? 'first' : 'subsequent', 
                $options
            );
        } else {
            // level2: siempre usa 'level2'
            $correct_percentage = starter_rp_get_commission_for_user($referrer_id, 'level2', $options);
        }
        
        $correct_points = floor(($order_total * $correct_percentage) / 100);
        $current_points = (int) $tx->points;
        $difference = $current_points - $correct_points; // Positivo = se dieron de más
        
        // Solo incluir si hay diferencia
        if ($difference === 0) {
            continue;
        }
        
        $buyer_info = get_userdata($buyer_id);
        $referrer_info = get_userdata($referrer_id);
        
        $corrections[] = [
            'tx_id'              => $tx->id,
            'tx_type'            => $tx->type,
            'referrer_id'        => $referrer_id,
            'referrer_name'      => $referrer_info ? $referrer_info->display_name : "#{$referrer_id}",
            'buyer_id'           => $buyer_id,
            'buyer_name'         => $buyer_info ? $buyer_info->display_name : "#{$buyer_id}",
            'order_id'           => $order_id,
            'order_total'        => $order_total,
            'is_first_purchase'  => $is_first_purchase,
            'old_percentage'     => $old_percentage,
            'correct_percentage' => $correct_percentage,
            'current_points'     => $current_points,
            'correct_points'     => $correct_points,
            'difference'         => $difference, // Positivo = exceso a restar
            'date'               => $tx->created_at,
            'description'        => $tx->description,
        ];
    }
    
    return $corrections;
}

/**
 * Aplicar las correcciones de comisiones
 * 
 * @param array $corrections Lista de correcciones (de starter_rp_analyze_commission_corrections)
 * @return array Resultado con totales
 */
function starter_rp_apply_commission_corrections($corrections) {
    global $wpdb;
    
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    $points_table = $wpdb->prefix . 'starter_user_points';
    
    $results = [
        'corrected'    => 0,
        'total_excess' => 0,
        'users_affected' => [],
        'errors'       => [],
    ];
    
    // Agrupar diferencias por usuario para actualizar saldo una sola vez
    $user_adjustments = [];
    
    foreach ($corrections as $c) {
        $referrer_id = $c['referrer_id'];
        $is_first = $c['is_first_purchase'];
        $buyer_name = $c['buyer_name'];
        $order_id = $c['order_id'];
        
        // Obtener nivel de membresía del referidor
        $referrer_level = 0;
        if (function_exists('starter_get_user_membership_level')) {
            $referrer_level = starter_get_user_membership_level($referrer_id);
        }
        $level_text = $referrer_level > 0 ? " [Nivel $referrer_level]" : '';
        
        // Construir nueva descripción
        if ($c['tx_type'] === 'referral_commission') {
            $new_description = sprintf(
                'Comisión %s por aporte de referido: %s (Pedido #%d - %s%% de %s)%s [RECALCULADO]',
                $is_first ? '(primer aporte)' : '(aporte posterior)',
                $buyer_name,
                $order_id,
                $c['correct_percentage'],
                wc_price($c['order_total']),
                $level_text
            );
        } else {
            $new_description = sprintf(
                'Comisión nivel 2 por aporte de referido indirecto: %s (Pedido #%d - %s%% de %s)%s [RECALCULADO]',
                $buyer_name,
                $order_id,
                $c['correct_percentage'],
                wc_price($c['order_total']),
                $level_text
            );
        }
        
        // Actualizar la transacción
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
            
            // Acumular ajuste por usuario
            if (!isset($user_adjustments[$referrer_id])) {
                $user_adjustments[$referrer_id] = 0;
            }
            $user_adjustments[$referrer_id] += $c['difference'];
        } else {
            $results['errors'][] = "Error al actualizar transacción #{$c['tx_id']}";
        }
    }
    
    // Aplicar ajustes de saldo por usuario
    foreach ($user_adjustments as $user_id => $excess) {
        if ($excess == 0) continue;
        
        // excess > 0 = se dieron puntos de más → restar del saldo
        // excess < 0 = se dieron puntos de menos → sumar al saldo
        $wpdb->query($wpdb->prepare("
            UPDATE $points_table SET 
                points = points - %d,
                last_update = %s
            WHERE user_id = %d
        ", $excess, current_time('mysql'), $user_id));
        
        // Registrar transacción de ajuste
        $adj_description = $excess > 0
            ? sprintf('Ajuste por recálculo de comisiones: -%d FC (exceso corregido)', $excess)
            : sprintf('Ajuste por recálculo de comisiones: +%d FC (déficit corregido)', abs($excess));
        
        $wpdb->insert($transactions_table, [
            'user_id'     => $user_id,
            'points'      => -$excess,
            'type'        => 'admin_deduct',
            'description' => $adj_description,
            'reference_id'=> null,
            'expiration_date' => null,
            'created_at'  => current_time('mysql'),
        ]);
        
        $user_info = get_userdata($user_id);
        $results['users_affected'][] = [
            'user_id'   => $user_id,
            'name'      => $user_info ? $user_info->display_name : "#{$user_id}",
            'adjustment' => -$excess,
        ];
        
        starter_rp_log(sprintf(
            'Recálculo comisiones: usuario %d ajustado en %d FC',
            $user_id, -$excess
        ));
    }
    
    return $results;
}

/**
 * Render subtab: Comisiones de Referidos
 */
function starter_rp_render_commissions_subtab() {
    $action_performed = false;
    $apply_results = null;
    
    if (isset($_POST['starter_rp_apply_corrections']) && check_admin_referer('starter_rp_recalculate')) {
        $corrections = starter_rp_analyze_commission_corrections();
        $apply_results = starter_rp_apply_commission_corrections($corrections);
        $action_performed = true;
    }
    
    $all_corrections = $action_performed ? [] : starter_rp_analyze_commission_corrections();
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
        <div class="starter-rp-notice info"><p><?php _e('No se encontraron comisiones de referidos que necesiten corrección.', 'starter-rp'); ?></p></div>
    <?php else: ?>
        <div class="starter-rp-notice warning">
            <p><strong><?php printf(__('Se encontraron %d transacciones con comisiones incorrectas.', 'starter-rp'), $total); ?></strong></p>
            <p><?php _e('Usaron el porcentaje de "primera compra" cuando debían usar "compras siguientes".', 'starter-rp'); ?></p>
        </div>
        
        <?php starter_rp_render_pagination($current_page, $total_pages, 'commissions'); ?>
        
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('TX #', 'starter-rp'); ?></th>
                    <th><?php _e('Fecha', 'starter-rp'); ?></th>
                    <th><?php _e('Referidor', 'starter-rp'); ?></th>
                    <th><?php _e('Comprador', 'starter-rp'); ?></th>
                    <th><?php _e('Pedido', 'starter-rp'); ?></th>
                    <th><?php _e('¿1ra compra?', 'starter-rp'); ?></th>
                    <th><?php _e('% Usado', 'starter-rp'); ?></th>
                    <th><?php _e('% Correcto', 'starter-rp'); ?></th>
                    <th><?php _e('FC Actual', 'starter-rp'); ?></th>
                    <th><?php _e('FC Correcto', 'starter-rp'); ?></th>
                    <th><?php _e('Diferencia', 'starter-rp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($corrections as $c): ?>
                <tr>
                    <td><?php echo $c['tx_id']; ?></td>
                    <td><?php echo date_i18n('Y-m-d H:i', strtotime($c['date'])); ?></td>
                    <td><?php echo esc_html($c['referrer_name']); ?></td>
                    <td><?php echo esc_html($c['buyer_name']); ?></td>
                    <td><a href="<?php echo admin_url('post.php?post=' . $c['order_id'] . '&action=edit'); ?>">#<?php echo $c['order_id']; ?></a></td>
                    <td><?php echo $c['is_first_purchase'] ? 'Sí' : 'No'; ?></td>
                    <td><?php echo $c['old_percentage']; ?>%</td>
                    <td><?php echo $c['correct_percentage']; ?>%</td>
                    <td><?php echo number_format($c['current_points']); ?></td>
                    <td><?php echo number_format($c['correct_points']); ?></td>
                    <td style="color: <?php echo $c['difference'] > 0 ? 'red' : 'green'; ?>; font-weight: bold;">
                        <?php echo $c['difference'] > 0 ? '-' : '+'; ?><?php echo number_format(abs($c['difference'])); ?> FC
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php starter_rp_render_pagination($current_page, $total_pages, 'commissions'); ?>
        
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=starter-rp-recalculate&subtab=commissions')); ?>" style="margin-top: 20px;">
            <?php wp_nonce_field('starter_rp_recalculate'); ?>
            <input type="hidden" name="subtab" value="commissions" />
            <p class="description" style="margin-bottom: 10px;">
                <?php _e('Esta acción modificará las transacciones y ajustará los saldos de los usuarios afectados.', 'starter-rp'); ?>
            </p>
            <button type="submit" name="starter_rp_apply_corrections" value="1" class="button button-primary button-hero" 
                onclick="return confirm('<?php esc_attr_e('¿Estás seguro? Esta acción no se puede deshacer.', 'starter-rp'); ?>');">
                <?php printf(__('Aplicar %d correcciones', 'starter-rp'), $total); ?>
            </button>
        </form>
    <?php endif;
}
