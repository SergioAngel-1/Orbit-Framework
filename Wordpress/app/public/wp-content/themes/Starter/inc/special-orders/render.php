<?php
/**
 * Funciones de renderizado para Ventas Especiales
 * 
 * Contiene las funciones que generan el HTML de:
 * - Filas de la tabla de pedidos
 * - Tarjetas de resumen/estadísticas
 * - Paginación
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderizar una fila de compra desde tablas custom
 * 
 * Función unificada que renderiza tanto compras completadas como pendientes.
 * En vista pendiente muestra columnas extra (Motivo, Acciones).
 * 
 * @param object $row             Fila enriquecida de starter_so_query_purchases()
 * @param bool   $is_pending_view Si estamos en la vista de pendientes
 */
function starter_so_render_purchase_row($row, $is_pending_view = false) {
    $type_badge   = starter_so_build_type_badge($row->order_type);
    $details_html = starter_so_build_purchase_details_html($row);
    
    // Mapeo de estados internos a etiquetas legibles
    $status_labels = [
        'pending'              => 'Pendiente',
        'processing'           => 'Procesando',
        'completed'            => 'Completado',
        'error_activation'     => 'Error activación',
        'error_adding_points'  => 'Error puntos',
        'error_no_points_function' => 'Error sistema',
        'amount_mismatch'      => 'Monto no coincide',
        'dismissed'            => 'Descartado',
    ];
    $status_label = $status_labels[$row->status] ?? ucfirst($row->status);
    
    // Clase CSS del estado
    $status_css_map = [
        'pending'          => 'status-pending',
        'processing'       => 'status-processing',
        'completed'        => 'status-completed',
        'amount_mismatch'  => 'status-failed',
        'dismissed'        => 'status-cancelled',
    ];
    $status_class = $status_css_map[$row->status] ?? (str_starts_with($row->status, 'error') ? 'status-failed' : 'status-on-hold');
    
    $created = $row->created_at ? new DateTime($row->created_at) : null;
    
    ?>
    <tr>
        <td class="column-order">
            <?php if ($row->wc_order_id) : ?>
                <a href="<?php echo esc_url(get_edit_post_link($row->wc_order_id)); ?>" class="order-view">
                    <strong>#<?php echo esc_html($row->wc_order_id); ?></strong>
                </a>
            <?php else : ?>
                <span class="na" title="Sin orden WC asociada">&ndash;</span>
            <?php endif; ?>
        </td>
        <td class="column-type">
            <?php echo $type_badge; ?>
        </td>
        <td class="column-date">
            <?php if ($created) : ?>
                <time datetime="<?php echo esc_attr($created->format('c')); ?>">
                    <?php echo esc_html(date_i18n('d M Y', $created->getTimestamp())); ?>
                    <br><small><?php echo esc_html(date_i18n('g:i a', $created->getTimestamp())); ?></small>
                </time>
            <?php else : ?>
                <span class="na">&ndash;</span>
            <?php endif; ?>
        </td>
        <td class="column-customer">
            <?php if ($row->user_id) : ?>
                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $row->user_id)); ?>">
                    <?php echo esc_html($row->customer_name ?: 'Usuario #' . $row->user_id); ?>
                </a>
            <?php else : ?>
                <span class="na">Desconocido</span>
            <?php endif; ?>
            <?php if (!empty($row->customer_email)) : ?>
                <br><small class="description"><?php echo esc_html($row->customer_email); ?></small>
            <?php endif; ?>
        </td>
        <td class="column-details">
            <?php echo $details_html; ?>
        </td>
        <td class="column-reference">
            <?php if (!empty($row->reference)) : ?>
                <code class="wompi-reference"><?php echo esc_html($row->reference); ?></code>
            <?php else : ?>
                <span class="na">&ndash;</span>
            <?php endif; ?>
        </td>
        <td class="column-status">
            <mark class="order-status <?php echo esc_attr($status_class); ?>">
                <span><?php echo esc_html($status_label); ?></span>
            </mark>
        </td>
        <?php if ($is_pending_view) : ?>
        <td class="column-reason">
            <?php echo starter_so_get_pending_reason($row); ?>
        </td>
        <?php endif; ?>
        <td class="column-total">
            <?php echo wc_price($row->price); ?>
        </td>
        <?php if ($is_pending_view) : ?>
        <td class="column-actions">
            <button type="button" 
                    class="button button-small starter-so-complete-btn"
                    data-id="<?php echo esc_attr($row->id); ?>"
                    data-type="<?php echo esc_attr($row->order_type); ?>"
                    title="Marcar esta compra como completada manualmente">
                Completar
            </button>
            <button type="button" 
                    class="button button-small starter-so-dismiss-btn"
                    data-id="<?php echo esc_attr($row->id); ?>"
                    data-type="<?php echo esc_attr($row->order_type); ?>"
                    title="Descartar esta compra pendiente sin completarla"
                    style="margin-top: 4px; color: #a00;">
                Descartar
            </button>
        </td>
        <?php endif; ?>
    </tr>
    <?php
}

/**
 * Construir HTML de detalles para una compra (desde tablas custom)
 * 
 * @param object $row Fila de compra
 * @return string HTML
 */
