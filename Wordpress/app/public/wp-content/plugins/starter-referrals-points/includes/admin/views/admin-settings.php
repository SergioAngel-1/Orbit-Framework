<div class="wrap starter-rp-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="starter-rp-tabs">
        <a href="<?php echo admin_url('admin.php?page=starter-rp-dashboard'); ?>" class="tab">
            <?php _e('Dashboard', 'starter-rp'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="tab">
            <?php _e('Transacciones', 'starter-rp'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="tab">
            <?php _e('Red de Referidos', 'starter-rp'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=starter-rp-settings'); ?>" class="tab active">
            <?php _e('Configuración', 'starter-rp'); ?>
        </a>
    </div>
    
    <?php
    // Cargar las opciones directamente de la base de datos para asegurar que estamos usando los valores más recientes
    $options = get_option('starter_rp_settings', []);
    
    // Valores predeterminados para todas las opciones
    $default_options = [
        'points_conversion_rate' => 0.1,      // 0.1 significa que 10 puntos = $1
        'points_percentage' => 5,             // 5% del valor de compra se convierte en puntos
        'referral_commission_level1' => 10,   // 10% para referidos directos
        'referral_commission_level2' => 5,    // 5% para referidos indirectos
        'points_expiration_days' => 365,      // 1 año de caducidad
        'referral_signup_points' => 100,      // 100 puntos por nuevo referido
    ];
    
    // Combinar opciones guardadas con valores predeterminados
    $options = wp_parse_args($options, $default_options);
    
    // Mostrar mensajes de éxito o error
    if (isset($_GET['settings-updated'])) {
        add_settings_error('starter_rp_messages', 'starter_rp_message', __('Configuración guardada.', 'starter-rp'), 'updated');
    }
    settings_errors('starter_rp_messages');
    ?>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('starter_rp_settings');
        ?>
        
        <div class="starter-rp-settings-container">
            <!-- Pestañas de configuración -->
            <div class="nav-tab-wrapper wp-clearfix">
                <a href="#general-settings" class="nav-tab nav-tab-active"><?php _e('General', 'starter-rp'); ?></a>
                <a href="#points-settings" class="nav-tab"><?php _e('Virtual Coins', 'starter-rp'); ?></a>
                <a href="#referral-settings" class="nav-tab"><?php _e('Referidos', 'starter-rp'); ?></a>
                <a href="#display-settings" class="nav-tab"><?php _e('Visualización', 'starter-rp'); ?></a>
            </div>
            
            <!-- Sección General -->
            <div id="general-settings" class="tab-content active">
                <h2><?php _e('Configuración General del Sistema', 'starter-rp'); ?></h2>
                <p class="description"><?php _e('Estas opciones controlan la activación o desactivación de los módulos principales del sistema.', 'starter-rp'); ?></p>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Sistema de Virtual Coins', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="checkbox" name="starter_rp_settings[enable_points]" value="1" 
                                <?php checked(1, $options['enable_points'] ?? 1); ?> />
                            <p class="description">
                                <?php _e('Cuando esta casilla está <strong>marcada</strong>, el sistema de Virtual Coins está <strong>activo</strong>. Los clientes podrán ganar y canjear Virtual Coins. Si la desactivas, todas las funciones relacionadas con Virtual Coins dejarán de estar disponibles.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Programa de Referidos', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="checkbox" name="starter_rp_settings[enable_referrals]" value="1" 
                                <?php checked(1, $options['enable_referrals'] ?? 1); ?> />
                            <p class="description">
                                <?php _e('Cuando esta casilla está <strong>marcada</strong>, el programa de referidos está <strong>activo</strong>. Los clientes podrán compartir su código de referido y ganar Virtual Coins cuando otros usuarios se registren usando su código. Si la desactivas, todas las funciones de referidos dejarán de estar disponibles.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Roles de usuario participantes', 'starter-rp'); ?>
                        </th>
                        <td>
                            <?php
                            $all_roles = get_editable_roles();
                            $allowed_roles = $options['allowed_roles'] ?? ['customer'];
                            
                            foreach ($all_roles as $role_id => $role_info) {
                                $checked = in_array($role_id, $allowed_roles) ? 'checked' : '';
                                ?>
                                <label>
                                    <input type="checkbox" name="starter_rp_settings[allowed_roles][]" value="<?php echo esc_attr($role_id); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html($role_info['name']); ?>
                                </label><br>
                                <?php
                            }
                            ?>
                            <p class="description">
                                <?php _e('Selecciona los roles de usuario que pueden participar en el programa de Virtual Coins y referidos. <strong>Marca las casillas</strong> de los roles que deseas incluir. Por defecto, solo los clientes ("Customer") pueden participar.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Sección de Virtual Coins -->
            <div id="points-settings" class="tab-content">
                <h2><?php _e('Configuración de Virtual Coins', 'starter-rp'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p><strong><?php _e('¿Cómo funciona el sistema?', 'starter-rp'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php _e('• Los compradores NO ganan puntos directamente por sus compras', 'starter-rp'); ?></li>
                        <li><?php _e('• Los REFERIDORES ganan puntos cuando sus referidos realizan compras', 'starter-rp'); ?></li>
                        <li><?php _e('• Los usuarios pueden canjear sus puntos acumulados por descuentos', 'starter-rp'); ?></li>
                        <li><?php _e('• Los puntos se otorgan por: registro, reseñas, cumpleaños y sistema de referidos', 'starter-rp'); ?></li>
                    </ul>
                </div>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Tasa de conversión de Virtual Coins', 'starter-rp'); ?>
                        </th>
                        <td>
                            <?php
                            // Obtener el valor actual o usar el valor predeterminado
                            $conversion_rate = isset($options['points_conversion_rate']) ? $options['points_conversion_rate'] : 0.1;
                            ?>
                            <input type="number" name="starter_rp_settings[points_conversion_rate]" step="0.01" min="0" 
                                value="<?php echo esc_attr($conversion_rate); ?>" />
                            <p class="description">
                                <?php
                                $vc_name = function_exists('site_get_vc_name') ? site_get_vc_name() : 'Virtual Coin';
                                printf(__('Valor monetario de cada %s cuando se canjean por descuentos (por ejemplo, 0.1 significa que 10 %s = $1).', 'starter-rp'), esc_html($vc_name), esc_html($vc_name));
                                ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Virtual Coins por compra del referido (%)', 'starter-rp'); ?>
                        </th>
                        <td>
                            <?php
                            // Obtener el valor actual o usar el valor predeterminado (ahora es porcentaje)
                            $points_percentage = isset($options['points_percentage']) ? $options['points_percentage'] : 5;
                            ?>
                            <input type="number" name="starter_rp_settings[points_percentage]" step="0.1" min="0" max="100" 
                                value="<?php echo esc_attr($points_percentage); ?>" />%
                            <p class="description">
                                <?php _e('Porcentaje del valor de la compra que recibe el REFERIDOR cuando su referido realiza una compra (ej: 5% de una compra de $100 = 5 Virtual Coins para el referidor).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Mínimo de Virtual Coins para canjear', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[min_points_redemption]" min="0" 
                                value="<?php echo esc_attr($options['min_points_redemption'] ?? 100); ?>" />
                            <p class="description">
                                <?php _e('Cantidad mínima de Virtual Coins que un usuario debe tener antes de poder canjearlos por descuentos en el checkout.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Máximo de Virtual Coins por pedido', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[max_points_per_order]" min="0" 
                                value="<?php echo esc_attr($options['max_points_per_order'] ?? 0); ?>" />
                            <p class="description">
                                <?php _e('Número máximo de Virtual Coins que un usuario puede canjear por descuentos en un solo pedido (0 = sin límite).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Expiración de Virtual Coins', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[points_expiry_days]" min="0" 
                                value="<?php echo esc_attr($options['points_expiry_days'] ?? 365); ?>" />
                            <p class="description">
                                <?php _e('Número de días después de los cuales expiran los Virtual Coins no utilizados (0 = nunca expiran).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Otorgar Virtual Coins por', 'starter-rp'); ?>
                        </th>
                        <td>
                            <?php
                            $point_triggers = [
                                'purchase' => __('Compras', 'starter-rp'),
                                'registration' => __('Registro', 'starter-rp'),
                                'review' => __('Escribir reseñas', 'starter-rp'),
                                'birthday' => __('Cumpleaños', 'starter-rp')
                            ];
                            
                            $enabled_triggers = $options['point_triggers'] ?? ['purchase'];
                            
                            foreach ($point_triggers as $trigger_id => $trigger_name) {
                                $checked = in_array($trigger_id, $enabled_triggers) ? 'checked' : '';
                                ?>
                                <label>
                                    <input type="checkbox" name="starter_rp_settings[point_triggers][]" value="<?php echo esc_attr($trigger_id); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html($trigger_name); ?>
                                </label><br>
                                <?php
                            }
                            ?>
                        </td>
                    </tr>
                    

                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Virtual Coins por reseña', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[points_review]" min="0" 
                                value="<?php echo esc_attr($options['points_review'] ?? 50); ?>" />
                            <p class="description">
                                <?php _e('Cantidad de Virtual Coins otorgados cuando un cliente escribe una reseña de producto.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Virtual Coins por cumpleaños', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[points_birthday]" min="0" 
                                value="<?php echo esc_attr($options['points_birthday'] ?? 200); ?>" />
                            <p class="description">
                                <?php _e('Cantidad de Virtual Coins otorgados automáticamente a un cliente en su fecha de cumpleaños.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Sección de Referidos -->
            <div id="referral-settings" class="tab-content">
                <h2><?php _e('Configuración del Programa de Referidos', 'starter-rp'); ?></h2>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Duración de la comisión', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[referral_commission_duration]" min="0" 
                                value="<?php echo esc_attr($options['referral_commission_duration'] ?? 365); ?>" /> días
                            <p class="description">
                                <?php _e('Número de días durante los cuales un referidor recibe Virtual Coins por las compras que realiza su referido (0 = sin límite de tiempo).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Habilitar referidos de nivel 2', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="checkbox" name="starter_rp_settings[enable_second_level]" value="1" id="enable_second_level"
                                <?php checked(1, $options['enable_second_level'] ?? 0); ?> />
                            <p class="description">
                                <?php _e('Permitir ganar Virtual Coins por referidos de segundo nivel (cuando los usuarios que has referido traen a otros usuarios).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Virtual Coins por referido de primer nivel', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[signup_points_level1]" min="0" 
                                value="<?php echo esc_attr($options['signup_points_level1'] ?? 100); ?>" />
                            <p class="description">
                                <?php _e('Cantidad de Virtual Coins otorgados por cada nuevo usuario que se registra directamente usando tu código de referido (primer nivel).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Virtual Coins por referido de segundo nivel', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[signup_points_level2]" min="0" 
                                value="<?php echo esc_attr($options['signup_points_level2'] ?? 50); ?>" />
                            <p class="description">
                                <?php _e('Cantidad de Virtual Coins otorgados por cada nuevo usuario que se registra usando el código de uno de tus referidos (segundo nivel).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Virtual Coins por registro', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="number" name="starter_rp_settings[points_registration]" min="0" 
                                value="<?php echo esc_attr($options['points_registration'] ?? 100); ?>" />
                            <p class="description">
                                <?php _e('Cantidad de Virtual Coins otorgados cuando un nuevo usuario se registra en la tienda (bono de bienvenida).', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <!-- Comisiones por Membresía -->
                <h3 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    👥 <?php _e('Comisiones por Nivel de Membresía', 'starter-rp'); ?>
                </h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Configura el porcentaje de comisión que reciben los usuarios según su nivel de membresía. <strong>Solo usuarios con membresía (Bronce o superior) pueden participar en el programa de referidos.</strong>', 'starter-rp'); ?>
                </p>
                
                <?php
                // Obtener niveles de membresía si el plugin está activo
                $membership_levels = [];
                if (class_exists('Starter_Memberships')) {
                    $membership_levels = Starter_Memberships::get_all_membership_levels();
                }
                
                $membership_commissions = $options['membership_commissions'] ?? [];
                $enable_second_level = !empty($options['enable_second_level']);
                ?>
                
                <?php if (!empty($membership_levels)) : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead>
                        <tr>
                            <th style="width: 180px;"><?php _e('Nivel de Membresía', 'starter-rp'); ?></th>
                            <th style="text-align: center;"><?php _e('Primera Compra (N1)', 'starter-rp'); ?></th>
                            <th style="text-align: center;"><?php _e('Compras Siguientes (N1)', 'starter-rp'); ?></th>
                            <th style="text-align: center;" class="level2-column"><?php _e('Nivel 2 (Indirectos)', 'starter-rp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Solo niveles 1-5 (Bronce en adelante pueden usar referidos)
                        // Nivel 0 (Zanahoria sin membresía) no tiene acceso al sistema de referidos
                        foreach ($membership_levels as $level_id => $level_info) : 
                            // Saltar nivel 0 - no tiene acceso a referidos
                            if ($level_id == 0) continue;
                            
                            $level_config = $membership_commissions[$level_id] ?? [];
                            
                            // Valores por defecto según nivel (fallbacks si no hay valores guardados)
                            $defaults = [
                                1 => ['first' => 3, 'subsequent' => 1, 'level2' => 0.2],
                                2 => ['first' => 4, 'subsequent' => 2, 'level2' => 0.5],
                                3 => ['first' => 6, 'subsequent' => 3, 'level2' => 1],
                                4 => ['first' => 8, 'subsequent' => 4, 'level2' => 1.5],
                                5 => ['first' => 10, 'subsequent' => 5, 'level2' => 2],
                            ];
                            
                            $default = $defaults[$level_id] ?? $defaults[1];
                            $first_commission = $level_config['first_commission'] ?? $default['first'];
                            $subsequent_commission = $level_config['subsequent_commission'] ?? $default['subsequent'];
                            $level2_commission = $level_config['level2_commission'] ?? $default['level2'];
                        ?>
                        <tr>
                            <td>
                                <span style="color: <?php echo esc_attr($level_info['color']); ?>; font-weight: bold;">
                                    <?php echo esc_html($level_info['icon'] . ' ' . $level_info['name']); ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <input type="number" 
                                       name="starter_rp_settings[membership_commissions][<?php echo esc_attr($level_id); ?>][first_commission]"
                                       value="<?php echo esc_attr($first_commission); ?>"
                                       min="0" max="100" step="0.01"
                                       style="width: 80px; text-align: center;">
                                <span>%</span>
                            </td>
                            <td style="text-align: center;">
                                <input type="number" 
                                       name="starter_rp_settings[membership_commissions][<?php echo esc_attr($level_id); ?>][subsequent_commission]"
                                       value="<?php echo esc_attr($subsequent_commission); ?>"
                                       min="0" max="100" step="0.01"
                                       style="width: 80px; text-align: center;">
                                <span>%</span>
                            </td>
                            <td style="text-align: center;" class="level2-column">
                                <input type="number" 
                                       name="starter_rp_settings[membership_commissions][<?php echo esc_attr($level_id); ?>][level2_commission]"
                                       value="<?php echo esc_attr($level2_commission); ?>"
                                       min="0" max="100" step="0.01"
                                       style="width: 80px; text-align: center;"
                                       class="level2-input"
                                       <?php echo !$enable_second_level ? 'disabled' : ''; ?>>
                                <span>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top: 10px;">
                    <?php _e('Los porcentajes se aplican sobre el valor total de la compra del referido.', 'starter-rp'); ?>
                </p>
                
                <script>
                jQuery(document).ready(function($) {
                    // Habilitar/deshabilitar columna nivel 2 según checkbox
                    $('#enable_second_level').on('change', function() {
                        if ($(this).is(':checked')) {
                            $('.level2-input').prop('disabled', false);
                            $('.level2-column').css('opacity', '1');
                        } else {
                            $('.level2-input').prop('disabled', true);
                            $('.level2-column').css('opacity', '0.5');
                        }
                    });
                    
                    // Estado inicial
                    if (!$('#enable_second_level').is(':checked')) {
                        $('.level2-column').css('opacity', '0.5');
                    }
                });
                </script>
                <?php else : ?>
                <div class="notice notice-warning inline" style="margin: 10px 0;">
                    <p><?php _e('El plugin de Membresías no está activo. Actívalo para configurar las comisiones por nivel de membresía.', 'starter-rp'); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sección de Visualización -->
            <div id="display-settings" class="tab-content">
                <h2><?php _e('Configuración de Visualización', 'starter-rp'); ?></h2>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Habilitar canje de puntos en checkout', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="checkbox" name="starter_rp_settings[display_points_checkout]" value="1" 
                                <?php checked(1, $options['display_points_checkout'] ?? 1); ?> />
                            <p class="description">
                                <?php _e('Permitir que los usuarios canjeen sus Virtual Coins por descuentos durante el proceso de checkout.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Texto para canjear puntos', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="text" name="starter_rp_settings[redeem_points_text]" class="regular-text" 
                                value="<?php echo esc_attr($options['redeem_points_text'] ?? __('Usar mis Virtual Coins disponibles ({points} puntos)', 'starter-rp')); ?>" />
                            <p class="description">
                                <?php _e('Texto mostrado en el checkout para canjear puntos. Use {points} como marcador para el saldo de puntos disponibles.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Texto de saldo insuficiente', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="text" name="starter_rp_settings[insufficient_points_text]" class="regular-text" 
                                value="<?php echo esc_attr($options['insufficient_points_text'] ?? __('Necesitas al menos {min_points} Virtual Coins para canjear. Tienes {current_points} FC.', 'starter-rp')); ?>" />
                            <p class="description">
                                <?php _e('Mensaje mostrado cuando el usuario no tiene suficientes puntos. Use {min_points} y {current_points} como marcadores.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Texto de descuento aplicado', 'starter-rp'); ?>
                        </th>
                        <td>
                            <input type="text" name="starter_rp_settings[discount_applied_text]" class="regular-text" 
                                value="<?php echo esc_attr($options['discount_applied_text'] ?? __('Descuento de {points} Virtual Coins aplicado (-{discount})', 'starter-rp')); ?>" />
                            <p class="description">
                                <?php _e('Texto mostrado cuando se aplica un descuento. Use {points} y {discount} como marcadores.', 'starter-rp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <?php submit_button(); ?>
    </form>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Mostrar/ocultar pestañas de configuración interna
        $('.starter-rp-settings-container .nav-tab-wrapper .nav-tab').on('click', function(e) {
            e.preventDefault();
            
            // Activar pestaña (solo dentro del contenedor de settings)
            $('.starter-rp-settings-container .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Mostrar contenido
            $('.starter-rp-settings-container .tab-content').removeClass('active');
            $($(this).attr('href')).addClass('active');
        });
    });
</script>

<!-- Los estilos ahora se cargan desde el archivo starter-styles.css -->
