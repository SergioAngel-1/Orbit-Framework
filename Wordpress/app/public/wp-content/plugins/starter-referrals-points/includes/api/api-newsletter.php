<?php
/**
 * API para gestionar suscripciones al newsletter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints para el newsletter
 */
function starter_register_newsletter_endpoints() {
    register_rest_route('starter/v1', '/newsletter/subscribe', array(
        'methods' => 'POST',
        'callback' => 'starter_newsletter_subscribe_endpoint',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'starter_register_newsletter_endpoints');

/**
 * Endpoint para suscribirse al newsletter
 */
function starter_newsletter_subscribe_endpoint($request) {
    // Obtener el correo electrónico del request
    $email = sanitize_email($request->get_param('email'));
    
    // Validar el correo electrónico
    if (!is_email($email)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Por favor, proporciona un correo electrónico válido.'
        ), 400);
    }
    
    // Verificar si el correo ya existe en la lista
    $existing_subscribers = get_option('starter_newsletter_subscribers', array());
    
    if (in_array($email, $existing_subscribers)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Este correo electrónico ya está suscrito a nuestro newsletter.'
        ), 400);
    }
    
    // Agregar el correo a la lista de suscriptores
    $existing_subscribers[] = $email;
    update_option('starter_newsletter_subscribers', $existing_subscribers);
    
    // Registrar la fecha de suscripción
    $subscription_dates = get_option('starter_newsletter_subscription_dates', array());
    $subscription_dates[$email] = current_time('mysql');
    update_option('starter_newsletter_subscription_dates', $subscription_dates);
    
    // Sincronizar con user_meta si el email pertenece a un usuario registrado
    $user = get_user_by('email', $email);
    if ($user) {
        update_user_meta($user->ID, 'newsletter', true);
    }
    
    // Enviar respuesta exitosa
    return new WP_REST_Response(array(
        'success' => true,
        'message' => '¡Gracias por suscribirte a nuestro newsletter!'
    ), 200);
}

/**
 * Agregar página de administración para el newsletter
 */
function starter_add_newsletter_admin_page() {
    // Agregar como menú principal para asegurar que sea visible
    add_menu_page(
        'Newsletter - E-Commerce Template',  // Título de la página
        'Newsletter',               // Título del menú
        'manage_options',           // Capacidad requerida
        'starter-newsletter',     // Slug del menú
        'starter_render_newsletter_admin_page', // Función callback
        'dashicons-email-alt',      // Icono
        30                          // Posición
    );
}
add_action('admin_menu', 'starter_add_newsletter_admin_page');

/**
 * Renderizar la página de administración del newsletter
 */
function starter_render_newsletter_admin_page() {
    // Obtener la lista de suscriptores
    $subscribers = get_option('starter_newsletter_subscribers', array());
    $subscription_dates = get_option('starter_newsletter_subscription_dates', array());
    
    // Manejar la eliminación de suscriptores
    if (isset($_POST['action']) && $_POST['action'] === 'delete_subscriber' && isset($_POST['email']) && check_admin_referer('delete_newsletter_subscriber')) {
        $email_to_delete = sanitize_email($_POST['email']);
        
        // Eliminar el correo de la lista
        $key = array_search($email_to_delete, $subscribers);
        if ($key !== false) {
            unset($subscribers[$key]);
            $subscribers = array_values($subscribers); // Reindexar el array
            update_option('starter_newsletter_subscribers', $subscribers);
            
            // Eliminar la fecha de suscripción
            if (isset($subscription_dates[$email_to_delete])) {
                unset($subscription_dates[$email_to_delete]);
                update_option('starter_newsletter_subscription_dates', $subscription_dates);
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>Suscriptor eliminado correctamente.</p></div>';
        }
    }
    
    // Manejar la exportación de suscriptores
    if (isset($_POST['action']) && $_POST['action'] === 'export_subscribers' && check_admin_referer('export_newsletter_subscribers')) {
        // Generar CSV
        $csv_content = "Email,Fecha de Suscripción\n";
        foreach ($subscribers as $email) {
            $date = isset($subscription_dates[$email]) ? $subscription_dates[$email] : 'N/A';
            $csv_content .= "$email,$date\n";
        }
        
        // Configurar las cabeceras para descargar el archivo
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="newsletter_subscribers_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Enviar el contenido CSV
        echo $csv_content;
        exit;
    }
    
    // Renderizar la interfaz de administración
    ?>
    <div class="wrap">
        <h1>Suscriptores del Newsletter</h1>
        
        <div class="card">
            <h2>Estadísticas</h2>
            <p>Total de suscriptores: <strong><?php echo count($subscribers); ?></strong></p>
            
            <form method="post">
                <?php wp_nonce_field('export_newsletter_subscribers'); ?>
                <input type="hidden" name="action" value="export_subscribers">
                <button type="submit" class="button button-primary">Exportar Suscriptores (CSV)</button>
            </form>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h2>Lista de Suscriptores</h2>
            
            <?php if (empty($subscribers)) : ?>
                <p>No hay suscriptores todavía.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Correo Electrónico</th>
                            <th>Fecha de Suscripción</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscribers as $email) : ?>
                            <tr>
                                <td><?php echo esc_html($email); ?></td>
                                <td><?php echo isset($subscription_dates[$email]) ? esc_html($subscription_dates[$email]) : 'N/A'; ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('delete_newsletter_subscriber'); ?>
                                        <input type="hidden" name="action" value="delete_subscriber">
                                        <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
                                        <button type="submit" class="button button-small" onclick="return confirm('¿Estás seguro de que deseas eliminar este suscriptor?');">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
