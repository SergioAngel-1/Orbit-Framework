<?php
/**
 * Handler: Muestras Gratis (v2.0 - Refactorizado)
 * 
 * Gestiona muestras gratis para usuarios con membresía.
 * 
 * LÓGICA SIMPLIFICADA:
 * - orders_in_period: Contador acumulativo de pedidos completados en el período de membresía
 * - deliveries_earned: Calculado como floor(orders_in_period / every_orders)
 * - grams_delivered: deliveries_earned * grams_per_delivery (máximo total_grams)
 * - orders_in_current_cycle: orders_in_period % every_orders
 * - orders_until_next: every_orders - orders_in_current_cycle
 * 
 * El contador NUNCA se resetea a 0 (excepto al activar nueva membresía).
 * Esto simplifica la lógica y evita inconsistencias.
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Free_Samples_Handler extends Starter_Benefit_Handler_Base {
    
    // =========================================================================
    // CONSTANTES
    // =========================================================================
    
    /** Contador acumulativo de pedidos completados en el período */
    const META_ORDERS_IN_PERIOD = '_starter_free_samples_orders_in_period';
    /** Fecha de inicio del período actual de membresía */
    const META_PERIOD_START = '_starter_free_samples_period_start';
    /** Información de la última entrega de muestra */
    const META_LAST_DELIVERY = '_starter_free_samples_last_delivery';
    /** Número de entregas realizadas (para validación) */
    const META_DELIVERIES_COUNT = '_starter_free_samples_deliveries_count';
    
    // Legacy keys para migración
    const LEGACY_META_ORDERS_COUNT = '_starter_free_samples_orders_count';
    const LEGACY_META_GRAMS_CLAIMED = '_starter_free_samples_claimed';
    const LEGACY_META_CYCLE_START = '_starter_free_samples_cycle_start';
    
    // =========================================================================
    // CONSTRUCTOR Y CONFIGURACIÓN BASE
    // =========================================================================
    
    public function __construct() {
        $this->key = 'free_samples';
        $this->name = 'Muestras Gratis';
        $this->description = 'Gramos gratis cada X pedidos';
        $this->requires_wc = true;
    }
    
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    // =========================================================================
    // REGISTRO DE HOOKS
    // =========================================================================
    
    public function register_hooks(): void {
        // Reset al activar membresía
        add_action('starter_membership_activated', [$this, 'on_membership_activated'], 10, 2);
        
        // Procesar pedido completado
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 5, 1);
        
        // Hook alternativo por si el anterior no se dispara
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 5, 4);
        
        // Nota informativa al pasar a processing
        add_action('woocommerce_order_status_processing', [$this, 'on_order_processing'], 10, 1);
        
        // También procesar cuando el pedido se crea (para pedidos pending por transferencia)
        add_action('woocommerce_order_status_pending', [$this, 'on_order_processing'], 10, 1);
        add_action('woocommerce_order_status_on-hold', [$this, 'on_order_processing'], 10, 1);
        
        // Hook al crear el pedido (checkout)
        add_action('woocommerce_checkout_order_created', [$this, 'on_order_created'], 10, 1);
    }
    
    /**
     * Hook cuando se crea el pedido en checkout
     */
    public function on_order_created($order): void {
        if (!$order) return;
        $this->on_order_processing($order->get_id());
    }
    
    /**
     * Hook alternativo cuando cambia el estado del pedido
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order): void {
        if ($new_status !== 'completed') {
            return;
        }
        
        if ($order->get_meta('_starter_fs_processed') === 'yes') {
            return;
        }
        
        $this->on_order_completed($order_id);
    }
    
    // =========================================================================
    // MÉTODO PRINCIPAL: APPLY (Datos para el frontend)
    // =========================================================================
    
    /**
     * Retorna los datos de muestras gratis para el frontend
     * 
     * LÓGICA v3.0 - Ciclo de N+1 pedidos:
     * - Para every_orders = 2, el ciclo real es de 3 pedidos:
     *   - Pedido 1: progreso 1/2
     *   - Pedido 2: progreso 2/2 (ciclo completo)
     *   - Pedido 3: GANA MUESTRA, reinicia a 0/2 (este pedido NO cuenta para el siguiente ciclo)
     * 
     * - orders_in_period: Total acumulativo de pedidos completados
     * - deliveries_earned: Se obtiene del contador de entregas (META_DELIVERIES_COUNT)
     * - orders_in_current_cycle: Pedidos desde la última entrega (0 a every_orders)
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_validated_config($user_id);
        if (!$config) {
            return null;
        }

        // Migrar datos legacy si es necesario
        $this->migrate_legacy_data($user_id);
        
        // Asegurar que existan los meta datos
        $this->ensure_user_meta_defaults($user_id);

        // Obtener contadores
        $orders_in_period = $this->get_orders_in_period($user_id);
        $deliveries_earned = $this->get_deliveries_count($user_id);
        
        // Calcular máximo de entregas y gramos
        $max_deliveries = floor($config['total_grams'] / $config['grams_per_delivery']);
        $deliveries_earned = min($deliveries_earned, $max_deliveries);
        $grams_delivered = $deliveries_earned * $config['grams_per_delivery'];
        $grams_remaining = max(0, $config['total_grams'] - $grams_delivered);
        $can_receive_more = $grams_remaining >= $config['grams_per_delivery'];
        
        // Calcular progreso en el ciclo actual
        // El ciclo real es de (every_orders + 1) pedidos, donde el último gana muestra
        // Pero visualmente mostramos progreso de 0 a every_orders
        // orders_in_current_cycle = pedidos desde la última entrega, máximo every_orders
        $cycle_length = $config['every_orders'] + 1; // Ciclo real incluye el pedido que gana
        $orders_since_last_delivery = $orders_in_period - ($deliveries_earned * $cycle_length);
        $orders_in_current_cycle = max(0, min($orders_since_last_delivery, $config['every_orders']));
        
        // Calcular pedidos hasta la próxima muestra
        // Si el ciclo está completo (every_orders), el próximo pedido gana muestra
        $orders_until_next = null;
        if ($can_receive_more) {
            if ($orders_in_current_cycle >= $config['every_orders']) {
                $orders_until_next = 1; // El próximo pedido gana muestra
            } else {
                $orders_until_next = $config['every_orders'] - $orders_in_current_cycle + 1; // +1 porque el pedido que completa también cuenta
            }
        }
        
        // Obtener información de última entrega
        $last_delivery_meta = get_user_meta($user_id, self::META_LAST_DELIVERY, true);
        $last_delivery_at = null;
        $last_delivery_grams = null;
        $last_delivery_order_id = null;
        if (is_array($last_delivery_meta)) {
            $last_delivery_at = $last_delivery_meta['date'] ?? null;
            $last_delivery_grams = isset($last_delivery_meta['grams']) ? floatval($last_delivery_meta['grams']) : null;
            $last_delivery_order_id = $last_delivery_meta['order_id'] ?? null;
        }
        
        // Verificar si acaba de recibir muestra (última hora)
        $last_timestamp = $last_delivery_at ? strtotime($last_delivery_at) : false;
        $just_delivered = $last_timestamp ? (current_time('timestamp') - $last_timestamp) < HOUR_IN_SECONDS : false;
        
        // Fecha de inicio del período
        $period_start = get_user_meta($user_id, self::META_PERIOD_START, true) ?: null;
        
        // Contar pedidos pendientes de completar
        $pending_orders_count = $this->count_pending_orders($user_id);
        
        return [
            // Configuración del beneficio
            'total_grams' => $config['total_grams'],
            'grams_per_delivery' => $config['grams_per_delivery'],
            'every_orders' => $config['every_orders'],
            
            // Estado actual
            'orders_in_period' => $orders_in_period,
            'orders_in_current_cycle' => $orders_in_current_cycle,
            'orders_until_next' => $orders_until_next,
            'deliveries_earned' => $deliveries_earned,
            'grams_delivered' => $grams_delivered,
            'grams_remaining' => $grams_remaining,
            'can_receive_more' => $can_receive_more,
            
            // Información de última entrega
            'last_delivery_at' => $last_delivery_at,
            'last_delivery_grams' => $last_delivery_grams,
            'last_delivery_order_id' => $last_delivery_order_id,
            'just_delivered' => $just_delivered,
            
            // Información del período
            'period_start' => $period_start,
            
            // Pedidos pendientes
            'pending_orders_count' => $pending_orders_count,
            
            // Campos legacy para compatibilidad con frontend existente
            'orders_count' => $orders_in_period,
            'orders_in_cycle' => $orders_in_current_cycle,
        ];
    }
    
    public function get_display_value(int $user_id): string {
        $status = $this->apply($user_id, []);
        if (!$status) {
            return '';
        }
        
        return sprintf(
            '%sg total (%sg cada %d pedidos) - %sg entregados, %sg restantes',
            $status['total_grams'],
            $status['grams_per_delivery'],
            $status['every_orders'],
            $status['grams_delivered'],
            $status['grams_remaining']
        );
    }
    
    // =========================================================================
    // HANDLERS DE EVENTOS
    // =========================================================================
    
    /**
     * Reset cuando se activa una nueva membresía
     */
    public function on_membership_activated(int $user_id, int $level): void {
        $this->reset_user_data($user_id);
        $this->log("Membresía activada - datos reseteados", [
            'user_id' => $user_id,
            'level' => $level
        ]);
    }
    
    /**
     * Nota informativa cuando el pedido pasa a processing
     * Calcula si este pedido ganará muestra cuando se complete
     * 
     * LÓGICA v3.0 - Ciclo de N+1 pedidos
     */
    public function on_order_processing(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        // Evitar procesar dos veces
        if ($order->get_meta('_starter_fs_processing_note') === 'yes') return;
        
        // Verificar si tiene el beneficio
        if (!$this->is_enabled_for_user($user_id)) return;
        
        $config = $this->get_validated_config($user_id);
        if (!$config) return;
        
        // Migrar datos legacy
        $this->migrate_legacy_data($user_id);
        $this->ensure_user_meta_defaults($user_id);
        
        // Obtener estado actual
        $orders_in_period = $this->get_orders_in_period($user_id);
        $deliveries_earned = $this->get_deliveries_count($user_id);
        $max_deliveries = floor($config['total_grams'] / $config['grams_per_delivery']);
        
        // Verificar si aún puede recibir más muestras
        if ($deliveries_earned >= $max_deliveries) {
            $order->add_order_note('🌿 Muestras gratis: Ya recibió todas las muestras de este período');
            $order->update_meta_data('_starter_fs_processing_note', 'yes');
            $order->update_meta_data('_starter_fs_will_earn', 'no');
            $order->update_meta_data('_starter_fs_reason', 'max_reached');
            $order->save();
            return;
        }
        
        // Calcular si este pedido ganará muestra cuando se complete
        // cycle_length = every_orders + 1 (el pedido N+1 gana muestra)
        $cycle_length = $config['every_orders'] + 1;
        $next_order_number = $orders_in_period + 1;
        $orders_since_last_delivery_after = $next_order_number - ($deliveries_earned * $cycle_length);
        
        // Gana muestra si este pedido es el N+1 del ciclo
        $will_earn = ($orders_since_last_delivery_after == $cycle_length);
        
        // Calcular progreso visual (0 a every_orders)
        $progress_after = min($orders_since_last_delivery_after, $config['every_orders']);
        $orders_until_next = $will_earn ? 0 : ($cycle_length - $orders_since_last_delivery_after);
        
        if ($will_earn) {
            $delivery_number = $deliveries_earned + 1;
            $grams_to_earn = $config['grams_per_delivery'];
            
            $order->add_order_note(sprintf(
                '🌿⚠️ INCLUIR %sg DE MUESTRA GRATIS (Entrega #%d de %d)',
                $grams_to_earn,
                $delivery_number,
                $max_deliveries
            ));
            
            $order->update_meta_data('_starter_fs_will_earn', 'yes');
            $order->update_meta_data('_starter_fs_expected_grams', $grams_to_earn);
            $order->update_meta_data('_starter_fs_delivery_number', $delivery_number);
        } else if ($progress_after >= $config['every_orders']) {
            // Ciclo completo: el próximo pedido ganará muestra
            $order->add_order_note(sprintf(
                '🌿✨ Progreso muestras: Pedido #%d (%d/%d) - ¡CICLO COMPLETO! El próximo pedido ganará %sg de muestra',
                $next_order_number,
                $progress_after,
                $config['every_orders'],
                $config['grams_per_delivery']
            ));
            
            $order->update_meta_data('_starter_fs_will_earn', 'next');
            $order->update_meta_data('_starter_fs_expected_grams', 0);
        } else {
            $order->add_order_note(sprintf(
                '🌿 Progreso muestras: Pedido #%d (%d/%d) - faltan %d para próxima muestra de %sg',
                $next_order_number,
                $progress_after,
                $config['every_orders'],
                $orders_until_next,
                $config['grams_per_delivery']
            ));
            
            $order->update_meta_data('_starter_fs_will_earn', 'no');
            $order->update_meta_data('_starter_fs_expected_grams', 0);
        }
        
        // Guardar snapshot del estado al momento del checkout
        $order->update_meta_data('_starter_fs_orders_snapshot', $next_order_number);
        $order->update_meta_data('_starter_fs_orders_until_next', $orders_until_next);
        $order->update_meta_data('_starter_fs_processing_note', 'yes');
        $order->save();
    }
    
    /**
     * Procesar pedido completado - INCREMENTA CONTADOR Y ENTREGA MUESTRA SI CORRESPONDE
     * 
     * LÓGICA v3.0 - Ciclo de N+1 pedidos:
     * - Para every_orders = 2, necesitas 3 pedidos para ganar muestra:
     *   - Pedido 1: progreso 1/2
     *   - Pedido 2: progreso 2/2 (ciclo completo, próximo gana)
     *   - Pedido 3: GANA MUESTRA, progreso reinicia a 0/2
     * 
     * El pedido que gana muestra NO cuenta para el siguiente ciclo.
     */
    public function on_order_completed(int $order_id): void {
        error_log("[FreeSamples] on_order_completed INICIO - order_id=$order_id");
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("[FreeSamples] ERROR: Pedido no encontrado");
            return;
        }
        
        $user_id = $order->get_user_id();
        if (!$user_id) {
            error_log("[FreeSamples] ERROR: Pedido sin usuario");
            return;
        }
        
        // Evitar procesar dos veces
        if ($order->get_meta('_starter_fs_processed') === 'yes') {
            error_log("[FreeSamples] Ya procesado, saliendo");
            return;
        }
        
        error_log("[FreeSamples] Procesando pedido para user_id=$user_id");
        
        // Verificar si tiene el beneficio
        if (!$this->is_enabled_for_user($user_id)) {
            $this->mark_order_processed($order, 'benefit_disabled');
            return;
        }
        
        $config = $this->get_validated_config($user_id);
        if (!$config) {
            $this->mark_order_processed($order, 'no_config');
            return;
        }
        
        // Migrar datos legacy y asegurar defaults
        $this->migrate_legacy_data($user_id);
        $this->ensure_user_meta_defaults($user_id);
        
        // Obtener estado actual
        $orders_in_period = $this->get_orders_in_period($user_id);
        $deliveries_earned = $this->get_deliveries_count($user_id);
        $max_deliveries = floor($config['total_grams'] / $config['grams_per_delivery']);
        
        // Verificar si aún puede recibir más muestras
        if ($deliveries_earned >= $max_deliveries) {
            $this->mark_order_processed($order, 'max_deliveries_reached');
            return;
        }
        
        // INCREMENTAR CONTADOR DE PEDIDOS
        $new_count = $this->increment_orders_in_period($user_id);
        
        // Calcular progreso en el ciclo actual
        // cycle_length = every_orders + 1 (incluye el pedido que gana muestra)
        $cycle_length = $config['every_orders'] + 1;
        $orders_since_last_delivery = $new_count - ($deliveries_earned * $cycle_length);
        
        // Verificar si ganó muestra: cuando orders_since_last_delivery == cycle_length
        // Es decir, este es el pedido N+1 del ciclo
        $earned_sample = ($orders_since_last_delivery == $cycle_length);
        
        error_log("[FreeSamples] new_count=$new_count, deliveries_earned=$deliveries_earned, cycle_length=$cycle_length, orders_since_last_delivery=$orders_since_last_delivery, earned_sample=" . ($earned_sample ? 'YES' : 'NO'));
        
        if ($earned_sample) {
            $delivery_number = $deliveries_earned + 1;
            $grams_to_deliver = $config['grams_per_delivery'];
            
            // Incrementar contador de entregas
            $this->increment_deliveries_count($user_id);
            
            // Guardar información de la entrega
            update_user_meta($user_id, self::META_LAST_DELIVERY, [
                'order_id' => $order_id,
                'grams' => $grams_to_deliver,
                'delivery_number' => $delivery_number,
                'date' => current_time('mysql')
            ]);
            
            // Calcular gramos restantes
            $grams_delivered_total = $delivery_number * $grams_to_deliver;
            $grams_remaining = $config['total_grams'] - $grams_delivered_total;

            $order->add_order_note(sprintf(
                '🌿✅ MUESTRA GRATIS ENTREGADA: %sg (Entrega #%d de %d) - Restantes: %sg de %sg',
                $grams_to_deliver,
                $delivery_number,
                $max_deliveries,
                max(0, $grams_remaining),
                $config['total_grams']
            ));
            
            $order->update_meta_data('_starter_fs_earned_grams', $grams_to_deliver);
            $order->update_meta_data('_starter_fs_delivery_number', $delivery_number);
            
            $this->log("Muestra entregada", [
                'user_id' => $user_id,
                'order_id' => $order_id,
                'delivery_number' => $delivery_number,
                'grams' => $grams_to_deliver,
                'remaining' => $grams_remaining
            ]);
        } else {
            // Calcular progreso visual (0 a every_orders)
            $progress = min($orders_since_last_delivery, $config['every_orders']);
            $orders_until = $cycle_length - $orders_since_last_delivery;
            
            if ($progress >= $config['every_orders']) {
                // Ciclo completo: el próximo pedido ganará muestra
                $order->add_order_note(sprintf(
                    '🌿✨ Progreso muestras: Pedido #%d (%d/%d) - ¡CICLO COMPLETO! El próximo pedido ganará %sg de muestra',
                    $new_count,
                    $progress,
                    $config['every_orders'],
                    $config['grams_per_delivery']
                ));
            } else {
                $order->add_order_note(sprintf(
                    '🌿 Progreso muestras: Pedido #%d (%d/%d) - faltan %d para próxima muestra de %sg',
                    $new_count,
                    $progress,
                    $config['every_orders'],
                    $orders_until,
                    $config['grams_per_delivery']
                ));
            }
        }
        
        $order->update_meta_data('_starter_fs_order_number', $new_count);
        $this->mark_order_processed($order, 'success');
    }
    
    // =========================================================================
    // MÉTODOS DE ACCESO A DATOS DEL USUARIO
    // =========================================================================
    
    /**
     * Obtener contador acumulativo de pedidos en el período
     */
    private function get_orders_in_period(int $user_id): int {
        $count = get_user_meta($user_id, self::META_ORDERS_IN_PERIOD, true);
        return max(0, intval($count));
    }
    
    /**
     * Incrementar contador de pedidos en el período (operación atómica)
     */
    private function increment_orders_in_period(int $user_id): int {
        global $wpdb;
        
        // Intentar actualización atómica
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} 
             SET meta_value = meta_value + 1 
             WHERE user_id = %d AND meta_key = %s",
            $user_id,
            self::META_ORDERS_IN_PERIOD
        ));
        
        // Si no existía o no se actualizó, crear con valor 1
        if ($result === 0 || $wpdb->rows_affected === 0) {
            update_user_meta($user_id, self::META_ORDERS_IN_PERIOD, 1);
            return 1;
        }
        
        // Leer el nuevo valor
        return $this->get_orders_in_period($user_id);
    }
    
    /**
     * Obtener contador de entregas realizadas
     */
    private function get_deliveries_count(int $user_id): int {
        $count = get_user_meta($user_id, self::META_DELIVERIES_COUNT, true);
        return max(0, intval($count));
    }
    
    /**
     * Incrementar contador de entregas
     */
    private function increment_deliveries_count(int $user_id): int {
        $current = $this->get_deliveries_count($user_id);
        $new_count = $current + 1;
        update_user_meta($user_id, self::META_DELIVERIES_COUNT, $new_count);
        return $new_count;
    }
    
    /**
     * Resetear todos los datos del usuario (al activar nueva membresía)
     */
    private function reset_user_data(int $user_id): void {
        update_user_meta($user_id, self::META_ORDERS_IN_PERIOD, 0);
        update_user_meta($user_id, self::META_DELIVERIES_COUNT, 0);
        update_user_meta($user_id, self::META_PERIOD_START, current_time('mysql'));
        delete_user_meta($user_id, self::META_LAST_DELIVERY);
        
        // Limpiar datos legacy
        delete_user_meta($user_id, self::LEGACY_META_ORDERS_COUNT);
        delete_user_meta($user_id, self::LEGACY_META_GRAMS_CLAIMED);
        delete_user_meta($user_id, self::LEGACY_META_CYCLE_START);
    }
    
    /**
     * Contar pedidos pendientes de completar (processing, on-hold, pending)
     * Excluye pedidos que ya ganaron muestra gratis (no cuentan para el siguiente ciclo)
     */
    private function count_pending_orders(int $user_id): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $pending_statuses = ['pending', 'processing', 'on-hold'];

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => $pending_statuses,
            'limit' => -1,
        ]);

        // Filtrar pedidos que ya ganaron muestra (no cuentan para el siguiente ciclo)
        $count = 0;
        foreach ($orders as $order) {
            $earned_grams = $order->get_meta('_starter_fs_earned_grams');
            // Solo contar si NO ha ganado muestra
            if (empty($earned_grams) || floatval($earned_grams) <= 0) {
                $count++;
            }
        }

        return $count;
    }
    
    // =========================================================================
    // MÉTODOS AUXILIARES
    // =========================================================================
    
    /**
     * Obtener configuración validada del beneficio
     */
    private function get_validated_config(int $user_id): ?array {
        $config = $this->get_config_for_user($user_id);
        if (!$config) {
            return null;
        }
        
        $total_grams = floatval($config['total_grams'] ?? $config['grams'] ?? 0);
        $grams_per_delivery = floatval($config['grams_per_delivery'] ?? 0);
        $every_orders = intval($config['every_orders'] ?? 1);
        
        if ($total_grams <= 0) {
            return null;
        }
        
        // Valores por defecto si no están configurados
        if ($grams_per_delivery <= 0) {
            $grams_per_delivery = $total_grams;
        }
        if ($every_orders <= 0) {
            $every_orders = 1;
        }
        
        return [
            'total_grams' => $total_grams,
            'grams_per_delivery' => $grams_per_delivery,
            'every_orders' => $every_orders
        ];
    }
    
    /**
     * Marcar pedido como procesado
     */
    private function mark_order_processed(\WC_Order $order, string $reason): void {
        $order->update_meta_data('_starter_fs_processed', 'yes');
        $order->update_meta_data('_starter_fs_processed_at', current_time('mysql'));
        $order->update_meta_data('_starter_fs_result', $reason);
        $order->save();

        $this->log("Pedido marcado como procesado", [
            'order_id' => $order->get_id(),
            'reason' => $reason
        ]);
    }

    /**
     * Garantiza que los meta datos base existan
     */
    private function ensure_user_meta_defaults(int $user_id): void {
        if (get_user_meta($user_id, self::META_ORDERS_IN_PERIOD, true) === '') {
            update_user_meta($user_id, self::META_ORDERS_IN_PERIOD, 0);
        }
        if (get_user_meta($user_id, self::META_DELIVERIES_COUNT, true) === '') {
            update_user_meta($user_id, self::META_DELIVERIES_COUNT, 0);
        }
        if (!get_user_meta($user_id, self::META_PERIOD_START, true)) {
            update_user_meta($user_id, self::META_PERIOD_START, current_time('mysql'));
        }
    }
    
    /**
     * Migrar datos del sistema legacy al nuevo sistema
     * Solo se ejecuta una vez por usuario
     */
    private function migrate_legacy_data(int $user_id): void {
        // Verificar si ya se migró
        $migrated = get_user_meta($user_id, '_starter_fs_migrated_v2', true);
        if ($migrated === 'yes') {
            return;
        }
        
        // Obtener datos legacy
        $legacy_orders = get_user_meta($user_id, self::LEGACY_META_ORDERS_COUNT, true);
        $legacy_grams = get_user_meta($user_id, self::LEGACY_META_GRAMS_CLAIMED, true);
        
        // Si hay datos legacy, migrar
        if ($legacy_orders !== '' || $legacy_grams !== '') {
            $config = $this->get_validated_config($user_id);
            
            if ($config) {
                // Calcular orders_in_period basado en gramos entregados
                $legacy_grams_float = floatval($legacy_grams);
                $deliveries_from_grams = $config['grams_per_delivery'] > 0 
                    ? floor($legacy_grams_float / $config['grams_per_delivery']) 
                    : 0;
                
                // El nuevo contador es: (entregas * every_orders) + orders_count_legacy
                $legacy_orders_int = intval($legacy_orders);
                $new_orders_in_period = ($deliveries_from_grams * $config['every_orders']) + $legacy_orders_int;
                
                update_user_meta($user_id, self::META_ORDERS_IN_PERIOD, $new_orders_in_period);
                update_user_meta($user_id, self::META_DELIVERIES_COUNT, $deliveries_from_grams);
                
                $this->log("Datos legacy migrados", [
                    'user_id' => $user_id,
                    'legacy_orders' => $legacy_orders_int,
                    'legacy_grams' => $legacy_grams_float,
                    'new_orders_in_period' => $new_orders_in_period,
                    'deliveries_count' => $deliveries_from_grams
                ]);
            }
        }
        
        // Marcar como migrado
        update_user_meta($user_id, '_starter_fs_migrated_v2', 'yes');
    }
}

// Registrar el handler
starter_benefit_registry()->register(new Starter_Free_Samples_Handler());
