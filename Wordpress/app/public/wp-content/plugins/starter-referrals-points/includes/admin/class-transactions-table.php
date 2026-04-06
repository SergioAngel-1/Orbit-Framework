<?php
/**
 * Tabla de transacciones para el panel de administración
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Verificar que la clase padre existe
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Clase para la tabla de transacciones
 */
class Starter_RP_Transactions_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Transacción', 'starter-rp'),
            'plural'   => __('Transacciones', 'starter-rp'),
            'ajax'     => false
        ]);
    }
    
    /**
     * Obtener columnas
     */
    public function get_columns() {
        $columns = [
            'id'          => __('ID', 'starter-rp'),
            'user'        => __('Usuario', 'starter-rp'),
            'date'        => __('Fecha', 'starter-rp'),
            'type'        => __('Tipo', 'starter-rp'),
            'points'      => __('Puntos', 'starter-rp'),
            'description' => __('Descripción', 'starter-rp'),
            'expiration'  => __('Expiración', 'starter-rp')
        ];
        
        return $columns;
    }
    
    /**
     * Columnas que se pueden ordenar
     */
    public function get_sortable_columns() {
        $sortable_columns = [
            'id'          => ['id', true],
            'date'        => ['created_at', false],
            'user'        => ['user_id', false],
            'type'        => ['type', false],
            'points'      => ['points', false],
            'expiration'  => ['expiration_date', false]
        ];
        
        return $sortable_columns;
    }
    
    /**
     * Procesamiento por defecto para las columnas
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item->id;
            case 'date':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at));
            case 'type':
                $label = $this->get_transaction_type_label($item->type);
                $order_id = $this->extract_order_id($item);
                if ($order_id) {
                    $order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    $label .= '<br><a href="' . esc_url($order_url) . '" style="color:#999;font-size:12px;">Pedido #' . $order_id . '</a>';
                }
                return $label;
            case 'points':
                return '<span style="color:' . ($item->points >= 0 ? 'green' : 'red') . '">' . $item->points . '</span>';
            case 'description':
                return $item->description;
            case 'expiration':
                return isset($item->expiration_date) && $item->expiration_date ? date_i18n(get_option('date_format'), strtotime($item->expiration_date)) : __('No expira', 'starter-rp');
            default:
                return isset($item->$column_name) ? $item->$column_name : '';
        }
    }
    
    /**
     * Columna de usuario
     */
    public function column_user($item) {
        $user = get_user_by('id', $item->user_id);
        
        if (!$user) {
            return __('Usuario no encontrado', 'starter-rp');
        }
        
        $user_edit_link = get_edit_user_link($item->user_id);
        $user_name = $user->display_name . ' (' . $user->user_email . ')';
        
        // Construir URL de filtrado por este usuario
        $filter_url = add_query_arg([
            'page'    => 'starter-rp-transactions',
            'user_id' => $item->user_id,
        ], admin_url('admin.php'));
        
        $output  = '<a href="' . esc_url($user_edit_link) . '">' . esc_html($user_name) . '</a>';
        $output .= '<br><a href="' . esc_url($filter_url) . '" style="color:#999;font-size:12px;">' . __('Filtrar por este usuario', 'starter-rp') . '</a>';
        
        return $output;
    }
    
    /**
     * Extraer order_id de una transacción
     * Busca en la descripción y en reference_id según el tipo
     */
    private function extract_order_id($item) {
        // 1. Buscar "Pedido #N" o "pedido #N" en la descripción
        if (preg_match('/[Pp]edido\s*#(\d+)/', $item->description, $m)) {
            return (int) $m[1];
        }
        
        // 2. Para tipos 'used' y 'refund', reference_id es el order_id
        if (in_array($item->type, ['used', 'refund'], true) && !empty($item->reference_id)) {
            return (int) $item->reference_id;
        }
        
        // 3. Buscar "pedido" seguido de un número en cualquier formato
        if (preg_match('/pedido[^\d]*(\d+)/i', $item->description, $m)) {
            return (int) $m[1];
        }
        
        return 0;
    }
    
    /**
     * Obtener etiqueta legible para el tipo de transacción
     */
    private function get_transaction_type_label($type) {
        $labels = [
            'used'                       => __('Uso de FC en pedidos', 'starter-rp'),
            'refund'                     => __('Reembolso por cancelación', 'starter-rp'),
            'registration'               => __('Puntos por registro', 'starter-rp'),
            'review'                     => __('Puntos por reseña', 'starter-rp'),
            'birthday'                   => __('Puntos por cumpleaños', 'starter-rp'),
            'referral_purchase'          => __('Comisión por compra de referido', 'starter-rp'),
            'referral_commission'        => __('Comisión de referido (nivel 1)', 'starter-rp'),
            'referral_commission_level2' => __('Comisión de referido (nivel 2)', 'starter-rp'),
            'referral_signup'            => __('Puntos por nuevo referido', 'starter-rp'),
            'referral_signup_level1'     => __('Puntos por referido directo', 'starter-rp'),
            'referral_signup_level2'     => __('Puntos por referido indirecto', 'starter-rp'),
            'referral_product_bonus'     => __('Bonificación extra por producto', 'starter-rp'),
            'received'                   => __('FC recibidos por transferencia', 'starter-rp'),
            'transfer'                   => __('FC enviados por transferencia', 'starter-rp'),
            'membership_monthly'         => __('FC por membresía (mensual)', 'starter-rp'),
            'membership_activation'      => __('FC por activación de membresía', 'starter-rp'),
            'admin_add'                  => __('Añadido por admin', 'starter-rp'),
            'admin_deduct'               => __('Deducido por admin', 'starter-rp'),
            'expired'                    => __('Expirado', 'starter-rp'),
        ];
        
        return isset($labels[$type]) ? $labels[$type] : ucfirst($type);
    }
    
    /**
     * No hay elementos
     */
    public function no_items() {
        _e('No se encontraron transacciones.', 'starter-rp');
    }
    
    /**
     * Preparar elementos
     */
    public function prepare_items() {
        global $wpdb;
        
        $per_page = 20;
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = [$columns, $hidden, $sortable];
        
        $table = $wpdb->prefix . 'starter_points_transactions';
        $users_table = $wpdb->prefix . 'users';
        
        // Búsqueda
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        
        // Filtros
        $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
        $type = isset($_REQUEST['type']) ? sanitize_text_field($_REQUEST['type']) : '';
        
        // Construir la consulta
        $query = "SELECT t.* FROM $table t";
        
        // Join con usuarios para búsqueda
        if (!empty($search)) {
            $query .= " LEFT JOIN $users_table u ON t.user_id = u.ID";
        }
        
        // Condiciones WHERE
        $where = [];
        $where_args = [];
        
        // Filtro por usuario
        if ($user_id > 0) {
            $where[] = "t.user_id = %d";
            $where_args[] = $user_id;
        }
        
        // Filtro por tipo
        if (!empty($type)) {
            $where[] = "t.type = %s";
            $where_args[] = $type;
        }
        
        // Filtro: excluir uso de FC en pedidos
        $hide_order_usage = isset($_REQUEST['hide_order_usage']) ? intval($_REQUEST['hide_order_usage']) : 0;
        if ($hide_order_usage && empty($type)) {
            $where[] = "t.type != 'used'";
        }
        
        // Búsqueda
        if (!empty($search)) {
            $where[] = "(t.description LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)";
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_args[] = $search_like;
            $where_args[] = $search_like;
            $where_args[] = $search_like;
        }
        
        // Añadir condiciones a la consulta
        if (!empty($where)) {
            $query .= " WHERE " . implode(' AND ', $where);
        }
        
        // Ordenar
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'id';
        $order = !empty($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC';
        
        if (!empty($orderby) && !empty($order)) {
            $query .= " ORDER BY $orderby $order";
        }
        
        // Paginación
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var($this->prepare_query("SELECT COUNT(*) FROM ($query) as t", $where_args));
        
        $query .= " LIMIT %d OFFSET %d";
        $where_args[] = $per_page;
        $where_args[] = ($current_page - 1) * $per_page;
        
        // Preparar query final
        $prepared_query = $this->prepare_query($query, $where_args);
        
        // Obtener datos
        $this->items = $wpdb->get_results($prepared_query);
        
        // Configurar paginación
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
    
    /**
     * Preparar consulta con argumentos
     */
    private function prepare_query($query, $args = []) {
        global $wpdb;
        
        if (empty($args)) {
            return $query;
        }
        
        return $wpdb->prepare($query, $args);
    }
    
    /**
     * Filtros adicionales
     */
    public function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }
        
        global $wpdb;
        
        // Obtener usuarios que tienen transacciones (solo los relevantes)
        $transactions_table = $wpdb->prefix . 'starter_points_transactions';
        $users_with_transactions = $wpdb->get_results("
            SELECT DISTINCT u.ID, u.display_name, u.user_email
            FROM {$wpdb->users} u
            INNER JOIN $transactions_table t ON u.ID = t.user_id
            ORDER BY u.display_name ASC
        ");
        
        $selected_user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
        
        // Filtro de tipo de transacción
        $types = [
            'used'                      => __('Uso de FC en pedidos', 'starter-rp'),
            'refund'                    => __('Reembolso por cancelación', 'starter-rp'),
            'registration'              => __('Puntos por registro', 'starter-rp'),
            'review'                    => __('Puntos por reseña', 'starter-rp'),
            'birthday'                  => __('Puntos por cumpleaños', 'starter-rp'),
            'referral_purchase'         => __('Comisión por compra de referido', 'starter-rp'),
            'referral_commission'       => __('Comisión de referido (nivel 1)', 'starter-rp'),
            'referral_commission_level2'=> __('Comisión de referido (nivel 2)', 'starter-rp'),
            'referral_signup'           => __('Puntos por nuevo referido', 'starter-rp'),
            'referral_signup_level1'    => __('Puntos por referido directo', 'starter-rp'),
            'referral_signup_level2'    => __('Puntos por referido indirecto', 'starter-rp'),
            'referral_product_bonus'    => __('Bonificación extra por producto', 'starter-rp'),
            'received'                  => __('FC recibidos por transferencia', 'starter-rp'),
            'transfer'                  => __('FC enviados por transferencia', 'starter-rp'),
            'membership_monthly'        => __('FC por membresía (mensual)', 'starter-rp'),
            'membership_activation'     => __('FC por activación de membresía', 'starter-rp'),
            'admin_add'                 => __('Añadido por admin', 'starter-rp'),
            'admin_deduct'              => __('Deducido por admin', 'starter-rp'),
            'expired'                   => __('Expirado', 'starter-rp'),
        ];
        
        $selected_type = isset($_REQUEST['type']) ? sanitize_text_field($_REQUEST['type']) : '';
        $hide_order_usage = isset($_REQUEST['hide_order_usage']) ? intval($_REQUEST['hide_order_usage']) : 0;
        ?>
        <div class="alignleft actions">
            <select name="user_id" id="starter-rp-user-filter">
                <option value="0"><?php _e('Todos los usuarios', 'starter-rp'); ?></option>
                <?php foreach ($users_with_transactions as $u) : ?>
                    <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($selected_user_id, $u->ID); ?>>
                        <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type">
                <option value=""><?php _e('Todos los tipos', 'starter-rp'); ?></option>
                <?php foreach ($types as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_type, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label style="margin-left:6px;vertical-align:middle;">
                <input type="checkbox" name="hide_order_usage" value="1" <?php checked($hide_order_usage, 1); ?> />
                <?php _e('Excluir uso de FC en pedidos', 'starter-rp'); ?>
            </label>
            <?php submit_button(__('Filtrar', 'starter-rp'), '', 'filter_action', false); ?>
        </div>
        <?php
    }
}
