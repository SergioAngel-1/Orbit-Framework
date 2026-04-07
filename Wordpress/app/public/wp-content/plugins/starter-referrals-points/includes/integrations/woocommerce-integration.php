<?php
/**
 * Integración con WooCommerce
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar integración con WooCommerce
 */
function starter_rp_init_woocommerce_integration() {
    // Añadir campo de puntos en la página de producto
    add_action('woocommerce_product_options_general_product_data', 'starter_rp_add_product_points_field');
    add_action('woocommerce_process_product_meta', 'starter_rp_save_product_points_field');
    
    // Mostrar puntos en la página de producto
    add_action('woocommerce_before_add_to_cart_button', 'starter_rp_display_product_points');
    
    // Cargar estilos CSS en el frontend
    add_action('wp_enqueue_scripts', 'starter_rp_enqueue_frontend_styles');
    
    // Añadir campo de código de referido en checkout
    add_filter('woocommerce_checkout_fields', 'starter_rp_add_referral_code_field');
    add_action('woocommerce_checkout_update_order_meta', 'starter_rp_save_referral_code_to_order');
    
    // Mostrar puntos en la página Mi Cuenta
    add_action('woocommerce_account_dashboard', 'starter_rp_display_points_in_account');
    
    // Añadir pestaña de Puntos y Referidos en Mi Cuenta
    add_filter('woocommerce_account_menu_items', 'starter_rp_add_account_menu_items');
    add_action('woocommerce_account_puntos-referidos_endpoint', 'starter_rp_account_points_content');
    add_action('init', 'starter_rp_add_endpoints');
    add_filter('query_vars', 'starter_rp_add_query_vars');
    
    // Mostrar puntos ganados en el correo de confirmación
    add_action('woocommerce_email_order_details', 'starter_rp_email_show_points', 10, 4);
}

/**
 * Añadir campo de puntos en el panel de productos
 */
function starter_rp_add_product_points_field() {
    global $post;
    
    echo '<div class="options_group">';
    
    woocommerce_wp_text_input([
        'id' => '_starter_product_points',
        'label' => __('Puntos por compra', 'starter-rp'),
        'description' => __('Puntos que el cliente gana al comprar este producto', 'starter-rp'),
        'desc_tip' => true,
        'type' => 'number',
        'custom_attributes' => [
            'step' => '1',
            'min' => '0'
        ]
    ]);
    
    echo '</div>';
}

/**
 * Guardar campo de puntos del producto
 */
function starter_rp_save_product_points_field($post_id) {
    if (isset($_POST['_starter_product_points'])) {
        $points = absint($_POST['_starter_product_points']);
        update_post_meta($post_id, '_starter_product_points', $points);
    }
}

/**
 * Mostrar puntos en la página de producto
 */
function starter_rp_display_product_points() {
    global $product;
    
    if (!$product) {
        return;
    }
    
    // Verificar si el sistema de puntos está habilitado
    if (!starter_rp_is_points_system_enabled()) {
        return;
    }
    
    // Verificar si el usuario actual puede participar (si está logueado)
    if (is_user_logged_in() && !starter_rp_can_user_use_points()) {
        return;
    }
    
    $points = get_post_meta($product->get_id(), '_starter_product_points', true);
    
    if (!$points) {
        return;
    }
    
    echo '<div class="product-points-info">';
    echo '<div class="points-badge">';
    echo sprintf(
        __('<strong>%d puntos</strong> al comprar este producto', 'starter-rp'),
        (int) $points
    );
    echo '</div>';
    echo '</div>';
    
    // Estilo CSS
    ?>
    <!-- Los estilos ahora se cargan desde el archivo starter-styles.css -->
    <?php
}

/**
 * Añadir campo de código de referido en checkout
 */
