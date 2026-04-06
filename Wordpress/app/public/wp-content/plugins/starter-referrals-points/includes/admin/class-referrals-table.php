<?php
/**
 * Tabla de red de referidos para el panel de administración
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
 * Clase para la tabla de referidos
 */
class Starter_RP_Referrals_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Referido', 'starter-rp'),
            'plural'   => __('Referidos', 'starter-rp'),
            'ajax'     => false
        ]);
    }
    
    /**
     * Obtener columnas
     */
    public function get_columns() {
        $columns = [
            'user'              => __('Usuario', 'starter-rp'),
            'referral_code'     => __('Código de Referido', 'starter-rp'),
            'referrer'          => __('Referido por', 'starter-rp'),
            'registration_date' => __('Fecha de Registro', 'starter-rp'),
            'total_orders'      => __('Total de Pedidos', 'starter-rp'),
            'total_spent'       => __('Total Gastado', 'starter-rp'),
            'points_balance'    => __('Saldo de Puntos', 'starter-rp'),
            'referrals_count'   => __('Referidos', 'starter-rp')
        ];
        
        return $columns;
    }
    
    /**
     * Columnas que se pueden ordenar
     */
    public function get_sortable_columns() {
        $sortable_columns = [
            'registration_date' => ['registered', false],
            'referral_code'     => ['referral_code', false],
            'points_balance'    => ['points_balance', false],
            'referrals_count'   => ['referrals_count', false]
        ];
        
        return $sortable_columns;
    }
    
    /**
     * Procesamiento por defecto para las columnas
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'referral_code':
                return $item->referral_code;
            case 'registration_date':
                return isset($item->signup_date) ? date_i18n(get_option('date_format'), strtotime($item->signup_date)) : __('No disponible', 'starter-rp');
            case 'total_orders':
                return $this->get_user_orders_count($item->ID);
            case 'total_spent':
                return wc_price($this->get_user_total_spent($item->ID));
            case 'points_balance':
                $balance = isset($item->points_balance) ? (int) $item->points_balance : $this->get_user_points_balance($item->ID);
                $formatted = number_format($balance);
                if ($balance < 0) {
                    return '<span style="color:#dc3232;font-weight:bold;">' . $formatted . '</span>';
                }
                return $formatted;
            case 'referrals_count':
                $count = $this->get_user_referrals_count($item->ID);
                if ($count > 0) {
                    return '<a href="' . admin_url('admin.php?page=starter-rp-network&referrer=' . $item->ID) . '">' . $count . '</a>';
                }
                return '0';
            default:
                return isset($item->$column_name) ? $item->$column_name : '';
        }
    }
    
    /**
     * Columna de usuario
     */
    public function column_user($item) {
        $user_edit_link = get_edit_user_link($item->ID);
        $user_name = $item->display_name . ' (' . $item->user_email . ')';
        
        // URL para filtrar transacciones de este usuario
        $filter_url = add_query_arg([
            'page'    => 'starter-rp-transactions',
            'user_id' => $item->ID,
        ], admin_url('admin.php'));
        
        $output  = '<a href="' . esc_url($user_edit_link) . '">' . esc_html($user_name) . '</a>';
        $output .= '<br><a href="' . esc_url($filter_url) . '" style="color:#999;font-size:12px;">' . __('Filtrar por este usuario', 'starter-rp') . '</a>';
        
        return $output;
    }
    
    /**
     * Columna de referidor
     */
    public function column_referrer($item) {
        if (!$item->referrer_id) {
            return __('Ninguno', 'starter-rp');
        }
        
        $referrer = get_user_by('id', $item->referrer_id);
        
        if (!$referrer) {
            return __('Usuario no encontrado', 'starter-rp');
        }
        
        $user_edit_link = get_edit_user_link($item->referrer_id);
        $user_name = $referrer->display_name . ' (' . $referrer->user_email . ')';
        
        // URL para filtrar transacciones de este referidor
        $filter_url = add_query_arg([
            'page'    => 'starter-rp-transactions',
            'user_id' => $item->referrer_id,
        ], admin_url('admin.php'));
        
        $output  = '<a href="' . esc_url($user_edit_link) . '">' . esc_html($user_name) . '</a>';
        $output .= '<br><a href="' . esc_url($filter_url) . '" style="color:#999;font-size:12px;">' . __('Filtrar por este usuario', 'starter-rp') . '</a>';
        
        return $output;
    }
    
    /**
     * No hay elementos
     */
    public function no_items() {
        _e('No se encontraron referidos.', 'starter-rp');
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
        
        $users_table = $wpdb->prefix . 'users';
        $referrals_table = $wpdb->prefix . 'starter_referrals';
        $points_table = $wpdb->prefix . 'starter_user_points';
        
        // Búsqueda
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        
        // Filtros
        $referrer_id = isset($_REQUEST['referrer']) ? intval($_REQUEST['referrer']) : 0;
        
        // Registrar información de depuración
        starter_rp_log('Starter RP - Consultando referidos para referrer_id: ' . $referrer_id);
        
        // Construir la consulta
        $query = "
            SELECT u.*, r.referral_code, r.referrer_id, r.signup_date, COALESCE(p.points, 0) as points_balance,
            (SELECT COUNT(*) FROM $referrals_table WHERE referrer_id = u.ID) as referrals_count
            FROM $users_table u
            LEFT JOIN $referrals_table r ON u.ID = r.user_id
            LEFT JOIN $points_table p ON u.ID = p.user_id
        ";
        
        // Condiciones WHERE
        $where = [];
        $where_args = [];
        
        // Filtro por referidor
        if ($referrer_id > 0) {
            $where[] = "r.referrer_id = %d";
            $where_args[] = $referrer_id;
            
            // Verificar si existen referidos para este referidor
            $check_referrals = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $referrals_table WHERE referrer_id = %d",
                $referrer_id
            ));
            
            starter_rp_log('Starter RP - Número de referidos encontrados en la tabla: ' . $check_referrals);
        }
        
        // Búsqueda
        if (!empty($search)) {
            $where[] = "(u.display_name LIKE %s OR u.user_email LIKE %s OR r.referral_code LIKE %s)";
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_args[] = $search_like;
            $where_args[] = $search_like;
            $where_args[] = $search_like;
        }
        
        // Añadir condiciones a la consulta
        if (!empty($where)) {
            $query .= " WHERE " . implode(' AND ', $where);
        }
        
        // Solo usuarios con código de referido
        if (empty($where)) {
            $query .= " WHERE r.referral_code IS NOT NULL";
        } else {
            $query .= " AND r.referral_code IS NOT NULL";
        }
        
        // Ordenar
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'u.ID';
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
        
        // Registrar la consulta para depuración
        starter_rp_log('Starter RP - Consulta SQL: ' . $prepared_query);
        
        // Obtener datos
        $this->items = $wpdb->get_results($prepared_query);
        
        // Registrar número de resultados
        starter_rp_log('Starter RP - Número de resultados: ' . count($this->items));
        
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
     * Obtener número de pedidos del usuario
     */
    private function get_user_orders_count($user_id) {
        return wc_get_customer_order_count($user_id);
    }
    
    /**
     * Obtener total gastado por el usuario
     */
    private function get_user_total_spent($user_id) {
        return wc_get_customer_total_spent($user_id);
    }
    
    /**
     * Obtener saldo de puntos del usuario
     */
    private function get_user_points_balance($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'starter_user_points';
        
        $points = $wpdb->get_var($wpdb->prepare("
            SELECT points FROM $table WHERE user_id = %d
        ", $user_id));
        
        return $points !== null ? (int) $points : 0;
    }
    
    /**
     * Obtener cantidad de referidos del usuario
     */
    private function get_user_referrals_count($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'starter_referrals';
        
        return $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $table WHERE referrer_id = %d
        ", $user_id));
    }
    
    /**
     * Filtros adicionales
     */
    public function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }
        
        // Mostrar información del referidor seleccionado
        $referrer_id = isset($_REQUEST['referrer']) ? intval($_REQUEST['referrer']) : 0;
        
        if ($referrer_id > 0) {
            $referrer = get_user_by('id', $referrer_id);
            
            if ($referrer) {
                echo '<div class="alignleft actions">';
                echo '<strong>' . __('Mostrando referidos de:', 'starter-rp') . '</strong> ';
                echo esc_html($referrer->display_name) . ' (' . esc_html($referrer->user_email) . ')';
                echo ' <a href="' . admin_url('admin.php?page=starter-rp-network') . '" class="button button-secondary">';
                echo __('Mostrar todos', 'starter-rp');
                echo '</a>';
                echo '</div>';
            }
        }
    }
}
