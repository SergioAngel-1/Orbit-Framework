<?php
/**
 * Página de administración: Ventas Especiales
 * 
 * Registra el submenú bajo WooCommerce y renderiza la vista
 * con tabla de pedidos, filtros, búsqueda y resumen.
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar la página de administración bajo el menú de WooCommerce
 */
add_action('admin_menu', 'starter_so_register_admin_page');
function starter_so_register_admin_page() {
    add_submenu_page(
        'woocommerce',
        'Ventas Especiales',
        'Ventas Especiales',
        'manage_woocommerce',
        STARTER_SPECIAL_ORDERS_PAGE_SLUG,
        'starter_so_render_admin_page'
    );
}

/**
 * Renderizar la página de Ventas Especiales
 */
function starter_so_render_admin_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('No tienes permisos para acceder a esta página.', 'starter'));
    }
    
    // Parámetros de filtro (sanitizados)
    $current_type   = isset($_GET['order_type']) ? sanitize_text_field($_GET['order_type']) : 'all';
    $current_status = isset($_GET['order_status']) ? sanitize_text_field($_GET['order_status']) : 'all';
    $search_term    = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $paged          = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    
    // Validar tipo contra valores permitidos
    $valid_types = array_merge(['all', STARTER_SPECIAL_ORDERS_VIEW_PENDING], starter_get_special_order_types());
    if (!in_array($current_type, $valid_types, true)) {
        $current_type = 'all';
    }
    
    // Determinar si estamos en la vista de pendientes
    $is_pending_view = ($current_type === STARTER_SPECIAL_ORDERS_VIEW_PENDING);
    
    // Determinar tipo de consulta y estado
    // Las pestañas normales (Todos, Membresías, Paquetes FC) solo muestran completados por defecto.
    // La pestaña Pendientes muestra todos los no-completados.
    $query_type = $is_pending_view ? 'all' : $current_type;
    $query_status = $is_pending_view ? 'pending_only' : ($current_status === 'all' ? 'completed' : $current_status);
    
    // Consulta unificada desde tablas custom (fuente de verdad)
    $result      = starter_so_query_purchases($query_type, $query_status, $search_term, $paged);
    $rows        = $result['rows'];
    $total_items = $result['total'];
    $total_pages = $result['pages'];
    
    // Obtener contadores para las pestañas
    $counts        = starter_so_get_counts();
    $pending_count = starter_so_get_pending_count();
    
    // URLs de las pestañas
    $base_page_url = admin_url('admin.php?page=' . STARTER_SPECIAL_ORDERS_PAGE_SLUG);
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Ventas Especiales</h1>
        <p class="description">Pedidos de Membresías y Paquetes de Virtual Coins procesados a través de Wompi.</p>
        <hr class="wp-header-end">
        
        <!-- Pestañas de tipo -->
        <ul class="subsubsub">
            <li>
                <a href="<?php echo esc_url(add_query_arg('order_type', 'all', $base_page_url)); ?>" 
                   class="<?php echo $current_type === 'all' ? 'current' : ''; ?>">
                    Todos <span class="count">(<?php echo esc_html($counts['all']); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('order_type', STARTER_ORDER_TYPE_MEMBERSHIP, $base_page_url)); ?>"
                   class="<?php echo $current_type === STARTER_ORDER_TYPE_MEMBERSHIP ? 'current' : ''; ?>">
                    &#x1F3C6; Membresías <span class="count">(<?php echo esc_html($counts['membership']); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('order_type', STARTER_ORDER_TYPE_VIRTUAL_COINS, $base_page_url)); ?>"
                   class="<?php echo $current_type === STARTER_ORDER_TYPE_VIRTUAL_COINS ? 'current' : ''; ?>">
                    &#x1FA99; Paquetes FC <span class="count">(<?php echo esc_html($counts['virtual_coins']); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('order_type', STARTER_SPECIAL_ORDERS_VIEW_PENDING, $base_page_url)); ?>"
                   class="<?php echo $is_pending_view ? 'current' : ''; ?>">
                    &#x23F3; Pendientes <span class="count">(<?php echo esc_html($pending_count); ?>)</span>
                </a>
            </li>
        </ul>
        
        <!-- Formulario de búsqueda -->
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="search-box" style="float: right; margin-top: 6px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(STARTER_SPECIAL_ORDERS_PAGE_SLUG); ?>">
            <input type="hidden" name="order_type" value="<?php echo esc_attr($current_type); ?>">
            <?php if ($current_status !== 'all') : ?>
                <input type="hidden" name="order_status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            <label class="screen-reader-text" for="search-special-orders">Buscar pedidos:</label>
            <input type="search" id="search-special-orders" name="s" 
                   value="<?php echo esc_attr($search_term); ?>" 
                   placeholder="# orden, referencia Wompi, email o nombre...">
            <input type="submit" id="search-submit" class="button" value="Buscar">
        </form>
        
        <!-- Filtro de estado + paginación superior -->
        <div class="tablenav top" style="clear: both;">
            <div class="alignleft actions">
                <?php starter_so_render_status_filter($is_pending_view ? 'pending_only' : $current_status, $is_pending_view); ?>
            </div>
            
            <?php if ($total_pages > 1) : ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_items); ?> elementos</span>
                <?php echo starter_so_pagination($paged, $total_pages); ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Tabla de pedidos -->
        <?php starter_so_render_purchases_table($rows, $is_pending_view); ?>
        
        <!-- Paginación inferior -->
        <?php if ($total_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_items); ?> elementos</span>
                <?php echo starter_so_pagination($paged, $total_pages); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resumen de totales -->
        <?php if (!$is_pending_view) : ?>
            <?php starter_so_render_summary($current_type); ?>
        <?php endif; ?>
    </div>
    
    <?php starter_so_print_styles(); ?>
    <?php
}

/**
 * Renderizar el dropdown de filtro por estado
 * 
 * @param string $current_status Estado seleccionado actualmente
 */
function starter_so_render_status_filter($current_status, $is_pending_view = false) {
    if ($is_pending_view) {
        $statuses = [
            'pending_only'     => 'Todos los pendientes',
            'pending'          => 'Pendiente',
            'processing'       => 'Procesando',
            'amount_mismatch'  => 'Monto no coincide',
            'error_activation' => 'Error activación',
        ];
    } else {
        $statuses = [
            'all'        => 'Todos los estados',
            'completed'  => 'Completado',
            'pending'    => 'Pendiente',
            'processing' => 'Procesando',
        ];
    }
    
    ?>
    <select name="order_status" id="filter-by-status" onchange="
        var url = new URL(window.location.href);
        url.searchParams.set('order_status', this.value);
        url.searchParams.delete('paged');
        window.location.href = url.toString();
    ">
        <?php foreach ($statuses as $value => $label) : ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($current_status, $value); ?>>
                <?php echo esc_html($label); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