function starter_rp_add_referral_code_field($fields) {
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        return $fields;
    }
    
    // Solo mostrar si el usuario no está logueado
    if (is_user_logged_in()) {
        return $fields;
    }
    
    // Verificar si hay un código de referido en la URL
    $ref_from_url = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';
    
    // Verificar si hay un código de referido en la cookie
    $ref_from_cookie = isset($_COOKIE['starter_referral']) ? sanitize_text_field($_COOKIE['starter_referral']) : '';
    
    // Usar el código de la URL si existe, de lo contrario usar el de la cookie
    $ref_code = !empty($ref_from_url) ? $ref_from_url : $ref_from_cookie;
    
    // El campo solo debe ser de solo lectura si el código viene de la URL
    $readonly = !empty($ref_from_url) ? true : false;
    
    $fields['order']['starter_referral_code'] = [
        'type' => 'text',
        'label' => __('Código de referido (opcional)', 'starter-rp'),
        'placeholder' => __('Si tienes un código, ingrésalo aquí', 'starter-rp'),
        'required' => false,
        'class' => ['form-row-wide'],
        'priority' => 120,
        'default' => $ref_code,
        'custom_attributes' => $readonly ? ['readonly' => 'readonly'] : []
    ];
    
    // Añadir un campo oculto para saber si el código vino de la URL
    $fields['order']['starter_referral_code_from_url'] = [
        'type' => 'hidden',
        'default' => !empty($ref_from_url) ? '1' : '0',
        'class' => ['form-row-hidden']
    ];
    
    return $fields;
}

/**
 * Guardar código de referido en el pedido
 */
function starter_rp_save_referral_code_to_order($order_id) {
    if (isset($_POST['starter_referral_code']) && !empty($_POST['starter_referral_code'])) {
        $code = sanitize_text_field($_POST['starter_referral_code']);
        
        // Verificar que el código existe
        global $wpdb;
        $table = $wpdb->prefix . 'starter_referrals';
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $table WHERE referral_code = %s
        ", $code));
        
        if ($exists > 0) {
            update_post_meta($order_id, '_starter_referral_code', $code);
            
            // Si es un pedido para registrar usuario, guardar también en cookie
            if (!is_user_logged_in()) {
                setcookie('starter_referral', $code, time() + (86400 * 30), '/');
            }
        }
    }
}

/**
 * Mostrar puntos en la página de Mi Cuenta
 */
function starter_rp_display_points_in_account() {
    if (!is_user_logged_in()) {
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return;
    }
    
    $user_points = starter_rp_get_user_points($user_id);
    
    if (!$user_points || $user_points['balance'] <= 0) {
        return;
    }
    
    echo '<div class="starter-account-points">';
    echo '<h3>' . __('Mis Puntos', 'starter-rp') . '</h3>';
    echo '<p>' . sprintf(
        __('Tienes <strong>%d puntos</strong> disponibles', 'starter-rp'),
        $user_points['balance']
    ) . '</p>';
    echo '<p><a href="' . wc_get_account_endpoint_url('puntos-referidos') . '" class="button">';
    echo __('Ver mis puntos y referidos', 'starter-rp');
    echo '</a></p>';
    echo '</div>';
    
    // Estilo CSS
    ?>
    <!-- Los estilos ahora se cargan desde el archivo starter-styles.css -->
    <?php
}

/**
 * Añadir pestaña de Puntos y Referidos en Mi Cuenta
 */
function starter_rp_add_account_menu_items($items) {
    // Insertar después de "Pedidos"
    $new_items = [];
    
    foreach ($items as $key => $value) {
        $new_items[$key] = $value;
        
        if ($key === 'orders') {
            $new_items['puntos-referidos'] = __('Puntos y Referidos', 'starter-rp');
        }
    }
    
    return $new_items;
}

/**
 * Añadir endpoint para la pestaña de Puntos y Referidos
 */
function starter_rp_add_endpoints() {
    add_rewrite_endpoint('puntos-referidos', EP_ROOT | EP_PAGES);
}

/**
 * Añadir variable de consulta
 */
function starter_rp_add_query_vars($vars) {
    $vars[] = 'puntos-referidos';
    return $vars;
}

/**
 * Contenido de la página de Puntos y Referidos
 */
