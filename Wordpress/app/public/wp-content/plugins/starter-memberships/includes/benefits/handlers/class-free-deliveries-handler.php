<?php
/**
 * Handler: Entregas Gratis
 * 
 * Gestiona la cantidad de entregas gratis disponibles para el usuario
 * según su nivel de membresía.
 * 
 * IMPORTANTE: Las entregas gratis están asociadas a la MEMBRESÍA, no al usuario.
 * Esto significa que:
 * - Cuando la membresía expira, las entregas usadas "mueren" con ella
 * - Cuando se activa una nueva membresía, el contador empieza en 0
 * - No se editan desde el perfil de usuario, están ligadas a la membresía
 * 
 * Configuración esperada:
 * - quantity: Cantidad de entregas gratis por período de membresía
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 * @updated 2.0.0 - Entregas asociadas a membresía en lugar de usuario
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Free_Deliveries_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Columna en tabla de membresías para tracking de entregas usadas
     * Se almacena en wp_starter_user_memberships.free_deliveries_used
     */
    const DB_COLUMN_DELIVERIES_USED = 'free_deliveries_used';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'free_deliveries';
        $this->name = 'Entregas Gratis';
        $this->description = 'Cantidad de entregas gratis durante el período de membresía';
        $this->requires_wc = true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // Hook para verificar si el envío gratis está disponible
        add_filter('woocommerce_shipping_free_shipping_is_available', [$this, 'check_free_shipping_available'], 10, 3);
        
        // Hook SOLO cuando el pedido se COMPLETA para decrementar contador
        // NO en processing - las entregas solo se descuentan al completar
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);
        
        // Asegurar que la columna existe en la tabla de membresías
        add_action('init', [$this, 'ensure_db_column_exists'], 5);
    }
    
    /**
     * Asegurar que las columnas de free_deliveries existen en la tabla de membresías
     * Se ejecuta una sola vez y guarda flag en options
     */
    public function ensure_db_column_exists(): void {
        // Versión actual de las columnas
        $current_version = '2';
        
        // Limpiar opción antigua si existe
        if (get_option('starter_free_deliveries_column_added')) {
            delete_option('starter_free_deliveries_column_added');
        }
        
        $saved_version = get_option('starter_free_deliveries_columns_version', '0');
        
        if ($saved_version === $current_version) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'starter_user_memberships';
        
        // Columna: free_deliveries_used
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $table LIKE 'free_deliveries_used'"
        );
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE $table ADD COLUMN free_deliveries_used int(11) NOT NULL DEFAULT 0 AFTER points_awarded"
            );
            $this->log('Columna free_deliveries_used agregada');
        }
        
        // Columna: free_deliveries_last_used_at
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $table LIKE 'free_deliveries_last_used_at'"
        );
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE $table ADD COLUMN free_deliveries_last_used_at datetime DEFAULT NULL AFTER free_deliveries_used"
            );
            $this->log('Columna free_deliveries_last_used_at agregada');
        }
        
        // Columna: free_deliveries_last_order_id
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $table LIKE 'free_deliveries_last_order_id'"
        );
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE $table ADD COLUMN free_deliveries_last_order_id bigint(20) unsigned DEFAULT NULL AFTER free_deliveries_last_used_at"
            );
            $this->log('Columna free_deliveries_last_order_id agregada');
        }
        
        // Marcar versión como completada
        update_option('starter_free_deliveries_columns_version', $current_version);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return null;
        }
        
        $total = intval($config['quantity'] ?? 0);
        $membership = $this->get_user_active_membership($user_id);
        $used = $membership ? intval($membership->free_deliveries_used ?? 0) : 0;
        $remaining = max(0, $total - $used);
        
        // Contar pedidos pendientes que usan envío gratis por membresía
        $pending_orders_count = $this->count_pending_orders_with_free_delivery($user_id);
        
        // Obtener información del último envío gratis usado
        $last_used_at = null;
        $last_used_order_id = null;
        if ($membership && !empty($membership->free_deliveries_last_used_at)) {
            $last_used_at = $membership->free_deliveries_last_used_at;
            $last_used_order_id = $membership->free_deliveries_last_order_id ?? null;
        } elseif ($used > 0) {
            // Fallback: buscar el último pedido con envío gratis usado
            $last_order = $this->get_last_free_delivery_order($user_id);
            if ($last_order) {
                $last_used_at = $last_order->get_date_completed() 
                    ? $last_order->get_date_completed()->format('Y-m-d H:i:s')
                    : $last_order->get_date_created()->format('Y-m-d H:i:s');
                $last_used_order_id = $last_order->get_id();
            }
        }
        
        return [
            'total_allowed' => $total,
            'used' => $used,
            'remaining' => $remaining,
            'can_use' => $remaining > 0,
            'membership_id' => $membership ? $membership->id : null,
            'pending_orders_count' => $pending_orders_count,
            'last_used_at' => $last_used_at,
            'last_used_order_id' => $last_used_order_id
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return '';
        }
        
        $total = intval($config['quantity'] ?? 0);
        
        if ($total <= 0) {
            return '';
        }
        
        $membership = $this->get_user_active_membership($user_id);
        $used = $membership ? intval($membership->free_deliveries_used ?? 0) : 0;
        $remaining = max(0, $total - $used);
        
        return sprintf(
            _n('%d entrega gratis', '%d entregas gratis', $total, 'starter-memberships'),
            $total
        ) . sprintf(' (%d disponibles)', $remaining);
    }
    
    /**
     * Verificar si el envío gratis está disponible por membresía
     * 
     * @param bool $is_available
     * @param array $package
     * @param WC_Shipping_Free_Shipping $shipping_method
     * @return bool
     */
    public function check_free_shipping_available(bool $is_available, array $package, $shipping_method): bool {
        // Si ya está disponible por otra razón, mantenerlo
        if ($is_available) {
            return true;
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return $is_available;
        }
        
        // Verificar si tiene entregas gratis disponibles
        $status = $this->apply($user_id, []);
        
        if ($status && $status['can_use']) {
            $this->log("Envío gratis disponible por membresía", [
                'user_id' => $user_id,
                'remaining' => $status['remaining']
            ]);
            return true;
        }
        
        return $is_available;
    }
    
    /**
     * Decrementar contador cuando se completa un pedido con envío gratis por membresía
     * 
     * IMPORTANTE: Solo se descuenta si el frontend envió explícitamente
     * el meta_data '_use_free_delivery_membership' = 'yes'
     * Esto evita descontar entregas por otros tipos de envío gratis (promociones, monto mínimo, etc.)
     * 
     * @param int $order_id
     */
    public function on_order_completed(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }
        
        // Verificar si ya se procesó este pedido (usar $order->get_meta para compatibilidad HPOS)
        $processed = $order->get_meta('_starter_free_delivery_processed');
        if ($processed) {
            return;
        }
        
        // IMPORTANTE: Verificar si el usuario eligió usar entrega gratis por membresía
        // Este meta_data lo envía el frontend cuando el usuario activa la opción
        $use_free_delivery = $order->get_meta('_use_free_delivery_membership');
        
        // Debug log para verificar el valor
        error_log("[FreeDeliveries] Order $order_id - _use_free_delivery_membership = " . var_export($use_free_delivery, true));
        
        if ($use_free_delivery !== 'yes') {
            // No se eligió usar entrega gratis por membresía, no descontar
            return;
        }
        
        // Verificar si el usuario tiene el beneficio activo
        if (!$this->is_enabled_for_user($user_id)) {
            $this->log("Usuario sin beneficio de entregas gratis intentó usar una", [
                'user_id' => $user_id,
                'order_id' => $order_id
            ]);
            return;
        }
        
        // Verificar si tenía entregas disponibles
        $status = $this->apply($user_id, []);
        if (!$status || !$status['can_use']) {
            $this->log("Usuario sin entregas disponibles intentó usar una", [
                'user_id' => $user_id,
                'order_id' => $order_id,
                'status' => $status
            ]);
            return;
        }
        
        // Incrementar contador de entregas usadas en la membresía
        $membership_id = $status['membership_id'];
        if (!$membership_id) {
            $this->log("No se encontró membresía activa para descontar entrega", [
                'user_id' => $user_id,
                'order_id' => $order_id
            ]);
            return;
        }
        
        $this->increment_deliveries_used($membership_id);
        
        // Guardar información del último envío gratis usado en la membresía
        $this->save_last_used_info($membership_id, $order_id);
        
        // Marcar pedido como procesado (usar $order->update_meta_data para compatibilidad HPOS)
        $order->update_meta_data('_starter_free_delivery_processed', 'yes');
        $order->update_meta_data('_starter_free_delivery_used', 'yes');
        $order->update_meta_data('_starter_free_delivery_membership_id', $membership_id);
        $order->save();
        
        // Agregar nota al pedido para trazabilidad
        $order->add_order_note(sprintf(
            '📦 Envío gratis por membresía usado. Restantes: %d de %d',
            $status['remaining'] - 1,
            $status['total_allowed']
        ));
        
        $this->log("Entrega gratis por membresía usada", [
            'user_id' => $user_id,
            'order_id' => $order_id,
            'membership_id' => $membership_id,
            'remaining' => $status['remaining'] - 1,
            'total_allowed' => $status['total_allowed']
        ]);
    }
    
    /**
     * Obtener la membresía activa del usuario
     * 
     * @param int $user_id
     * @return object|null
     */
    private function get_user_active_membership(int $user_id): ?object {
        if (!function_exists('starter_memberships_get_user_membership')) {
            return null;
        }
        return starter_memberships_get_user_membership($user_id);
    }
    
    /**
     * Incrementar contador de entregas usadas en la membresía
     * Usa UPDATE atómico para evitar race conditions
     * 
     * @param int $membership_id
     */
    private function increment_deliveries_used(int $membership_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'starter_user_memberships';
        
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET free_deliveries_used = free_deliveries_used + 1 WHERE id = %d",
            $membership_id
        ));
    }
    
    /**
     * Guardar información del último envío gratis usado
     * 
     * @param int $membership_id
     * @param int $order_id
     */
    private function save_last_used_info(int $membership_id, int $order_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'starter_user_memberships';
        
        $wpdb->update(
            $table,
            [
                'free_deliveries_last_used_at' => current_time('mysql'),
                'free_deliveries_last_order_id' => $order_id
            ],
            ['id' => $membership_id],
            ['%s', '%d'],
            ['%d']
        );
    }
    
    /**
     * Obtener el último pedido completado que usó envío gratis por membresía
     * 
     * @param int $user_id
     * @return \WC_Order|null
     */
    private function get_last_free_delivery_order(int $user_id): ?\WC_Order {
        if (!function_exists('wc_get_orders')) {
            return null;
        }
        
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => 'completed',
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_starter_free_delivery_used',
            'meta_value' => 'yes'
        ]);
        
        return !empty($orders) ? $orders[0] : null;
    }
    
    /**
     * Contar pedidos pendientes de completar que usan envío gratis por membresía
     * (pending, processing, on-hold)
     * 
     * @param int $user_id
     * @return int
     */
    private function count_pending_orders_with_free_delivery(int $user_id): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        
        $pending_statuses = ['pending', 'processing', 'on-hold'];
        
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => $pending_statuses,
            'limit' => -1,
        ]);
        
        $count = 0;
        foreach ($orders as $order) {
            // Solo contar si el pedido usa envío gratis por membresía
            $use_free_delivery = $order->get_meta('_use_free_delivery_membership');
            if ($use_free_delivery === 'yes') {
                // Verificar que no haya sido ya procesado (descontado)
                $processed = $order->get_meta('_starter_free_delivery_processed');
                if (!$processed) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Free_Deliveries_Handler());