function starter_so_build_purchase_details_html($row) {
    if ($row->order_type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        $level_name = 'Nivel ' . ($row->membership_level ?? '?');
        
        if (class_exists('Starter_Memberships') && !empty($row->membership_level)) {
            $level_info = Starter_Memberships::get_membership_level((int) $row->membership_level);
            if ($level_info) {
                $level_name = $level_info['icon'] . ' ' . $level_info['name'];
            }
        }
        
        $html  = '<strong>' . esc_html($level_name) . '</strong>';
        $html .= '<br><span class="description">';
        $html .= esc_html(($row->duration_days ?? 0) . ' días');
        if (!empty($row->monthly_points) && $row->monthly_points > 0) {
            $html .= ' &middot; ' . number_format($row->monthly_points) . ' FC/mes';
        }
        $html .= '</span>';
        
        return $html;
    }
    
    if ($row->order_type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
        $total_coins = $row->total_coins ?? 0;
        
        $html = '<strong>&#x1FA99; ' . number_format($total_coins) . ' FC</strong>';
        if (!empty($row->product_name)) {
            $html .= '<br><span class="description">' . esc_html($row->product_name) . '</span>';
        }
        
        return $html;
    }
    
    return '<span class="na">&ndash;</span>';
}

/**
 * Construir badge HTML para el tipo de orden
 * 
 * @param string $order_type Tipo de orden
 * @return string HTML del badge
 */
function starter_so_build_type_badge($order_type) {
    if ($order_type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        return '<span class="order-type-tag membership-tag">Membresía</span>';
    }
    return '<span class="order-type-tag fc-tag">Paquete FC</span>';
}

/**
 * Renderizar tarjetas de resumen/estadísticas
 * 
 * @param string $current_type Tipo de filtro actual
 */
function starter_so_render_summary($current_type) {
    $stats = starter_so_get_summary_stats($current_type);
    
    $show_membership = $stats['membership'] && ($current_type === 'all' || $current_type === STARTER_ORDER_TYPE_MEMBERSHIP);
    $show_fc         = $stats['virtual_coins'] && ($current_type === 'all' || $current_type === STARTER_ORDER_TYPE_VIRTUAL_COINS);
    
    if (!$show_membership && !$show_fc) {
        return;
    }
    
    ?>
    <div class="starter-so-summary">
        <h3>Resumen</h3>
        <div class="summary-cards">
            <?php if ($show_membership) : ?>
                <?php $m = $stats['membership']; ?>
                <div class="summary-card membership-card">
                    <div class="card-icon">&#x1F3C6;</div>
                    <div class="card-content">
                        <h4>Membresías</h4>
                        <div class="card-stats">
                            <div class="stat">
                                <span class="stat-value"><?php echo (int) $m->completed; ?></span>
                                <span class="stat-label">Completadas</span>
                            </div>
                            <div class="stat">
                                <span class="stat-value"><?php echo (int) $m->pending; ?></span>
                                <span class="stat-label">Pendientes</span>
                            </div>
                            <div class="stat">
                                <span class="stat-value"><?php echo wc_price($m->total_revenue ?: 0); ?></span>
                                <span class="stat-label">Ingresos</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($show_fc) : ?>
                <?php $fc = $stats['virtual_coins']; ?>
                <div class="summary-card fc-card">
                    <div class="card-icon">&#x1FA99;</div>
                    <div class="card-content">
                        <h4>Paquetes de Virtual Coins</h4>
                        <div class="card-stats">
                            <div class="stat">
                                <span class="stat-value"><?php echo (int) $fc->completed; ?></span>
                                <span class="stat-label">Completadas</span>
                            </div>
                            <div class="stat">
                                <span class="stat-value"><?php echo (int) $fc->pending; ?></span>
                                <span class="stat-label">Pendientes</span>
                            </div>
                            <div class="stat">
                                <span class="stat-value"><?php echo wc_price($fc->total_revenue ?: 0); ?></span>
                                <span class="stat-label">Ingresos</span>
                            </div>
                            <div class="stat">
                                <span class="stat-value"><?php echo number_format($fc->total_coins_sold ?: 0); ?></span>
                                <span class="stat-label">FC vendidos</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}


/**
 * Obtener motivo legible de por qué una compra está pendiente
 * 
 * Analiza el status y el contexto (referencia Wompi, tiempo transcurrido)
 * para dar una explicación útil al admin.
 * 
 * @param object $row Fila de compra pendiente
 * @return string HTML del motivo
 */