function starter_rp_account_points_content() {
    $user_id = get_current_user_id();
    
    // Verificar permisos para ambos sistemas
    $permissions = starter_rp_get_user_system_permissions($user_id);
    
    // Si el usuario no puede usar ningún sistema, mostrar mensaje
    if (!$permissions['can_use_points'] && !$permissions['can_use_referrals']) {
        echo '<div class="woocommerce-info">';
        echo '<p>' . __('No tienes permisos para acceder al sistema de puntos y referidos, o estos sistemas están deshabilitados.', 'starter-rp') . '</p>';
        echo '</div>';
        return;
    }
    
    $user_points = $permissions['can_use_points'] ? starter_rp_get_user_points($user_id) : null;
    $transactions = $permissions['can_use_points'] ? starter_rp_get_user_transactions($user_id, 10) : [];
    $referrals = $permissions['can_use_referrals'] ? starter_rp_get_user_referrals($user_id) : [];
    $referral_code = $permissions['can_use_referrals'] ? starter_rp_get_user_referral_code($user_id) : null;
    // La URL de referido ahora se maneja en el frontend
    
    // Convertir a valor monetario
    $options = Starter_RP()->get_options();
    $conversion_rate = $options['points_conversion_rate'];
    $points_value = $user_points['balance'] / $conversion_rate;
    
    ?>
    <div class="starter-points-dashboard">
        <?php if ($permissions['can_use_points'] && $user_points): ?>
        <!-- Resumen de puntos -->
        <div class="points-summary">
            <h2><?php _e('Mi Saldo de Puntos', 'starter-rp'); ?></h2>
            <div class="points-balance">
                <span class="points-number"><?php echo $user_points['balance']; ?></span>
                <span class="points-label"><?php _e('puntos disponibles', 'starter-rp'); ?></span>
                <?php if ($points_value > 0) : ?>
                <span class="points-value">
                    <?php echo sprintf(__('(Equivalente a %s)', 'starter-rp'), wc_price($points_value)); ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="points-stats">
                <div class="stat">
                    <span class="stat-label"><?php _e('Total ganado:', 'starter-rp'); ?></span>
                    <span class="stat-value"><?php echo $user_points['total_earned']; ?></span>
                </div>
                <div class="stat">
                    <span class="stat-label"><?php _e('Total usado:', 'starter-rp'); ?></span>
                    <span class="stat-value"><?php echo $user_points['used']; ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($permissions['can_use_referrals'] && $referral_code): ?>
        <!-- Compartir referido -->
        <div class="referral-share">
            <h2><?php _e('Invita a tus amigos', 'starter-rp'); ?></h2>
            <p><?php printf(
                __('Invita a tus amigos a unirse usando tu código de referido y gana %d%% en puntos por sus compras.', 'starter-rp'),
                $options['referral_commission_level1']
            ); ?></p>
            
            <div class="referral-code-display">
                <div class="code-label"><?php _e('Tu código de referido:', 'starter-rp'); ?></div>
                <div class="code-value"><?php echo $referral_code; ?></div>
            </div>
            
            <div class="referral-link">
                <div class="link-label"><?php _e('Tu enlace de referido:', 'starter-rp'); ?></div>
                <div class="link-value">
                    <input type="text" readonly value="<?php echo esc_url(add_query_arg('ref', $referral_code, home_url())); ?>" 
                           onClick="this.select();" style="width: 100%;">
                </div>
                <button class="copy-button" onclick="copyToClipboard(this)">
                    <?php _e('Copiar enlace', 'starter-rp'); ?>
                </button>
            </div>
            
            <div class="share-buttons">
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_url); ?>" 
                   target="_blank" class="share-button facebook">
                    <?php _e('Compartir en Facebook', 'starter-rp'); ?>
                </a>
                <a href="https://wa.me/?text=<?php echo urlencode(__('Usa mi código de referido para obtener beneficios: ', 'starter-rp') . $referral_url); ?>" 
                   target="_blank" class="share-button whatsapp">
                    <?php _e('Compartir por WhatsApp', 'starter-rp'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($permissions['can_use_points']): ?>
        <!-- Historial de transacciones -->
        <div class="transactions-history">
            <h2><?php _e('Historial de Transacciones', 'starter-rp'); ?></h2>
            <?php if (empty($transactions)) : ?>
                <p><?php _e('No tienes transacciones de puntos aún.', 'starter-rp'); ?></p>
            <?php else : ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th><?php _e('Fecha', 'starter-rp'); ?></th>
                            <th><?php _e('Tipo', 'starter-rp'); ?></th>
                            <th><?php _e('Puntos', 'starter-rp'); ?></th>
                            <th><?php _e('Descripción', 'starter-rp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction) : ?>
                            <tr>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($transaction->created_at)); ?></td>
                                <td>
                                    <?php 
                                    switch ($transaction->type) {
                                        case 'earned':
                                            _e('Ganado', 'starter-rp');
                                            break;
                                        case 'used':
                                            _e('Usado', 'starter-rp');
                                            break;
                                        case 'expired':
                                            _e('Expirado', 'starter-rp');
                                            break;
                                        case 'referral':
                                            _e('Referido', 'starter-rp');
                                            break;
                                        default:
                                            echo ucfirst($transaction->type);
                                    }
                                    ?>
                                </td>
                                <td class="<?php echo $transaction->points >= 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo $transaction->points > 0 ? '+' . $transaction->points : $transaction->points; ?>
                                </td>
                                <td><?php echo $transaction->description; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($permissions['can_use_referrals']): ?>
        <!-- Mis referidos -->
        <div class="my-referrals">
            <h2><?php _e('Mis Referidos', 'starter-rp'); ?></h2>
            <?php if (empty($referrals)) : ?>
                <p><?php _e('No tienes referidos aún. ¡Comparte tu código y comienza a ganar puntos!', 'starter-rp'); ?></p>
            <?php else : ?>
                <table class="referrals-table">
                    <thead>
                        <tr>
                            <th><?php _e('Nombre', 'starter-rp'); ?></th>
                            <th><?php _e('Fecha', 'starter-rp'); ?></th>
                            <th><?php _e('Nivel', 'starter-rp'); ?></th>
                            <th><?php _e('Puntos generados', 'starter-rp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrals as $referral) : ?>
                            <tr>
                                <td><?php echo $referral['name']; ?></td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($referral['registration_date'])); ?></td>
                                <td>
                                    <?php echo $referral['level'] == 1 ? 
                                        __('Directo', 'starter-rp') : 
                                        __('Indirecto', 'starter-rp'); ?>
                                </td>
                                <td><?php echo $referral['total_points_generated']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Estilos CSS -->
    <!-- Los estilos ahora se cargan desde el archivo starter-styles.css -->
    
    <!-- JavaScript para copiar al portapapeles -->
    <script type="text/javascript">
    function copyToClipboard(button) {
        var input = button.previousElementSibling.querySelector('input');
        input.select();
        document.execCommand('copy');
        
        var originalText = button.textContent;
        button.textContent = '¡Copiado!';
        button.style.backgroundColor = '#4CAF50';
        
        setTimeout(function() {
            button.textContent = originalText;
            button.style.backgroundColor = '#2e7d32';
        }, 2000);
    }
    </script>
    <?php
}

