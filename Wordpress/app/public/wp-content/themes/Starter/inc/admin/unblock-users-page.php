<?php
/**
 * Página de Administración: Desbloquear Usuarios
 * 
 * Interfaz para administradores para gestionar IPs bloqueadas por rate limiting.
 * 
 * @package Starter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agregar página "Desbloquear Usuarios" en el menú de Usuarios
 */
function starter_add_unblock_users_menu() {
    add_users_page(
        'Desbloquear Usuarios',           // Título de la página
        'Desbloquear Usuarios',           // Título del menú
        'manage_options',                 // Capacidad requerida
        'starter-unblock-users',        // Slug del menú
        'starter_render_unblock_users_page' // Función de renderizado
    );
}
add_action('admin_menu', 'starter_add_unblock_users_menu');

/**
 * Renderizar la página de desbloqueo de usuarios
 */
function starter_render_unblock_users_page() {
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
    }
    
    // Procesar formulario de desbloqueo
    $message = '';
    $message_type = '';
    
    if (isset($_POST['starter_unblock_ip']) && check_admin_referer('starter_unblock_ip_action', 'starter_unblock_ip_nonce')) {
        $ip = sanitize_text_field($_POST['ip_address']);
        $action = sanitize_text_field($_POST['action_type']);
        
        if (empty($ip)) {
            $message = 'Por favor, ingresa una dirección IP válida.';
            $message_type = 'error';
        } else {
            $result = starter_unblock_ip($ip, $action);
            
            if ($result) {
                $message = "IP <strong>{$ip}</strong> desbloqueada exitosamente para: <strong>{$action}</strong>";
                $message_type = 'success';
            } else {
                $message = "No se encontraron bloqueos para la IP <strong>{$ip}</strong>. Verifica que la IP sea correcta y esté bloqueada.";
                $message_type = 'warning';
            }
        }
    }
    
    // Obtener IPs bloqueadas actualmente
    $blocked_ips = starter_get_blocked_ips();
    
    ?>
    <div class="wrap">
        <h1>🔓 Desbloquear Usuarios</h1>
        <p class="description">
            Gestiona las direcciones IP bloqueadas por el sistema de rate limiting. 
            Puedes desbloquear IPs que hayan sido bloqueadas por intentos excesivos de login, registro u otras acciones.
        </p>
        
        <?php if ($message): ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>
        
        <hr class="wp-header-end">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            
            <!-- Formulario de Desbloqueo -->
            <div class="card">
                <h2>Desbloquear IP Manualmente</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('starter_unblock_ip_action', 'starter_unblock_ip_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ip_address">Dirección IP</label>
                            </th>
                            <td>
                                <input 
                                    type="text" 
                                    id="ip_address" 
                                    name="ip_address" 
                                    class="regular-text" 
                                    placeholder="Ej: 192.168.1.100"
                                    pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$"
                                    required
                                >
                                <p class="description">
                                    Ingresa la dirección IP que deseas desbloquear.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="action_type">Tipo de Bloqueo</label>
                            </th>
                            <td>
                                <select id="action_type" name="action_type" class="regular-text">
                                    <option value="all">Todas las acciones</option>
                                    <option value="login">Solo Login</option>
                                    <option value="register">Solo Registro</option>
                                    <option value="password_request">Solo Solicitud de Contraseña</option>
                                    <option value="password_reset">Solo Reset de Contraseña</option>
                                    <option value="contact">Solo Formulario de Contacto</option>
                                    <option value="order_email">Solo Email de Orden</option>
                                    <option value="points_transfer">Solo Transferencia de Puntos</option>
                                    <option value="referral_validate">Solo Validación de Referido</option>
                                    <option value="membership_verify">Solo Verificación de Membresía</option>
                                </select>
                                <p class="description">
                                    Selecciona qué tipo de bloqueo deseas eliminar.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Desbloquear IP', 'primary', 'starter_unblock_ip'); ?>
                </form>
            </div>
            
            <!-- Lista de IPs Bloqueadas -->
            <div class="card">
                <h2>IPs Bloqueadas Actualmente</h2>
                
                <?php if (empty($blocked_ips)): ?>
                    <div style="padding: 20px; text-align: center; color: #666;">
                        <p>✅ No hay IPs bloqueadas en este momento.</p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Dirección IP</th>
                                <th>Acción</th>
                                <th>Expira en</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blocked_ips as $block): ?>
                                <tr>
                                    <td><code><?php echo esc_html($block['ip']); ?></code></td>
                                    <td>
                                        <span class="dashicons dashicons-lock" style="color: #d63638;"></span>
                                        <?php echo esc_html($block['action']); ?>
                                    </td>
                                    <td><?php echo esc_html($block['expires_in']); ?></td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('starter_unblock_ip_action', 'starter_unblock_ip_nonce'); ?>
                                            <input type="hidden" name="ip_address" value="<?php echo esc_attr($block['ip']); ?>">
                                            <input type="hidden" name="action_type" value="<?php echo esc_attr($block['action']); ?>">
                                            <button 
                                                type="submit" 
                                                name="starter_unblock_ip" 
                                                class="button button-small"
                                                onclick="return confirm('¿Desbloquear la IP <?php echo esc_js($block['ip']); ?>?');"
                                            >
                                                Desbloquear
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <p style="margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                    <strong>ℹ️ Nota:</strong> Los bloqueos expiran automáticamente después de 30 minutos. 
                    Solo necesitas desbloquear manualmente si es urgente.
                </p>
            </div>
            
        </div>
        
        <!-- Información Adicional -->
        <div class="card" style="margin-top: 20px;">
            <h2>📊 Información del Sistema de Rate Limiting</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Acción</th>
                        <th>Intentos Máximos</th>
                        <th>Ventana de Tiempo</th>
                        <th>Duración del Bloqueo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Login</strong></td>
                        <td>5 intentos</td>
                        <td>15 minutos</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Registro</strong></td>
                        <td>3 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Solicitud de Contraseña</strong></td>
                        <td>5 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Reset de Contraseña</strong></td>
                        <td>10 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Formulario de Contacto</strong></td>
                        <td>8 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Email de Orden</strong></td>
                        <td>3 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Transferencia de Puntos</strong></td>
                        <td>5 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Validación de Referido</strong></td>
                        <td>60 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                    <tr>
                        <td><strong>Verificación de Membresía</strong></td>
                        <td>20 intentos</td>
                        <td>1 hora</td>
                        <td>30 minutos</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
    </div>
    
    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
        }
        .card h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
    </style>
    <?php
}

