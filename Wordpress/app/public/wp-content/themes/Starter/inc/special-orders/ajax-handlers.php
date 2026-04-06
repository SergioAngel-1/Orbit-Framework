<?php
/**
 * Manejadores AJAX para Ventas Especiales
 * 
 * Acciones administrativas sobre compras pendientes:
 * - Marcar como completado manualmente
 * - Descartar compra pendiente
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar scripts y localizar datos AJAX solo en nuestra página
 */
add_action('admin_enqueue_scripts', 'starter_so_enqueue_admin_scripts');
function starter_so_enqueue_admin_scripts($hook) {
    // Solo cargar en nuestra página
    if ($hook !== 'woocommerce_page_' . STARTER_SPECIAL_ORDERS_PAGE_SLUG) {
        return;
    }
    
    // Inline script (no necesitamos archivo externo para esto)
    wp_enqueue_script('jquery');
    
    add_action('admin_footer', 'starter_so_inline_scripts');
}

/**
 * Scripts inline para acciones AJAX en la página de Ventas Especiales
 */
function starter_so_inline_scripts() {
    $nonce = wp_create_nonce('starter_so_action');
    $ajax_url = admin_url('admin-ajax.php');
    
    ?>
    <script type="text/javascript">
    (function($) {
        'use strict';
        
        $(document).on('click', '.starter-so-complete-btn', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var purchaseId = $btn.data('id');
            var orderType = $btn.data('type');
            var $row = $btn.closest('tr');
            
            if (!confirm('¿Estás seguro de completar esta compra?\n\nEsto ejecutará el proceso completo:\n- Membresías: activar membresía + acreditar FC de bono + crear orden WC\n- Paquetes FC: acreditar Virtual Coins + crear orden WC')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Procesando...');
            
            $.ajax({
                url: '<?php echo esc_js($ajax_url); ?>',
                type: 'POST',
                data: {
                    action: 'starter_so_mark_completed',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    purchase_id: purchaseId,
                    order_type: orderType
                },
                success: function(response) {
                    if (response.success) {
                        // Actualizar la fila visualmente
                        $row.find('.order-status')
                            .removeClass()
                            .addClass('order-status status-completed')
                            .find('span').text('Completado');
                        
                        $row.find('.column-reason').html('<span class="na">&ndash;</span>');
                        $btn.remove();
                        $row.find('.starter-so-dismiss-btn').remove();
                        
                        // Actualizar contador de pendientes en la pestaña
                        var $pendingTab = $('a[href*="pending_purchases"] .count');
                        if ($pendingTab.length) {
                            var current = parseInt($pendingTab.text().replace(/[()]/g, ''));
                            if (current > 0) {
                                $pendingTab.text('(' + (current - 1) + ')');
                            }
                        }
                    } else {
                        alert('Error: ' + (response.data || 'No se pudo completar la acción'));
                        $btn.prop('disabled', false).text('Completar');
                    }
                },
                error: function() {
                    alert('Error de conexión. Intenta de nuevo.');
                    $btn.prop('disabled', false).text('Completar');
                }
            });
        });
        // Handler para descartar compra pendiente
        $(document).on('click', '.starter-so-dismiss-btn', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var purchaseId = $btn.data('id');
            var orderType = $btn.data('type');
            var $row = $btn.closest('tr');
            
            if (!confirm('¿Estás seguro de descartar esta compra?\n\nLa compra se marcará como descartada y desaparecerá de la lista de pendientes. No se activará membresía ni se acreditarán Virtual Coins.')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Descartando...');
            $row.find('.starter-so-complete-btn').prop('disabled', true);
            
            $.ajax({
                url: '<?php echo esc_js($ajax_url); ?>',
                type: 'POST',
                data: {
                    action: 'starter_so_dismiss_purchase',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    purchase_id: purchaseId,
                    order_type: orderType
                },
                success: function(response) {
                    if (response.success) {
                        // Animar y remover la fila
                        $row.css('background-color', '#fce4e4').fadeOut(400, function() {
                            $(this).remove();
                            
                            // Si no quedan filas, mostrar mensaje vacío
                            if ($('.starter-so-table tbody tr').length === 0) {
                                var colCount = $('.starter-so-table thead th').length;
                                $('.starter-so-table tbody').html(
                                    '<tr><td colspan="' + colCount + '" style="text-align: center; padding: 20px;"><em>No se encontraron ventas especiales con los filtros seleccionados.</em></td></tr>'
                                );
                            }
                        });
                        
                        // Actualizar contador de pendientes en la pestaña
                        var $pendingTab = $('a[href*="pending_purchases"] .count');
                        if ($pendingTab.length) {
                            var current = parseInt($pendingTab.text().replace(/[()]/g, ''));
                            if (current > 0) {
                                $pendingTab.text('(' + (current - 1) + ')');
                            }
                        }
                    } else {
                        alert('Error: ' + (response.data || 'No se pudo descartar la compra'));
                        $btn.prop('disabled', false).text('Descartar');
                        $row.find('.starter-so-complete-btn').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Error de conexión. Intenta de nuevo.');
                    $btn.prop('disabled', false).text('Descartar');
                    $row.find('.starter-so-complete-btn').prop('disabled', false);
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX handler: Completar compra pendiente ejecutando el flujo completo
 * 
 * Para membresías: activa membresía + acredita FC bono + crea orden WC + historial + hook
 * Para paquetes FC: acredita Virtual Coins + crea orden WC + hook
 * 
 * Es la versión manual (admin) del flujo que normalmente ejecuta Wompi.
 */
add_action('wp_ajax_starter_so_mark_completed', 'starter_so_ajax_mark_completed');
add_action('wp_ajax_starter_so_dismiss_purchase', 'starter_so_ajax_dismiss_purchase');
function starter_so_ajax_mark_completed() {
    // Verificar nonce
    if (!check_ajax_referer('starter_so_action', 'nonce', false)) {
        wp_send_json_error('Nonce inválido');
    }
    
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Sin permisos');
    }
    
    global $wpdb;
    
    $purchase_id = absint($_POST['purchase_id'] ?? 0);
    $order_type  = sanitize_text_field($_POST['order_type'] ?? '');
    
    if (!$purchase_id || !$order_type) {
        wp_send_json_error('Parámetros inválidos');
    }
    
    // Determinar tabla según tipo
    if ($order_type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        $table = $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES;
    } elseif ($order_type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
        $table = $wpdb->prefix . STARTER_TABLE_FC_PURCHASES;
    } else {
        wp_send_json_error('Tipo de orden no válido');
    }
    
    // Obtener la compra completa
    $purchase = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM `{$table}` WHERE id = %d",
        $purchase_id
    ));
    
    if (!$purchase) {
        wp_send_json_error('Compra no encontrada');
    }
    
    if ($purchase->status === 'completed') {
        wp_send_json_error('Esta compra ya está completada');
    }
    
    $current_admin = wp_get_current_user();
    $errors = [];
    $steps_done = [];
    
    // Marcar como processing
    $wpdb->update($table, ['status' => 'processing'], ['id' => $purchase_id]);
    
    // Safety net: si el script muere por fatal error, revertir a pending
    $GLOBALS['_starter_so_processing'] = [
        'table' => $table,
        'id'    => $purchase_id,
    ];
    register_shutdown_function(function() {
        $ctx = $GLOBALS['_starter_so_processing'] ?? null;
        if (!$ctx) return; // Ya se limpió = terminó bien
        
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            global $wpdb;
            $wpdb->update(
                $ctx['table'],
                ['status' => 'pending', 'processed_at' => current_time('mysql')],
                ['id' => $ctx['id']]
            );
            error_log(sprintf(
                '[Starter Special Orders] FATAL: Script murió durante procesamiento manual. Revertido a pending. id=%d, error=%s en %s:%d',
                $ctx['id'], $error['message'], $error['file'], $error['line']
            ));
        }
    });
    
    try {
    
    // ─── FLUJO MEMBRESÍA ───
    if ($order_type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        $user_id          = (int) $purchase->user_id;
        $membership_level = (int) $purchase->membership_level;
        $duration_days    = (int) $purchase->duration_days;
        $monthly_points   = (int) $purchase->monthly_points;
        $reference        = $purchase->reference;
        
        // 1. ACTIVAR MEMBRESÍA
        $membership_activated = false;
        if (function_exists('starter_activate_user_membership')) {
            $membership_id = starter_activate_user_membership($user_id, $membership_level, $duration_days, $purchase->product_id);
            $membership_activated = !empty($membership_id);
            
            if ($membership_activated) {
                $steps_done[] = 'Membresía activada (nivel ' . $membership_level . ', ' . $duration_days . ' días)';
            } else {
                $errors[] = 'No se pudo activar la membresía';
            }
        } else {
            $errors[] = 'Función starter_activate_user_membership no disponible';
        }
        
        // 2. ACREDITAR FC DE BONO
        if ($membership_activated && $monthly_points > 0) {
            if (function_exists('starter_rp_add_points')) {
                $level_info = null;
                if (class_exists('Starter_Memberships')) {
                    $level_info = Starter_Memberships::get_membership_level($membership_level);
                }
                $level_name = $level_info ? $level_info['name'] : "Nivel $membership_level";
                
                $description = sprintf(
                    'Bono de membresía %s - %s FC (Completado manualmente por %s)',
                    $level_name,
                    number_format($monthly_points),
                    $current_admin->user_login
                );
                
                $expiration_days = 365;
                if (function_exists('Starter_RP')) {
                    $options = Starter_RP()->get_options();
                    $expiration_days = $options['points_expiry_days'] ?? 365;
                }
                
                $points_added = starter_rp_add_points(
                    $user_id,
                    $monthly_points,
                    'membership_bonus',
                    $description,
                    $purchase->id,
                    $expiration_days
                );
                
                if ($points_added) {
                    $steps_done[] = number_format($monthly_points) . ' FC de bono acreditados';
                } else {
                    $errors[] = 'No se pudieron acreditar los FC de bono';
                }
            } else {
                $errors[] = 'Función starter_rp_add_points no disponible';
            }
        }
        
        // 3. CREAR ORDEN WOOCOMMERCE
        $wc_order_id = null;
        if ($membership_activated && function_exists('starter_create_membership_wc_order')) {
            // Construir datos de transacción simulada para la función de creación de orden
            $fake_transaction = [
                'id' => 'manual_' . $current_admin->user_login . '_' . time(),
                'payment_method_type' => 'MANUAL',
                'amount_in_cents' => (int) ($purchase->price * 100),
            ];
            
            $wc_order_id = starter_create_membership_wc_order($purchase, $fake_transaction, $reference);
            
            if ($wc_order_id) {
                // Agregar nota indicando que fue completado manualmente
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        'Compra completada manualmente por admin: %s',
                        $current_admin->user_login
                    ));
                    $order->save();
                }
                $steps_done[] = 'Orden WC #' . $wc_order_id . ' creada';
            } else {
                $errors[] = 'No se pudo crear la orden WC';
            }
        }
        
        // 4. REGISTRAR EN HISTORIAL
        if ($membership_activated && function_exists('starter_memberships_log_action')) {
            starter_memberships_log_action(
                $user_id,
                'activation',
                null,
                $membership_level,
                [
                    'source' => 'manual_admin',
                    'reference' => $reference,
                    'product_id' => $purchase->product_id,
                    'completed_by' => $current_admin->user_login,
                ],
                $membership_id ?? null
            );
            $steps_done[] = 'Historial registrado';
        }
        
        // Actualizar estado final
        if ($membership_activated) {
            $wpdb->update(
                $table,
                [
                    'status'       => 'completed',
                    'wc_order_id'  => $wc_order_id,
                    'processed_at' => current_time('mysql'),
                ],
                ['id' => $purchase_id]
            );
            
            // 5. DISPARAR HOOK
            do_action('starter_membership_purchase_completed', $user_id, $membership_level, $monthly_points, $purchase, [
                'id' => 'manual_' . $current_admin->user_login,
                'payment_method_type' => 'MANUAL',
            ]);
            $steps_done[] = 'Hook disparado';
        } else {
            $wpdb->update(
                $table,
                [
                    'status'       => 'error_activation',
                    'processed_at' => current_time('mysql'),
                ],
                ['id' => $purchase_id]
            );
        }
    }
    
    // ─── FLUJO PAQUETE FC ───
    if ($order_type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
        $user_id     = (int) $purchase->user_id;
        $total_coins = (int) $purchase->total_coins;
        $reference   = $purchase->reference;
        
        // 1. ACREDITAR VIRTUAL COINS
        if (function_exists('starter_rp_add_points')) {
            $product_name = get_the_title($purchase->product_id) ?: 'Paquete FC #' . $purchase->product_id;
            
            $description = sprintf(
                'Compra de paquete "%s" - %s FC (Completado manualmente por %s)',
                $product_name,
                number_format($total_coins),
                $current_admin->user_login
            );
            
            $expiration_days = 365;
            if (function_exists('Starter_RP')) {
                $options = Starter_RP()->get_options();
                $expiration_days = $options['points_expiry_days'] ?? 365;
            }
            
            $points_added = starter_rp_add_points(
                $user_id,
                $total_coins,
                'fc_purchase',
                $description,
                $purchase->id,
                $expiration_days
            );
            
            if ($points_added) {
                $steps_done[] = number_format($total_coins) . ' FC acreditados';
            } else {
                $errors[] = 'No se pudieron acreditar los Virtual Coins';
            }
        } else {
            $errors[] = 'Función starter_rp_add_points no disponible';
        }
        
        // 2. CREAR ORDEN WOOCOMMERCE
        $wc_order_id = null;
        $points_ok = !empty($points_added);
        if ($points_ok && function_exists('starter_create_fc_wc_order')) {
            $fake_transaction = [
                'id' => 'manual_' . $current_admin->user_login . '_' . time(),
                'payment_method_type' => 'MANUAL',
                'amount_in_cents' => (int) ($purchase->price * 100),
            ];
            
            $wc_order_id = starter_create_fc_wc_order($purchase, $fake_transaction, $reference);
            
            if ($wc_order_id) {
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        'Compra completada manualmente por admin: %s',
                        $current_admin->user_login
                    ));
                    $order->save();
                }
                $steps_done[] = 'Orden WC #' . $wc_order_id . ' creada';
            } else {
                $errors[] = 'No se pudo crear la orden WC';
            }
        }
        
        // Actualizar estado final
        if ($points_ok) {
            $wpdb->update(
                $table,
                [
                    'status'       => 'completed',
                    'wc_order_id'  => $wc_order_id,
                    'processed_at' => current_time('mysql'),
                ],
                ['id' => $purchase_id]
            );
            
            // 3. DISPARAR HOOK
            do_action('starter_fc_purchase_completed', $user_id, $total_coins, $purchase, [
                'id' => 'manual_' . $current_admin->user_login,
                'payment_method_type' => 'MANUAL',
            ]);
            $steps_done[] = 'Hook disparado';
        } else {
            $wpdb->update(
                $table,
                [
                    'status'       => 'error_adding_points',
                    'processed_at' => current_time('mysql'),
                ],
                ['id' => $purchase_id]
            );
        }
    }
    
    } catch (\Throwable $e) {
        // Si algo lanza excepción, revertir a pending y reportar
        $wpdb->update(
            $table,
            ['status' => 'pending', 'processed_at' => null],
            ['id' => $purchase_id]
        );
        
        error_log(sprintf(
            '[Starter Special Orders] EXCEPCIÓN durante procesamiento manual: type=%s, id=%d, error=%s, steps_before=[%s]',
            $order_type, $purchase_id, $e->getMessage(), implode(', ', $steps_done)
        ));
        
        // Limpiar safety net
        unset($GLOBALS['_starter_so_processing']);
        
        starter_so_invalidate_cache();
        
        wp_send_json_error('Error durante el procesamiento: ' . $e->getMessage() . '. Pasos completados: ' . implode(', ', $steps_done));
    }
    
    // Limpiar safety net (todo salió bien)
    unset($GLOBALS['_starter_so_processing']);
    
    // Invalidar cachés
    starter_so_invalidate_cache();
    
    // Log completo
    error_log(sprintf(
        '[Starter Special Orders] Compra completada manualmente: type=%s, id=%d, ref=%s, user=%d, by_admin=%s, steps=[%s], errors=[%s]',
        $order_type,
        $purchase_id,
        $purchase->reference,
        $purchase->user_id,
        $current_admin->user_login,
        implode(', ', $steps_done),
        implode(', ', $errors)
    ));
    
    // Respuesta
    if (!empty($errors) && empty($steps_done)) {
        wp_send_json_error('Falló la activación: ' . implode('. ', $errors));
    }
    
    wp_send_json_success([
        'message'     => 'Compra completada: ' . implode(' • ', $steps_done),
        'purchase_id' => $purchase_id,
        'steps'       => $steps_done,
        'warnings'    => $errors,
    ]);
}

