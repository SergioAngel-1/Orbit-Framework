<?php
/**
 * Página de Configuración de Membresías
 * 
 * Contiene las herramientas administrativas para gestión masiva de membresías:
 * - Zona de Peligro: Eliminar todas las membresías
 * - Congelar Membresías: Congelar/descongelar masiva e individualmente
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Pages
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Settings_Page {
    
    /**
     * Renderizar página de configuración
     */
    public static function render() {
        ?>
        <div class="wrap">
            <h1>⚙️ <?php _e('Configuración de Membresías', 'starter-memberships'); ?></h1>
            
            <p class="description">
                <?php _e('Herramientas administrativas para gestión masiva de membresías.', 'starter-memberships'); ?>
            </p>
            
            <?php self::render_danger_zone(); ?>
            <?php self::render_freeze_section(); ?>
        </div>
        <?php
    }
    
    /**
     * Renderizar zona de peligro
     */
    private static function render_danger_zone() {
        ?>
        <div class="card" style="margin-top: 30px; border-left: 4px solid #dc3545; max-width: 800px;">
            <h3 style="margin-top: 0; color: #dc3545;">⚠️ <?php _e('Zona de Peligro', 'starter-memberships'); ?></h3>
            
            <h4 style="margin-top: 20px;">🗑️ <?php _e('Eliminar Todas las Membresías', 'starter-memberships'); ?></h4>
            <p><?php _e('Elimina todas las membresías activas excepto las de administradores. Esta acción no se puede deshacer.', 'starter-memberships'); ?></p>
            
            <div id="delete-all-progress-container" style="display: none; margin: 20px 0;">
                <div style="background: #f0f0f0; border-radius: 5px; overflow: hidden; height: 30px; position: relative;">
                    <div id="delete-all-progress-bar" style="background: #dc3545; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    <span id="delete-all-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold;">0%</span>
                </div>
                <p id="delete-all-progress-status" style="margin-top: 10px;">Iniciando...</p>
            </div>
            
            <p class="submit" id="delete-all-button-container">
                <button type="button" id="delete-all-memberships" class="button" style="background: #dc3545; border-color: #dc3545; color: white;">
                    <?php _e('Eliminar Todas las Membresías', 'starter-memberships'); ?>
                </button>
                <span class="description" style="margin-left: 10px;"><?php _e('Procesa en lotes de 100', 'starter-memberships'); ?></span>
            </p>
        </div>
        
        <?php self::render_delete_script(); ?>
        <?php
    }
    
    /**
     * Renderizar script de eliminación
     */
    private static function render_delete_script() {
        ?>
        <script>
        jQuery(function($) {
            var deleteAllProcessing = false;
            var deleteAllNonce = '<?php echo wp_create_nonce('starter_delete_all_memberships_nonce'); ?>';
            
            $('#delete-all-memberships').on('click', function() {
                if (deleteAllProcessing) return;
                
                if (!confirm('⚠️ ADVERTENCIA: Esta acción eliminará TODAS las membresías activas (excepto administradores).\n\n¿Estás seguro de que deseas continuar?')) {
                    return;
                }
                
                if (!confirm('⚠️ ÚLTIMA CONFIRMACIÓN: Esta acción NO se puede deshacer.\n\n¿Realmente deseas eliminar todas las membresías?')) {
                    return;
                }
                
                deleteAllProcessing = true;
                $('#delete-all-button-container').hide();
                $('#delete-all-progress-container').show();
                
                processDeleteBatch(true);
            });
            
            function processDeleteBatch(isFirst) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'starter_delete_all_memberships',
                        nonce: deleteAllNonce,
                        reset: isFirst ? '1' : '0'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var percent = data.total > 0 ? Math.round((data.total_deleted / data.total) * 100) : 100;
                            
                            $('#delete-all-progress-bar').css('width', percent + '%');
                            $('#delete-all-progress-text').text(percent + '%');
                            $('#delete-all-progress-status').html(
                                'Eliminadas: <strong>' + data.total_deleted + '</strong> de <strong>' + data.total + '</strong>'
                            );
                            
                            if (!data.is_complete) {
                                setTimeout(function() { processDeleteBatch(false); }, 500);
                            } else {
                                $('#delete-all-progress-status').html(
                                    '<span style="color: green; font-weight: bold;">✅ ¡Completado!</span> ' +
                                    'Eliminadas: <strong>' + data.total_deleted + '</strong> membresías'
                                );
                                setTimeout(function() { location.reload(); }, 2000);
                            }
                        } else {
                            $('#delete-all-progress-status').html('<span style="color: red;">Error: ' + (response.data?.message || 'Error desconocido') + '</span>');
                            deleteAllProcessing = false;
                            $('#delete-all-button-container').show();
                        }
                    },
                    error: function() {
                        $('#delete-all-progress-status').html('<span style="color: red;">Error de conexión. Intenta de nuevo.</span>');
                        deleteAllProcessing = false;
                        $('#delete-all-button-container').show();
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Renderizar sección de congelar
     */
    private static function render_freeze_section() {
        ?>
        <div class="card" style="margin-top: 20px; border-left: 4px solid #0073aa; max-width: 800px;">
            <h3 style="margin-top: 0; color: #0073aa;">❄️ <?php _e('Congelar Membresías', 'starter-memberships'); ?></h3>
            <p><?php _e('Congela membresías activas. Los usuarios con membresía congelada serán tratados como nivel 0 (Zanahoria) hasta que se descongelen.', 'starter-memberships'); ?></p>
            
            <div id="freeze-mass-progress-container" style="display: none; margin-bottom: 20px;">
                <div style="background: #f0f0f0; border-radius: 5px; overflow: hidden; height: 30px; position: relative;">
                    <div id="freeze-mass-progress-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    <span id="freeze-mass-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold;">0%</span>
                </div>
                <p id="freeze-mass-progress-status" style="margin-top: 10px;">Procesando...</p>
            </div>
            
            <div id="freeze-mass-button-container" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 20px;">
                <button type="button" id="freeze-all-memberships" class="button button-primary" style="background: #0073aa; border-color: #0073aa;">
                    ❄️ <?php _e('Congelar Todas', 'starter-memberships'); ?>
                </button>
                <button type="button" id="unfreeze-all-memberships" class="button">
                    🔥 <?php _e('Descongelar Todas', 'starter-memberships'); ?>
                </button>
                <a href="<?php echo admin_url('users.php?membership_level=frozen'); ?>" class="button">
                    👁️ <?php _e('Ver Congelados', 'starter-memberships'); ?>
                </a>
            </div>
            <p class="description" style="margin-bottom: 20px;"><?php _e('Procesa en lotes de 100 (excepto administradores)', 'starter-memberships'); ?></p>
            
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">
            
            <h4 style="margin: 0 0 10px 0;"><?php _e('Congelar/Descongelar Individual', 'starter-memberships'); ?></h4>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <?php
                wp_dropdown_users([
                    'name' => 'freeze_user_id',
                    'id' => 'freeze_user_id',
                    'show_option_none' => __('Seleccionar usuario...', 'starter-memberships'),
                    'option_none_value' => '',
                    'role__in' => ['customer', 'subscriber']
                ]);
                ?>
                <button type="button" id="freeze-single-user" class="button">
                    ❄️ <?php _e('Congelar', 'starter-memberships'); ?>
                </button>
                <button type="button" id="unfreeze-single-user" class="button">
                    🔥 <?php _e('Descongelar', 'starter-memberships'); ?>
                </button>
            </div>
            <p id="freeze-single-status" style="margin-top: 10px;"></p>
        </div>
        
        <?php self::render_freeze_script(); ?>
        <?php
    }
    
    /**
     * Renderizar script de congelar
     */
    private static function render_freeze_script() {
        ?>
        <script>
        jQuery(function($) {
            var freezeNonce = '<?php echo wp_create_nonce('starter_freeze_memberships_nonce'); ?>';
            var freezeProcessing = false;
            
            $('#freeze-all-memberships').on('click', function() {
                if (freezeProcessing) return;
                if (!confirm('¿Congelar TODAS las membresías activas (excepto administradores)?')) return;
                
                freezeProcessing = true;
                $('#freeze-mass-button-container').hide();
                $('#freeze-mass-progress-container').show();
                processFreezeUnfreezeBatch('freeze', true);
            });
            
            $('#unfreeze-all-memberships').on('click', function() {
                if (freezeProcessing) return;
                if (!confirm('¿Descongelar TODAS las membresías congeladas?')) return;
                
                freezeProcessing = true;
                $('#freeze-mass-button-container').hide();
                $('#freeze-mass-progress-container').show();
                processFreezeUnfreezeBatch('unfreeze', true);
            });
            
            function processFreezeUnfreezeBatch(action, isFirst) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'starter_freeze_memberships_batch',
                        nonce: freezeNonce,
                        freeze_action: action,
                        reset: isFirst ? '1' : '0'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 100;
                            
                            $('#freeze-mass-progress-bar').css('width', percent + '%');
                            $('#freeze-mass-progress-text').text(percent + '%');
                            $('#freeze-mass-progress-status').html(
                                'Procesadas: <strong>' + data.processed + '</strong> de <strong>' + data.total + '</strong>'
                            );
                            
                            if (!data.is_complete) {
                                setTimeout(function() { processFreezeUnfreezeBatch(action, false); }, 500);
                            } else {
                                var actionText = action === 'freeze' ? 'congeladas' : 'descongeladas';
                                $('#freeze-mass-progress-status').html(
                                    '<span style="color: green; font-weight: bold;">✅ ¡Completado!</span> ' +
                                    'Membresías ' + actionText + ': <strong>' + data.processed + '</strong>'
                                );
                                setTimeout(function() { location.reload(); }, 2000);
                            }
                        } else {
                            $('#freeze-mass-progress-status').html('<span style="color: red;">Error: ' + (response.data?.message || 'Error') + '</span>');
                            freezeProcessing = false;
                            $('#freeze-mass-button-container').show();
                        }
                    },
                    error: function() {
                        $('#freeze-mass-progress-status').html('<span style="color: red;">Error de conexión</span>');
                        freezeProcessing = false;
                        $('#freeze-mass-button-container').show();
                    }
                });
            }
            
            $('#freeze-single-user').on('click', function() {
                var userId = $('#freeze_user_id').val();
                if (!userId) { alert('Selecciona un usuario'); return; }
                
                $.post(ajaxurl, {
                    action: 'starter_freeze_single_membership',
                    nonce: freezeNonce,
                    user_id: userId,
                    freeze_action: 'freeze'
                }, function(response) {
                    if (response.success) {
                        $('#freeze-single-status').html('<span style="color: green;">✅ Membresía congelada</span>');
                    } else {
                        $('#freeze-single-status').html('<span style="color: red;">Error: ' + (response.data?.message || 'Error') + '</span>');
                    }
                });
            });
            
            $('#unfreeze-single-user').on('click', function() {
                var userId = $('#freeze_user_id').val();
                if (!userId) { alert('Selecciona un usuario'); return; }
                
                $.post(ajaxurl, {
                    action: 'starter_freeze_single_membership',
                    nonce: freezeNonce,
                    user_id: userId,
                    freeze_action: 'unfreeze'
                }, function(response) {
                    if (response.success) {
                        $('#freeze-single-status').html('<span style="color: green;">✅ Membresía descongelada</span>');
                    } else {
                        $('#freeze-single-status').html('<span style="color: red;">Error: ' + (response.data?.message || 'Error') + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
