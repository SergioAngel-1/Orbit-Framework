<?php
/**
 * Notificaciones de gestión de usuarios
 * 
 * Este archivo contiene las funciones relacionadas con el envío de notificaciones
 * a usuarios y administradores sobre cambios en el estado de las cuentas.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Envía una notificación al administrador cuando se registra un nuevo usuario
 */
function send_admin_notification_new_user($user_id, $username, $email) {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $subject = "[$site_name] Nuevo usuario pendiente de aprobación";
    
    $admin_url = admin_url('users.php');
    
    $message = "Un nuevo usuario se ha registrado en tu sitio $site_name y está pendiente de aprobación.\n\n";
    $message .= "Nombre de usuario: $username\n";
    $message .= "Correo electrónico: $email\n\n";
    $message .= "Para aprobar o rechazar este usuario, ve a: $admin_url\n";
    
    wp_mail($admin_email, $subject, $message);
}