/**
 * AJAX handler: Descartar compra pendiente
 * 
 * Marca la compra como 'dismissed' para sacarla de la lista de pendientes
 * sin ejecutar ninguna lógica de activación o acreditación.
 * La compra permanece en la base de datos para auditoría.
 */
function starter_so_ajax_dismiss_purchase() {
    // Verificar nonce
    if (!check_ajax_referer('starter_so_action', 'nonce', false)) {
        wp_send_json_error('Nonce inválido');
    }
    
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Sin permisos');
    }
    
    global $wpdb;
    
    $purchase_id = absint($_POST['purchase_id'] ?? 0);
    $order_type  = sanitize_text_field($_POST['order_type'] ?? '');
    
    if (!$purchase_id || !$order_type) {
        wp_send_json_error('Parámetros inválidos');
    }
    
    // Determinar tabla según tipo
    if ($order_type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        $table = $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES;
    } elseif ($order_type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
        $table = $wpdb->prefix . STARTER_TABLE_FC_PURCHASES;
    } else {
        wp_send_json_error('Tipo de orden no válido');
    }
    
    // Verificar que la compra existe y no está completada
    $purchase = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status, reference, user_id FROM `{$table}` WHERE id = %d",
        $purchase_id
    ));
    
    if (!$purchase) {
        wp_send_json_error('Compra no encontrada');
    }
    
    if ($purchase->status === 'completed') {
        wp_send_json_error('No se puede descartar una compra ya completada');
    }
    
    if ($purchase->status === 'dismissed') {
        wp_send_json_error('Esta compra ya fue descartada');
    }
    
    // Actualizar estado a dismissed
    $updated = $wpdb->update(
        $table,
        [
            'status'       => 'dismissed',
            'processed_at' => current_time('mysql'),
        ],
        ['id' => $purchase_id],
        ['%s', '%s'],
        ['%d']
    );
    
    if ($updated === false) {
        wp_send_json_error('Error al actualizar la base de datos');
    }
    
    // Invalidar cachés
    starter_so_invalidate_cache();
    
    // Log de la acción manual
    $current_user = wp_get_current_user();
    error_log(sprintf(
        '[Starter Special Orders] Compra descartada manualmente: type=%s, id=%d, ref=%s, user=%d, prev_status=%s, by_admin=%s',
        $order_type,
        $purchase_id,
        $purchase->reference,
        $purchase->user_id,
        $purchase->status,
        $current_user->user_login
    ));
    
    wp_send_json_success([
        'message'     => 'Compra descartada',
        'purchase_id' => $purchase_id,
    ]);
}