/**
 * Obtener lista de IPs bloqueadas actualmente
 * 
 * @return array Lista de IPs bloqueadas con información
 */
function starter_get_blocked_ips() {
    global $wpdb;
    
    $blocked_ips = array();
    
    // Buscar transients de bloqueo en la base de datos
    $transients = $wpdb->get_results(
        "SELECT option_name, option_value 
        FROM {$wpdb->options} 
        WHERE option_name LIKE '_transient_starter_blocked_%'",
        ARRAY_A
    );
    
    foreach ($transients as $transient) {
        $key = str_replace('_transient_', '', $transient['option_name']);
        $block_data = maybe_unserialize($transient['option_value']);
        
        // Soporte para formato antiguo (solo timestamp) y nuevo (array con datos)
        if (is_array($block_data)) {
            $blocked_until = $block_data['blocked_until'];
            $ip = $block_data['ip'];
            $action = $block_data['action'];
        } else {
            // Formato antiguo: solo timestamp
            $blocked_until = intval($block_data);
            
            // Extraer acción del nombre del transient
            preg_match('/starter_blocked_([^_]+)_(.+)/', $key, $matches);
            $action = isset($matches[1]) ? $matches[1] : 'unknown';
            $ip_hash = isset($matches[2]) ? $matches[2] : '';
            $ip = 'Hash: ' . substr($ip_hash, 0, 8) . '...';
        }
        
        // Verificar si aún está bloqueado
        if ($blocked_until > time()) {
            $remaining = $blocked_until - time();
            $minutes = ceil($remaining / 60);
            
            $blocked_ips[] = array(
                'ip' => $ip,
                'action' => $action,
                'blocked_until' => $blocked_until,
                'expires_in' => $minutes . ' min',
                'transient_key' => $key
            );
        }
    }
    
    return $blocked_ips;
}