/**
 * Cargar estilos CSS en el frontend de WooCommerce
 */
function starter_rp_enqueue_frontend_styles() {
    // Cargar estilos solo en las páginas de WooCommerce donde se necesitan
    if (is_product() || is_account_page() || is_checkout()) {
        wp_enqueue_style(
            'starter-rp-styles', 
            plugins_url('/assets/css/starter-styles.css', dirname(dirname(__FILE__))),
            array(),
            STARTER_RP_VERSION
        );
    }
}

/**
 * Mostrar puntos ganados en el correo de confirmación
 */
function starter_rp_email_show_points($order, $sent_to_admin, $plain_text, $email) {
    // Solo en correos al cliente y pedidos completados
    if ($sent_to_admin || !$order->has_status('completed')) {
        return;
    }
    
    $points_earned = get_post_meta($order->get_id(), '_starter_points_earned', true);
    
    if (!$points_earned || $points_earned <= 0) {
        return;
    }
    
    if ($plain_text) {
        echo "\n" . sprintf(
            __('Has ganado %d puntos con esta compra.', 'starter-rp'),
            $points_earned
        ) . "\n\n";
    } else {
        echo '<div style="margin-bottom: 40px; padding: 12px; border-left: 4px solid #2e7d32; background-color: #f9f9f9;">';
        echo '<h3 style="margin: 0 0 10px; color: #2e7d32;">' . __('Tus Puntos', 'starter-rp') . '</h3>';
        echo '<p style="margin: 0;">' . sprintf(
            __('Has ganado <strong>%d puntos</strong> con esta compra.', 'starter-rp'),
            $points_earned
        ) . '</p>';
        echo '</div>';
    }
}