function starter_so_get_pending_reason($row) {
    $status = $row->status;
    $created = $row->created_at ? strtotime($row->created_at) : 0;
    $age_hours = $created ? (time() - $created) / 3600 : 0;
    $has_wompi_id = !empty($row->wompi_transaction_id);
    
    switch ($status) {
        case 'pending':
            if ($age_hours > 24) {
                return '<span class="reason-badge reason-abandoned" title="Más de 24h sin completar">Abandonada por el usuario</span>';
            }
            if ($age_hours > 1) {
                return '<span class="reason-badge reason-timeout" title="Más de 1h sin respuesta de pasarela">Sin respuesta de Wompi</span>';
            }
            return '<span class="reason-badge reason-waiting" title="Esperando pago del usuario">Esperando pago</span>';
            
        case 'processing':
            return '<span class="reason-badge reason-processing" title="El pago fue recibido pero el procesamiento no terminó">Procesamiento interrumpido</span>';
            
        case 'amount_mismatch':
            return '<span class="reason-badge reason-error" title="El monto pagado no coincide con el esperado">Monto no coincide</span>';
            
        case 'error_activation':
            return '<span class="reason-badge reason-error" title="El pago se recibió pero falló la activación de membresía">Error al activar membresía</span>';
            
        case 'error_adding_points':
            return '<span class="reason-badge reason-error" title="El pago se recibió pero falló la acreditación de FC">Error al acreditar FC</span>';
            
        case 'error_no_points_function':
            return '<span class="reason-badge reason-error" title="Función de puntos no disponible en el sistema">Error del sistema</span>';
            
        default:
            if (str_starts_with($status, 'error')) {
                return '<span class="reason-badge reason-error" title="' . esc_attr($status) . '">Error interno</span>';
            }
            return '<span class="reason-badge reason-unknown">' . esc_html(ucfirst($status)) . '</span>';
    }
}

/**
 * Renderizar la tabla unificada de compras (todas las vistas)
 * 
 * @param array $rows             Filas enriquecidas de starter_so_query_purchases()
 * @param bool  $is_pending_view  Si estamos en la vista de pendientes (muestra Motivo y Acciones)
 */
function starter_so_render_purchases_table($rows, $is_pending_view = false) {
    $columns = [
        'order'     => ['label' => 'Pedido',          'width' => '6%'],
        'type'      => ['label' => 'Tipo',             'width' => '8%'],
        'date'      => ['label' => 'Fecha',            'width' => '10%'],
        'customer'  => ['label' => 'Cliente',          'width' => '13%'],
        'details'   => ['label' => 'Detalles',         'width' => $is_pending_view ? '13%' : '20%'],
        'reference' => ['label' => 'Ref. Wompi',       'width' => '11%'],
        'status'    => ['label' => 'Estado',           'width' => '8%'],
    ];
    
    if ($is_pending_view) {
        $columns['reason']  = ['label' => 'Motivo',    'width' => '13%'];
    }
    
    $columns['total'] = ['label' => 'Total', 'width' => $is_pending_view ? '8%' : '10%'];
    
    if ($is_pending_view) {
        $columns['actions'] = ['label' => 'Acciones',  'width' => '8%'];
    }
    
    ?>
    <table class="wp-list-table widefat fixed striped starter-so-table">
        <thead>
            <tr>
                <?php foreach ($columns as $key => $col) : ?>
                    <th class="column-<?php echo esc_attr($key); ?>" style="width: <?php echo esc_attr($col['width']); ?>;">
                        <?php echo esc_html($col['label']); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)) : ?>
            <tr>
                <td colspan="<?php echo count($columns); ?>" style="text-align: center; padding: 20px;">
                    <em>No se encontraron ventas especiales con los filtros seleccionados.</em>
                </td>
            </tr>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <?php starter_so_render_purchase_row($row, $is_pending_view); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <?php foreach ($columns as $key => $col) : ?>
                    <th class="column-<?php echo esc_attr($key); ?>">
                        <?php echo esc_html($col['label']); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>
    <?php
}

/**
 * Generar HTML de paginación
 * 
 * Mantiene los filtros actuales (tipo, estado, búsqueda) en los enlaces.
 * 
 * @param int $current_page Página actual
 * @param int $total_pages  Total de páginas
 * @return string HTML de paginación
 */
function starter_so_pagination($current_page, $total_pages) {
    $base_url = admin_url('admin.php?page=' . STARTER_SPECIAL_ORDERS_PAGE_SLUG);
    
    // Preservar filtros activos en la URL
    $preserve_params = ['order_type', 'order_status', 's'];
    foreach ($preserve_params as $param) {
        if (isset($_GET[$param]) && $_GET[$param] !== '' && $_GET[$param] !== 'all') {
            $base_url = add_query_arg($param, sanitize_text_field($_GET[$param]), $base_url);
        }
    }
    
    $output = '<span class="pagination-links">';
    
    if ($current_page > 1) {
        $output .= '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">&laquo;</a> ';
        $output .= '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">&lsaquo;</a> ';
    } else {
        $output .= '<span class="tablenav-pages-navspan button disabled">&laquo;</span> ';
        $output .= '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span> ';
    }
    
    $output .= '<span class="paging-input">';
    $output .= $current_page . ' de <span class="total-pages">' . $total_pages . '</span>';
    $output .= '</span>';
    
    if ($current_page < $total_pages) {
        $output .= ' <a class="next-page button" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">&rsaquo;</a>';
        $output .= ' <a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">&raquo;</a>';
    } else {
        $output .= ' <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>';
        $output .= ' <span class="tablenav-pages-navspan button disabled">&raquo;</span>';
    }
    
    $output .= '</span>';
    
    return $output;
}
