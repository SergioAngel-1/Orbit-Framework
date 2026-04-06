<?php
/**
 * Renderizador de la página de administración para membresías por antigüedad
 * 
 * Contiene todo el HTML y JavaScript de la interfaz de usuario.
 * Separado de la lógica de negocio para mejor mantenibilidad.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Legacy
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Legacy_Page_Renderer {
    
    /**
     * Renderizar página principal
     */
    public static function render() {
        $message = '';
        $message_type = '';
        
        // Procesar formularios POST
        $form_result = self::process_forms();
        if ($form_result) {
            $message = $form_result['message'];
            $message_type = $form_result['type'];
        }
        
        // Obtener datos
        $stats = Starter_Legacy_Membership_Service::get_stats();
        $level_info = Starter_Memberships::get_membership_level(5);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($level_info['icon']); ?> <?php _e('Membresía por Antigüedad', 'starter-memberships'); ?></h1>
            
            <?php self::render_notice($message, $message_type); ?>
            
            <p class="description">
                <?php _e('Esta membresía especial se asigna a usuarios existentes que han sido parte de la comunidad. No está disponible para compra.', 'starter-memberships'); ?>
            </p>
            
            <?php self::render_stats_cards($stats, $level_info); ?>
            
            <style>
                .starter-legacy-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-top: 20px; }
                .starter-legacy-grid .card { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 20px; }
                .starter-legacy-column { display: flex; flex-direction: column; gap: 20px; }
                @media (max-width: 1200px) { .starter-legacy-grid { grid-template-columns: 1fr; } }
            </style>
            
            <div class="starter-legacy-grid">
                <div class="starter-legacy-column">
                    <?php self::render_mass_assignment_card($stats, $level_info); ?>
                    <?php self::render_individual_assignment_card(); ?>
                </div>
                
                <?php self::render_users_list_card($stats); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Procesar formularios POST
     */
    private static function process_forms() {
        // Asignación individual
        if (isset($_POST['starter_assign_single']) && check_admin_referer('starter_legacy_single_nonce')) {
            $user_id = intval($_POST['user_id']);
            $duration = intval($_POST['duration_days']) ?: 365;
            
            if (Starter_Legacy_Membership_Service::assign_single($user_id, $duration)) {
                return ['message' => 'Membresía por antigüedad asignada correctamente.', 'type' => 'success'];
            } else {
                return ['message' => 'Usuario no válido.', 'type' => 'error'];
            }
        }
        
        return null;
    }
    
    /**
     * Renderizar aviso
     */
    private static function render_notice($message, $type) {
        if (!$message) return;
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }
    
    /**
     * Renderizar tarjetas de estadísticas
     */
    private static function render_stats_cards($stats, $level_info) {
        ?>
        <div style="display: flex; justify-content: space-between; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
            <div class="card" style="padding: 20px; border-left: 4px solid <?php echo esc_attr($level_info['color']); ?>; flex: 0 0 auto;">
                <h3 style="margin-top: 0;">👥 <?php _e('Usuarios con esta Membresía', 'starter-memberships'); ?></h3>
                <p style="font-size: 36px; font-weight: bold; margin: 10px 0; color: <?php echo esc_attr($level_info['color']); ?>;">
                    <?php echo number_format($stats['with_legacy']); ?>
                </p>
            </div>
            
            <div class="card" style="padding: 20px; flex: 0 0 auto;">
                <h3 style="margin-top: 0;">🌸 <?php _e('FC por Activación', 'starter-memberships'); ?></h3>
                <p style="font-size: 36px; font-weight: bold; margin: 10px 0; color: #dba617;">
                    <?php echo starter_format_fc($level_info['monthly_points']); ?>
                </p>
                <p class="description"><?php _e('Se otorgan una única vez al activar', 'starter-memberships'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizar tarjeta de asignación masiva
     */
    private static function render_mass_assignment_card($stats, $level_info) {
        ?>
        <div class="card">
            <h3 style="margin-top: 0;">🚀 <?php _e('Asignación Masiva', 'starter-memberships'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Modo de Asignación', 'starter-memberships'); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="mass_assign_mode" value="new_only" checked id="mode_new_only">
                            <?php _e('Solo usuarios sin membresía', 'starter-memberships'); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="mass_assign_mode" value="all" id="mode_all">
                            <?php _e('Todos (nuevos + extender existentes)', 'starter-memberships'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Duración', 'starter-memberships'); ?></th>
                    <td>
                        <select id="mass_assign_duration">
                            <option value="365"><?php _e('1 año (365 días)', 'starter-memberships'); ?></option>
                            <option value="180"><?php _e('6 meses (180 días)', 'starter-memberships'); ?></option>
                            <option value="90"><?php _e('3 meses (90 días)', 'starter-memberships'); ?></option>
                            <option value="36500"><?php _e('Sin expiración (~100 años)', 'starter-memberships'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <div id="mass-assign-progress-container" style="display: none; margin: 20px 0;">
                <div style="background: #f0f0f0; border-radius: 5px; overflow: hidden; height: 30px; position: relative;">
                    <div id="mass-assign-progress-bar" style="background: <?php echo esc_attr($level_info['color']); ?>; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    <span id="mass-assign-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold;">0%</span>
                </div>
                <p id="mass-assign-progress-status" style="margin-top: 10px;">Iniciando...</p>
            </div>
            
            <p class="submit" id="mass-assign-button-container">
                <button type="button" id="start-mass-assignment" class="button button-primary">
                    <?php _e('Iniciar Asignación Masiva', 'starter-memberships'); ?>
                </button>
                <span class="description" style="margin-left: 10px;">
                    <?php _e('Procesa en lotes de 100 usuarios', 'starter-memberships'); ?>
                </span>
            </p>
        </div>
        
        <?php self::render_mass_assignment_script(); ?>
        <?php
    }
    
    /**
     * Renderizar script de asignación masiva
     */
    private static function render_mass_assignment_script() {
        ?>
        <script>
        jQuery(function($) {
            var massAssignProcessing = false;
            var massAssignNonce = '<?php echo wp_create_nonce('starter_mass_assign_nonce'); ?>';
            
            $('#start-mass-assignment').on('click', function() {
                if (massAssignProcessing) return;
                
                var mode = $('input[name="mass_assign_mode"]:checked').val();
                var duration = $('#mass_assign_duration').val();
                var modeText = mode === 'new_only' ? 'usuarios sin membresía' : 'todos los usuarios';
                
                if (!confirm('¿Iniciar asignación masiva para ' + modeText + ' con duración de ' + duration + ' días?')) {
                    return;
                }
                
                massAssignProcessing = true;
                $('#mass-assign-button-container').hide();
                $('#mass-assign-progress-container').show();
                
                processMassAssignBatch(true, mode, duration);
            });
            
            function processMassAssignBatch(isFirst, mode, duration) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'starter_mass_assign_legacy',
                        nonce: massAssignNonce,
                        reset: isFirst ? '1' : '0',
                        mode: mode,
                        duration: duration
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var percent = data.total > 0 ? Math.round((data.total_processed / data.total) * 100) : 100;
                            
                            $('#mass-assign-progress-bar').css('width', percent + '%');
                            $('#mass-assign-progress-text').text(percent + '%');
                            $('#mass-assign-progress-status').html(
                                'Procesados: <strong>' + data.total_processed + '</strong> de <strong>' + data.total + '</strong> | ' +
                                'Asignados: <strong>' + data.total_assigned + '</strong> | ' +
                                'Actualizados: <strong>' + (data.total_updated || 0) + '</strong>'
                            );
                            
                            if (!data.is_complete) {
                                setTimeout(function() { processMassAssignBatch(false, mode, duration); }, 300);
                            } else {
                                $('#mass-assign-progress-status').html(
                                    '<span style="color: green; font-weight: bold;">✅ ¡Completado!</span> ' +
                                    'Asignados: <strong>' + data.total_assigned + '</strong> | ' +
                                    'Actualizados: <strong>' + (data.total_updated || 0) + '</strong>'
                                );
                                setTimeout(function() { location.reload(); }, 2000);
                            }
                        } else {
                            $('#mass-assign-progress-status').html('<span style="color: red;">Error: ' + (response.data?.message || 'Error desconocido') + '</span>');
                            massAssignProcessing = false;
                            $('#mass-assign-button-container').show();
                        }
                    },
                    error: function() {
                        $('#mass-assign-progress-status').html('<span style="color: red;">Error de conexión. Intenta de nuevo.</span>');
                        massAssignProcessing = false;
                        $('#mass-assign-button-container').show();
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Renderizar tarjeta de asignación individual
     */
    private static function render_individual_assignment_card() {
        ?>
        <div class="card">
            <h3 style="margin-top: 0;">👤 <?php _e('Asignación Individual', 'starter-memberships'); ?></h3>
            <p><?php _e('Asignar membresía por antigüedad a un usuario específico.', 'starter-memberships'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('starter_legacy_single_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="user_id"><?php _e('Usuario', 'starter-memberships'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_users([
                                'name' => 'user_id',
                                'id' => 'user_id',
                                'show_option_none' => __('Seleccionar usuario...', 'starter-memberships'),
                                'option_none_value' => '',
                                'role__in' => ['customer', 'subscriber']
                            ]);
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="duration_days"><?php _e('Duración (días)', 'starter-memberships'); ?></label></th>
                        <td>
                            <input type="number" name="duration_days" id="duration_days" value="365" min="0" class="small-text">
                            <p class="description"><?php _e('0 = sin expiración', 'starter-memberships'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="starter_assign_single" class="button" 
                           value="<?php _e('Asignar Membresía', 'starter-memberships'); ?>">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Renderizar tarjeta de lista de usuarios
     */
    private static function render_users_list_card($stats) {
        $users_with_legacy = Starter_Legacy_Membership_Service::get_users_with_legacy(20);
        ?>
        <div class="card">
            <h3 style="margin-top: 0;">📋 <?php _e('Usuarios con Membresía por Antigüedad', 'starter-memberships'); ?></h3>
            
            <?php if (empty($users_with_legacy)) : ?>
                <p><em><?php _e('No hay usuarios con esta membresía.', 'starter-memberships'); ?></em></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Usuario', 'starter-memberships'); ?></th>
                            <th><?php _e('Email', 'starter-memberships'); ?></th>
                            <th><?php _e('Fecha de Registro', 'starter-memberships'); ?></th>
                            <th><?php _e('Expira', 'starter-memberships'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_with_legacy as $user) : 
                            $user_data = get_userdata($user->user_id);
                            if (!$user_data) continue;
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_user_link($user->user_id); ?>">
                                        <?php echo esc_html($user_data->display_name); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($user_data->user_email); ?></td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($user_data->user_registered)); ?></td>
                                <td>
                                    <?php 
                                    if ($user->end_date) {
                                        $days = ceil((strtotime($user->end_date) - time()) / DAY_IN_SECONDS);
                                        if ($days > 0) {
                                            echo date_i18n(get_option('date_format'), strtotime($user->end_date));
                                            echo ' <small>(' . $days . ' días)</small>';
                                        } else {
                                            echo '<span style="color: #dc3545;">Expirada</span>';
                                        }
                                    } else {
                                        echo '<em>Sin expiración</em>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($stats['with_legacy'] > 20) : ?>
                    <p>
                        <a href="<?php echo admin_url('users.php?membership_level=5'); ?>">
                            <?php printf(__('Ver todos (%d usuarios)', 'starter-memberships'), $stats['with_legacy']); ?>
                        </a>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
