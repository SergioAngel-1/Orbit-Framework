<?php
/**
 * Funciones de seguridad para el restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Función para verificar la fortaleza de la contraseña
 * 
 * @param string $password
 * @return array
 */
function starter_check_password_strength($password) {
    $strength = 0;
    $message = '';
    
    // Longitud mínima
    if (strlen($password) < 8) {
        $message = 'La contraseña debe tener al menos 8 caracteres.';
        return ['strength' => $strength, 'message' => $message];
    }
    
    $strength++;
    
    // Verificar si contiene números
    if (preg_match('/\d/', $password)) {
        $strength++;
    } else {
        $message = 'La contraseña debe contener al menos un número.';
        return ['strength' => $strength, 'message' => $message];
    }
    
    // Verificar si contiene letras mayúsculas y minúsculas
    if (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password)) {
        $strength++;
    } else {
        $message = 'La contraseña debe contener letras mayúsculas y minúsculas.';
        return ['strength' => $strength, 'message' => $message];
    }
    
    // Verificar si contiene caracteres especiales
    if (preg_match('/[^a-zA-Z\d]/', $password)) {
        $strength++;
    } else {
        $message = 'La contraseña debe contener al menos un carácter especial.';
        return ['strength' => $strength, 'message' => $message];
    }
    
    return ['strength' => $strength, 'message' => 'Contraseña fuerte'];
}
