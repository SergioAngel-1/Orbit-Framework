<?php
/**
 * Página de Administración: Aprobación Automática de Usuarios
 * 
 * Interfaz para administradores para configurar la aprobación automática de usuarios.
 * 
 * @package Starter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agregar página "Aprobación Automática" en el menú de Usuarios
 */
function starter_add_auto_approval_menu() {
    add_users_page(
        'Aprobación Automática',          // Título de la página
        'Aprobación Automática',          // Título del menú
        'manage_options',                 // Capacidad requerida
        'starter-auto-approval',        // Slug del menú
        'starter_render_auto_approval_page' // Función de renderizado
    );
}
add_action('admin_menu', 'starter_add_auto_approval_menu', 5); // Prioridad 5 para aparecer antes de Desbloquear

/**
 * Renderizar la página de configuración de aprobación automática
 */
function starter_render_auto_approval_page() {
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
    }
    
    // Procesar formulario de configuración
    $message = '';
    $message_type = '';
    
    if (isset($_POST['starter_save_auto_approval']) && check_admin_referer('starter_auto_approval_action', 'starter_auto_approval_nonce')) {
        // Guardar configuración
        $auto_approval_enabled = isset($_POST['auto_approval_enabled']) ? '1' : '0';
        $send_welcome_email = isset($_POST['send_welcome_email']) ? '1' : '0';
        $blocked_email_domains = isset($_POST['blocked_email_domains']) ? sanitize_textarea_field($_POST['blocked_email_domains']) : '';
        
        update_option('starter_auto_approval_enabled', $auto_approval_enabled);
        update_option('starter_send_welcome_email', $send_welcome_email);
        update_option('starter_blocked_email_domains', $blocked_email_domains);
        
        $message = 'Configuración guardada exitosamente.';
        $message_type = 'success';
        
        // Log del cambio
        error_log(sprintf(
            'Starter: Configuración de aprobación automática actualizada - Habilitado: %s',
            $auto_approval_enabled
        ));
    }
    
    // Obtener configuración actual
    $auto_approval_enabled = get_option('starter_auto_approval_enabled', '0');
    $send_welcome_email = get_option('starter_send_welcome_email', '1');
    $blocked_email_domains = get_option('starter_blocked_email_domains', '');
    
    // Estadísticas
    $stats = starter_get_user_approval_stats();
    
    ?>
    <div class="wrap">
        <h1>🔐 Aprobación Automática de Usuarios</h1>
        <p class="description">
            Configura cómo se gestionan los nuevos registros de usuarios. 
            Puedes habilitar la aprobación automática para que los usuarios tengan acceso inmediato al registrarse.
        </p>
        
        <?php if ($message): ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
        
        <hr class="wp-header-end">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            
            <!-- Formulario de Configuración -->
            <div class="card">
                <h2>⚙️ Configuración</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('starter_auto_approval_action', 'starter_auto_approval_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="auto_approval_enabled">Aprobación Automática</label>
                            </th>
                            <td>
                                <label class="starter-toggle">
                                    <input 
                                        type="checkbox" 
                                        id="auto_approval_enabled" 
                                        name="auto_approval_enabled" 
                                        value="1"
                                        <?php checked($auto_approval_enabled, '1'); ?>
                                    >
                                    <span class="starter-toggle-slider"></span>
                                </label>
                                <p class="description">
                                    <strong>Habilitado:</strong> Los usuarios se aprueban automáticamente al registrarse.<br>
                                    <strong>Deshabilitado:</strong> Los usuarios quedan pendientes de aprobación manual por un administrador.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="send_welcome_email">Email de Bienvenida</label>
                            </th>
                            <td>
                                <label class="starter-toggle">
                                    <input 
                                        type="checkbox" 
                                        id="send_welcome_email" 
                                        name="send_welcome_email" 
                                        value="1"
                                        <?php checked($send_welcome_email, '1'); ?>
                                    >
                                    <span class="starter-toggle-slider"></span>
                                </label>
                                <p class="description">
                                    Enviar correo de bienvenida automático cuando un usuario es aprobado (manual o automáticamente).
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="blocked_email_domains">Dominios Bloqueados</label>
                            </th>
                            <td>
                                <textarea 
                                    id="blocked_email_domains" 
                                    name="blocked_email_domains" 
                                    class="large-text" 
                                    rows="4"
                                    placeholder="ejemplo.com&#10;spam.net&#10;tempmail.org"
                                ><?php echo esc_textarea($blocked_email_domains); ?></textarea>
                                <p class="description">
                                    Lista de dominios de email bloqueados (uno por línea). 
                                    Los usuarios con estos dominios no podrán registrarse.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Guardar Configuración', 'primary', 'starter_save_auto_approval'); ?>
                </form>
            </div>
            
            <!-- Panel de Estadísticas -->
            <div>
                <div class="card">
                    <h2>📊 Estadísticas de Usuarios</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <tbody>
                            <tr>
                                <td><strong>Total de usuarios</strong></td>
                                <td style="text-align: right;"><?php echo number_format($stats['total']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>✅ Aprobados</strong></td>
                                <td style="text-align: right; color: #00a32a;"><?php echo number_format($stats['approved']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>⏳ Pendientes</strong></td>
                                <td style="text-align: right; color: #dba617;"><?php echo number_format($stats['pending']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>❌ Rechazados</strong></td>
                                <td style="text-align: right; color: #d63638;"><?php echo number_format($stats['rejected']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <?php if ($stats['pending'] > 0): ?>
                        <p style="margin-top: 15px;">
                            <a href="<?php echo admin_url('users.php?role=subscriber'); ?>" class="button button-secondary">
                                Ver usuarios pendientes
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="card" style="margin-top: 20px;">
                    <h2>ℹ️ Estado Actual</h2>
                    <div style="padding: 15px; border-radius: 4px; <?php echo $auto_approval_enabled === '1' ? 'background: #d4edda; border: 1px solid #c3e6cb;' : 'background: #fff3cd; border: 1px solid #ffeeba;'; ?>">
                        <?php if ($auto_approval_enabled === '1'): ?>
                            <p style="margin: 0; color: #155724;">
                                <strong>✅ Aprobación Automática ACTIVADA</strong><br>
                                Los nuevos usuarios se aprueban inmediatamente al registrarse.
                            </p>
                        <?php else: ?>
                            <p style="margin: 0; color: #856404;">
                                <strong>⏳ Aprobación Manual ACTIVADA</strong><br>
                                Los nuevos usuarios requieren aprobación de un administrador.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
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
        
        /* Toggle Switch Styles */
        .starter-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .starter-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .starter-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 26px;
        }
        .starter-toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        .starter-toggle input:checked + .starter-toggle-slider {
            background-color: #00a32a;
        }
        .starter-toggle input:checked + .starter-toggle-slider:before {
            transform: translateX(24px);
        }
    </style>
    <?php
}

/**
 * Obtener estadísticas de usuarios por estado de aprobación
 * 
 * @return array Estadísticas de usuarios
 */
function starter_get_user_approval_stats() {
    global $wpdb;
    
    // Total de usuarios (excluyendo administradores)
    $total = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
        WHERE um.meta_key = '{$wpdb->prefix}capabilities'
        AND um.meta_value NOT LIKE '%administrator%'
    ");
    
    // Usuarios pendientes
    $pending = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->usermeta}
        WHERE meta_key = 'pending_approval'
        AND meta_value = '1'
    ");
    
    // Usuarios rechazados
    $rejected = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->usermeta}
        WHERE meta_key = 'rejected_status'
        AND meta_value = '1'
    ");
    
    // Usuarios aprobados (total - pendientes - rechazados)
    $approved = max(0, $total - $pending - $rejected);
    
    return array(
        'total' => intval($total),
        'approved' => intval($approved),
        'pending' => intval($pending),
        'rejected' => intval($rejected)
    );
}

/**
 * Verificar si la aprobación automática está habilitada
 * 
 * @return bool True si está habilitada
 */
function starter_is_auto_approval_enabled() {
    return get_option('starter_auto_approval_enabled', '0') === '1';
}

/**
 * Obtener el nivel de membresía por defecto
 * 
 * @return int Nivel de membresía (0-4)
 */
function starter_get_default_membership_level() {
    return intval(get_option('starter_default_membership_level', 0));
}

/**
 * Verificar si un dominio de email está bloqueado
 * 
 * @param string $email Email a verificar
 * @return bool True si el dominio está bloqueado
 */
function starter_is_email_domain_blocked($email) {
    $blocked_domains = get_option('starter_blocked_email_domains', '');
    
    if (empty($blocked_domains)) {
        return false;
    }
    
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    $blocked_list = array_filter(array_map('trim', explode("\n", strtolower($blocked_domains))));
    
    return in_array($domain, $blocked_list, true);
}
