<?php
/**
 * Wompi History Page — Página de administración
 * 
 * Responsabilidades:
 * - Registrar submenú en WooCommerce
 * - Renderizar la página completa: filtros, tabla, paginación, resumen
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar submenú del historial Wompi
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Historial Wompi',
        'Historial Wompi',
        'manage_woocommerce',
        'starter-wompi-history',
        'starter_wompi_history_page'
    );
});

/**
 * Renderizar la página de historial
 */
function starter_wompi_history_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('No tienes permisos para acceder a esta página.');
    }

    global $wpdb;

    // Parámetros de filtro
    $status   = sanitize_text_field($_GET['status'] ?? 'all');
    $type     = sanitize_text_field($_GET['type'] ?? 'all');
    $search   = sanitize_text_field($_GET['s'] ?? '');
    $paged    = max(1, absint($_GET['paged'] ?? 1));
    $per_page = 20;

    $result     = starter_wompi_history_query(compact('status', 'type', 'search', 'paged', 'per_page'));
    $rows       = $result['rows'];
    $total      = $result['total'];
    $total_pages = ceil($total / $per_page);

    // Obtener datos reales de Wompi para las referencias de esta página
    $references = array_unique(array_filter(array_column($rows, 'reference')));
    $wompi_data = starter_wompi_batch_fetch($references);

    // Mapas de etiquetas
    $type_labels = [
        'membership'   => 'Membresía',
        'virtual_coins' => 'Virtual Coins',
        'card_payment' => 'Pago Tarjeta',
    ];
    $type_colors = [
        'membership'   => '#9b59b6',
        'virtual_coins' => '#f39c12',
        'card_payment' => '#3498db',
    ];

    // Estados Wompi (de la pasarela)
    $wompi_status_labels = [
        'APPROVED'  => 'Aprobada',
        'DECLINED'  => 'Rechazada',
        'VOIDED'    => 'Anulada',
        'ERROR'     => 'Error',
        'PENDING'   => 'Pendiente',
    ];
    $wompi_status_colors = [
        'APPROVED'  => '#27ae60',
        'DECLINED'  => '#e74c3c',
        'VOIDED'    => '#95a5a6',
        'ERROR'     => '#e74c3c',
        'PENDING'   => '#f39c12',
    ];

    // Estados internos (de nuestras tablas)
    $internal_status_labels = [
        'pending'    => 'Pendiente',
        'processing' => 'Procesando',
        'completed'  => 'Completado',
        'dismissed'  => 'Descartado',
        'error'      => 'Error',
        'failed'     => 'Fallido',
    ];
    $internal_status_colors = [
        'pending'    => '#f39c12',
        'processing' => '#3498db',
        'completed'  => '#27ae60',
        'dismissed'  => '#95a5a6',
        'error'      => '#e74c3c',
        'failed'     => '#e74c3c',
    ];

    // Métodos de pago Wompi
    $payment_method_labels = [
        'CARD'           => 'Tarjeta',
        'NEQUI'          => 'Nequi',
        'PSE'            => 'PSE',
        'BANCOLOMBIA_TRANSFER' => 'Bancolombia',
        'BANCOLOMBIA_COLLECT'  => 'Bancolombia QR',
        'DAVIPLATA'      => 'Daviplata',
    ];

    $base_url = admin_url('admin.php?page=starter-wompi-history');
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:8px;">
            <span class="dashicons dashicons-list-view" style="font-size:28px;width:28px;height:28px;"></span>
            Historial de Transacciones Wompi
        </h1>
        <p class="description" style="margin-top:4px;">
            Registro de todas las transacciones con la pasarela Wompi. Los datos de estado y método de pago se consultan directamente desde Wompi.
        </p>

        <!-- Filtros -->
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:16px 0;padding:12px 16px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
            <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <input type="hidden" name="page" value="starter-wompi-history">

                <div>
                    <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:3px;">Tipo</label>
                    <select name="type" style="min-width:140px;">
                        <option value="all" <?php selected($type, 'all'); ?>>Todos</option>
                        <option value="membership" <?php selected($type, 'membership'); ?>>Membresías</option>
                        <option value="virtual_coins" <?php selected($type, 'virtual_coins'); ?>>Virtual Coins</option>
                        <option value="card_payment" <?php selected($type, 'card_payment'); ?>>Pago Tarjeta</option>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:3px;">Estado interno</label>
                    <select name="status" style="min-width:130px;">
                        <option value="all" <?php selected($status, 'all'); ?>>Todos</option>
                        <option value="pending" <?php selected($status, 'pending'); ?>>Pendiente</option>
                        <option value="processing" <?php selected($status, 'processing'); ?>>Procesando</option>
                        <option value="completed" <?php selected($status, 'completed'); ?>>Completado</option>
                        <option value="dismissed" <?php selected($status, 'dismissed'); ?>>Descartado</option>
                        <option value="error" <?php selected($status, 'error'); ?>>Error</option>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:3px;">Buscar</label>
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
                           placeholder="Referencia, Wompi ID o User ID"
                           style="min-width:220px;">
                </div>

                <div>
                    <button type="submit" class="button">Filtrar</button>
                    <?php if ($status !== 'all' || $type !== 'all' || $search !== '') : ?>
                        <a href="<?php echo esc_url($base_url); ?>" class="button" style="margin-left:4px;">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <div style="margin-left:auto;font-size:13px;color:#666;">
                <strong><?php echo number_format_i18n($total); ?></strong> transacción<?php echo $total !== 1 ? 'es' : ''; ?>
            </div>
        </div>

        <!-- Tabla -->
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="width:90px;">Tipo</th>
                    <th>Referencia</th>
                    <th>Usuario</th>
                    <th style="width:120px;">Monto</th>
                    <th style="width:100px;">Estado Wompi</th>
                    <th style="width:90px;">Estado Int.</th>
                    <th style="width:90px;">Método Pago</th>
                    <th style="width:80px;">Orden WC</th>
                    <th style="width:140px;">Wompi TX ID</th>
                    <th style="width:130px;">Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)) : ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:24px;color:#999;">
                            No se encontraron transacciones<?php echo ($status !== 'all' || $type !== 'all' || $search !== '') ? ' con los filtros aplicados' : ''; ?>.
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) :
                        $tx_type_label = $type_labels[$row->tx_type] ?? $row->tx_type;
                        $tx_type_color = $type_colors[$row->tx_type] ?? '#999';

                        // Datos reales de Wompi
                        $wompi = $wompi_data[$row->reference] ?? null;

                        // Estado Wompi (de la pasarela)
                        $wompi_status = $wompi['status'] ?? null;
                        $wompi_st_label = $wompi_status ? ($wompi_status_labels[$wompi_status] ?? $wompi_status) : '—';
                        $wompi_st_color = $wompi_status ? ($wompi_status_colors[$wompi_status] ?? '#999') : '#ccc';

                        // Estado interno (de nuestra DB)
                        $int_st_label = $internal_status_labels[$row->status] ?? ucfirst($row->status);
                        $int_st_color = $internal_status_colors[$row->status] ?? '#999';

                        // Método de pago Wompi
                        $pm_type = $wompi['payment_method_type'] ?? null;
                        $pm_label = $pm_type ? ($payment_method_labels[$pm_type] ?? $pm_type) : '—';
                        // Detalles extra del método
                        $pm_extra = '';
                        if ($pm_type === 'CARD' && !empty($wompi['payment_method']['extra']['brand'])) {
                            $pm_extra = $wompi['payment_method']['extra']['brand'];
                            if (!empty($wompi['payment_method']['extra']['last_four'])) {
                                $pm_extra .= ' ···' . $wompi['payment_method']['extra']['last_four'];
                            }
                        }

                        // Wompi TX ID
                        $wompi_tx_id = $wompi['id'] ?? $row->wompi_transaction_id ?? null;

                        // Monto: preferir el de Wompi (en centavos) si disponible
                        if ($wompi && $wompi['amount_in_cents']) {
                            $display_amount = function_exists('site_format_price') ? strip_tags(site_format_price($wompi['amount_in_cents'] / 100)) : number_format($wompi['amount_in_cents'] / 100, 0, ',', '.');
                        } else {
                            $display_amount = function_exists('site_format_price') ? strip_tags(site_format_price(floatval($row->amount))) : number_format(floatval($row->amount), 0, ',', '.');
                        }
                        // Monto original si hay fee
                        $display_original = null;
                        if ($row->total_with_fee && floatval($row->total_with_fee) != floatval($row->amount)) {
                            $display_original = function_exists('site_format_price') ? strip_tags(site_format_price(floatval($row->amount))) : number_format(floatval($row->amount), 0, ',', '.');
                        }

                        // Link al usuario
                        $user_link = get_edit_user_link($row->user_id);

                        // Fecha
                        $created_display = $row->created_at ? wp_date('d/m/Y H:i', strtotime($row->created_at)) : '—';
                    ?>
                        <tr>
                            <td><code style="font-size:11px;"><?php echo esc_html($row->id); ?></code></td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr($tx_type_color); ?>;">
                                    <?php echo esc_html($tx_type_label); ?>
                                </span>
                            </td>
                            <td>
                                <code style="font-size:11px;word-break:break-all;"><?php echo esc_html($row->reference); ?></code>
                            </td>
                            <td>
                                <?php if ($user_link) : ?>
                                    <a href="<?php echo esc_url($user_link); ?>" title="Ver perfil">
                                        <?php echo esc_html($row->user_display); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html($row->user_display); ?>
                                <?php endif; ?>
                                <br><small style="color:#999;">ID: <?php echo esc_html($row->user_id); ?></small>
                            </td>
                            <td>
                                <strong>$<?php echo esc_html($display_amount); ?></strong>
                                <?php if ($display_original) : ?>
                                    <br><small style="color:#999;" title="Monto base sin fee">Base: $<?php echo esc_html($display_original); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($wompi_status) : ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr($wompi_st_color); ?>;">
                                        <?php echo esc_html($wompi_st_label); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color:#ccc;font-size:12px;" title="Sin datos de Wompi">Sin TX</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:600;color:#fff;background:<?php echo esc_attr($int_st_color); ?>;">
                                    <?php echo esc_html($int_st_label); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($pm_type) : ?>
                                    <span style="font-size:12px;font-weight:600;"><?php echo esc_html($pm_label); ?></span>
                                    <?php if ($pm_extra) : ?>
                                        <br><small style="color:#999;"><?php echo esc_html($pm_extra); ?></small>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span style="color:#ccc;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                // Solo mostrar orden WC para transacciones aprobadas en Wompi
                                $show_order = ($wompi_status === 'APPROVED');
                                $wc_order_id = $row->wc_order_id;

                                // Si no tenemos wc_order_id, buscar por referencia en postmeta
                                if ($show_order && !$wc_order_id && !empty($row->reference)) {
                                    $wc_order_id = $wpdb->get_var($wpdb->prepare(
                                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_starter_card_payment_reference' AND meta_value = %s LIMIT 1",
                                        $row->reference
                                    ));
                                }

                                $order_link = ($show_order && $wc_order_id) ? admin_url('post.php?post=' . $wc_order_id . '&action=edit') : null;
                                if ($order_link) : ?>
                                    <a href="<?php echo esc_url($order_link); ?>" title="Ver orden">#<?php echo esc_html($wc_order_id); ?></a>
                                <?php elseif ($show_order) : ?>
                                    <span style="color:#999;font-size:11px;" title="Orden no vinculada">Sin orden</span>
                                <?php else : ?>
                                    <span style="color:#ccc;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($wompi_tx_id) : ?>
                                    <code style="font-size:10px;word-break:break-all;"><?php echo esc_html($wompi_tx_id); ?></code>
                                <?php else : ?>
                                    <span style="color:#ccc;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?php echo esc_html($created_display); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Paginación -->
        <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom" style="margin-top:8px;">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format_i18n($total); ?> elemento<?php echo $total !== 1 ? 's' : ''; ?></span>
                    <span class="pagination-links">
                        <?php
                        $pagination_args = [
                            'status' => $status,
                            'type'   => $type,
                            's'      => $search,
                        ];

                        if ($paged > 1) {
                            echo '<a class="first-page button" href="' . esc_url(add_query_arg(array_merge($pagination_args, ['paged' => 1]), $base_url)) . '">«</a> ';
                            echo '<a class="prev-page button" href="' . esc_url(add_query_arg(array_merge($pagination_args, ['paged' => $paged - 1]), $base_url)) . '">‹</a> ';
                        } else {
                            echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
                            echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
                        }

                        echo '<span class="paging-input">' . $paged . ' de <span class="total-pages">' . $total_pages . '</span></span>';

                        if ($paged < $total_pages) {
                            echo ' <a class="next-page button" href="' . esc_url(add_query_arg(array_merge($pagination_args, ['paged' => $paged + 1]), $base_url)) . '">›</a>';
                            echo ' <a class="last-page button" href="' . esc_url(add_query_arg(array_merge($pagination_args, ['paged' => $total_pages]), $base_url)) . '">»</a>';
                        } else {
                            echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
                            echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
                        }
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Resumen rápido -->
        <?php
        $summary = starter_wompi_history_summary();
        if (!empty($summary)) :
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:20px;">
            <?php foreach ($summary as $s) : ?>
                <div style="flex:1;min-width:180px;background:#fff;border:1px solid #ccd0d4;border-left:4px solid <?php echo esc_attr($s['color']); ?>;border-radius:4px;padding:12px 16px;">
                    <div style="font-size:11px;text-transform:uppercase;font-weight:600;color:#666;margin-bottom:4px;"><?php echo esc_html($s['label']); ?></div>
                    <div style="font-size:22px;font-weight:700;color:#23282d;"><?php echo esc_html($s['count']); ?></div>
                    <div style="font-size:12px;color:#999;margin-top:2px;">
                        Pendientes: <?php echo esc_html($s['pending']); ?> · Completados: <?php echo esc_html($s['completed']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
