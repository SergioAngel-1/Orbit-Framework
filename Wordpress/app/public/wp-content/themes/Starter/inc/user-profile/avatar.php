<?php
/**
 * Avatar de perfil de usuario
 * 
 * Endpoint para subir/eliminar foto de perfil personalizada.
 * Almacena la imagen como attachment de WP y guarda el ID en user_meta.
 * Sobreescribe el Gravatar por defecto cuando existe.
 *
 * Seguridad implementada:
 * - JWT auth requerido (permission_callback is_user_logged_in)
 * - CSRF validado vía starter_get_csrf_protected_endpoints()
 * - Rate limiting: 10 uploads por hora por IP
 * - Validación MIME por magic bytes (finfo)
 * - Validación de extensión con whitelist estricta
 * - Re-procesamiento de imagen con GD para eliminar EXIF/payloads
 * - Límite de tamaño server-side (2MB)
 * - Sanitización de nombre de archivo
 * - Solo el usuario propietario puede modificar su avatar
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

// Rate limiting: máx 10 uploads por hora por IP
if (!defined('STARTER_RATE_LIMIT_AVATAR_ATTEMPTS')) {
    define('STARTER_RATE_LIMIT_AVATAR_ATTEMPTS', 10);
}
if (!defined('STARTER_RATE_LIMIT_AVATAR_WINDOW')) {
    define('STARTER_RATE_LIMIT_AVATAR_WINDOW', 3600);
}

/**
 * Registrar endpoints de avatar
 */
function starter_register_avatar_endpoints() {
    // Subir avatar (multipart/form-data)
    register_rest_route('starter/v1', '/user/profile/avatar', array(
        'methods'  => 'POST',
        'callback' => 'starter_upload_avatar_callback',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));

    // Eliminar avatar
    register_rest_route('starter/v1', '/user/profile/avatar', array(
        'methods'  => 'DELETE',
        'callback' => 'starter_delete_avatar_callback',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));
}
add_action('rest_api_init', 'starter_register_avatar_endpoints');

// NOTA: CSRF ya protegido automáticamente — el middleware en csrf-protection.php
// usa strpos matching contra '/starter/v1/user/profile', que cubre esta ruta.

/**
 * Rate limiting para avatar upload via rest_pre_dispatch
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    $route = $request->get_route();
    $method = $request->get_method();
    
    // Solo para POST en avatar endpoint
    if ($route !== '/starter/v1/user/profile/avatar' || $method !== 'POST') {
        return $result;
    }
    
    if (!function_exists('starter_get_client_ip') || !function_exists('starter_is_ip_blocked')) {
        return $result;
    }
    
    $ip = starter_get_client_ip();
    $block_status = starter_is_ip_blocked($ip, 'avatar');
    
    if ($block_status['blocked']) {
        $resp = new WP_REST_Response(array(
            'success' => false,
            'code'    => 'rate_limit_exceeded',
            'message' => sprintf(
                'Demasiados intentos de subida. Intenta de nuevo en %d minutos.',
                $block_status['minutes']
            ),
            'retry_after' => $block_status['remaining_time'],
        ), 429);
        if (!empty($block_status['remaining_time'])) {
            $resp->header('Retry-After', $block_status['remaining_time']);
        }
        return $resp;
    }
    
    return $result;
}, 8, 3);

/**
 * Mapa de magic bytes por MIME type para validación profunda
 */
function starter_avatar_get_magic_bytes() {
    return array(
        'image/jpeg' => array("\xFF\xD8\xFF"),
        'image/png'  => array("\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"),
        'image/webp' => array("RIFF"),
    );
}

/**
 * Validar que el archivo sea realmente una imagen válida usando magic bytes + GD
 *
 * @param string $file_path Ruta al archivo temporal
 * @param string $mime_type MIME type detectado por finfo
 * @return bool
 */
function starter_avatar_validate_image($file_path, $mime_type) {
    // 1. Verificar magic bytes
    $magic_map = starter_avatar_get_magic_bytes();
    if (!isset($magic_map[$mime_type])) {
        return false;
    }
    
    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        return false;
    }
    $header = fread($handle, 12);
    fclose($handle);
    
    $valid_magic = false;
    foreach ($magic_map[$mime_type] as $magic) {
        if (substr($header, 0, strlen($magic)) === $magic) {
            $valid_magic = true;
            break;
        }
    }
    // WebP tiene "RIFF" al inicio y "WEBP" en offset 8
    if ($mime_type === 'image/webp' && $valid_magic) {
        $valid_magic = (substr($header, 8, 4) === 'WEBP');
    }
    
    if (!$valid_magic) {
        return false;
    }
    
    // 2. Verificar que GD puede leer la imagen (prueba de integridad)
    if (!function_exists('getimagesize')) {
        return true; // Si GD no está disponible, confiar en magic bytes
    }
    
    $image_info = @getimagesize($file_path);
    if ($image_info === false) {
        return false;
    }
    
    // 3. Verificar dimensiones razonables (max 4096x4096)
    if ($image_info[0] > 4096 || $image_info[1] > 4096 || $image_info[0] < 1 || $image_info[1] < 1) {
        return false;
    }
    
    return true;
}

/**
 * Re-procesar imagen para eliminar EXIF, metadatos y posibles payloads incrustados.
 * Genera una imagen limpia desde los píxeles puros.
 *
 * @param string $file_path Ruta al archivo
 * @param string $mime_type MIME type
 * @return bool True si el re-procesamiento fue exitoso
 */
function starter_avatar_reprocess_image($file_path, $mime_type) {
    if (!function_exists('imagecreatefrompng')) {
        return true; // GD no disponible, saltar
    }
    
    $source = null;
    switch ($mime_type) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($file_path);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($file_path);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $source = @imagecreatefromwebp($file_path);
            }
            break;
    }
    
    if (!$source) {
        return false;
    }
    
    // Preservar transparencia para PNG/WebP
    if ($mime_type !== 'image/jpeg') {
        imagealphablending($source, false);
        imagesavealpha($source, true);
    }
    
    // Re-escribir imagen limpia (elimina EXIF, comentarios, payloads)
    $success = false;
    switch ($mime_type) {
        case 'image/jpeg':
            $success = imagejpeg($source, $file_path, 90);
            break;
        case 'image/png':
            $success = imagepng($source, $file_path, 8);
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) {
                $success = imagewebp($source, $file_path, 90);
            }
            break;
    }
    
    imagedestroy($source);
    return $success;
}

/**
 * Callback para subir avatar de perfil
 * Acepta multipart/form-data con campo 'avatar'
 */
function starter_upload_avatar_callback($request) {
    $user_id = get_current_user_id();
    
    // Registrar intento para rate limiting (cuenta cada upload, no solo fallidos)
    if (function_exists('starter_record_failed_attempt')) {
        starter_record_failed_attempt(starter_get_client_ip(), 'avatar');
    }
    
    $files = $request->get_file_params();
    if (empty($files['avatar'])) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No se envió ninguna imagen',
        ), 400);
    }

    $file = $files['avatar'];
    
    // Verificar errores de upload PHP
    if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Error en la subida del archivo',
        ), 400);
    }
    
    // Verificar que el archivo temporal existe y es un upload real
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Archivo inválido',
        ), 400);
    }

    // Validar extensión de archivo (whitelist estricta)
    $allowed_extensions = array('jpg', 'jpeg', 'png', 'webp');
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_extensions, true)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Extensión no permitida. Usa JPG, PNG o WebP.',
        ), 400);
    }
    
    // Validar MIME type real mediante magic bytes (finfo)
    $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $real_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($real_type, $allowed_types, true)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Formato no permitido. Usa JPG, PNG o WebP.',
        ), 400);
    }

    // Validar tamaño (max 2MB, doble check server-side)
    $max_size = 2 * 1024 * 1024;
    $real_size = filesize($file['tmp_name']);
    if ($file['size'] > $max_size || $real_size > $max_size || $real_size === false) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'La imagen es demasiado grande. Máximo 2MB.',
        ), 400);
    }
    
    // Validación profunda: magic bytes + getimagesize + dimensiones
    if (!starter_avatar_validate_image($file['tmp_name'], $real_type)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'El archivo no es una imagen válida.',
        ), 400);
    }
    
    // Re-procesar imagen para eliminar EXIF/metadatos/payloads incrustados
    if (!starter_avatar_reprocess_image($file['tmp_name'], $real_type)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No se pudo procesar la imagen.',
        ), 400);
    }
    
    // Sanitizar nombre de archivo
    $safe_filename = 'avatar-' . $user_id . '-' . time() . '.' . $file_ext;
    $file['name'] = $safe_filename;

    // Requiere funciones de media de WP
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Eliminar avatar anterior si existe
    $old_attachment_id = get_user_meta($user_id, '_custom_avatar_id', true);
    if ($old_attachment_id) {
        wp_delete_attachment(intval($old_attachment_id), true);
    }

    // Subir archivo usando la API de medios de WP
    $upload_overrides = array(
        'test_form' => false,
        'mimes'     => array(
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'webp'     => 'image/webp',
        ),
    );

    $uploaded = wp_handle_upload($file, $upload_overrides);

    if (isset($uploaded['error'])) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Error al subir imagen.',
        ), 500);
    }

    // Crear attachment en la biblioteca de medios
    $attachment = array(
        'guid'           => $uploaded['url'],
        'post_mime_type' => $uploaded['type'],
        'post_title'     => sanitize_file_name('avatar-user-' . $user_id),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => $user_id,
    );

    $attachment_id = wp_insert_attachment($attachment, $uploaded['file']);
    if (is_wp_error($attachment_id)) {
        @unlink($uploaded['file']);
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Error al registrar imagen.',
        ), 500);
    }

    // Generar thumbnails
    $metadata = wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
    wp_update_attachment_metadata($attachment_id, $metadata);

    // Marcar como avatar de usuario para ocultarlo de la biblioteca de medios
    update_post_meta($attachment_id, '_starter_user_avatar', true);

    // Guardar referencia en user_meta
    update_user_meta($user_id, '_custom_avatar_id', $attachment_id);

    // Obtener URL del thumbnail (tamaño 'thumbnail' = 150x150 por defecto)
    $avatar_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
    if (!$avatar_url) {
        $avatar_url = $uploaded['url'];
    }

    update_user_meta($user_id, '_custom_avatar_url', $avatar_url);

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'avatar' => $avatar_url,
        ),
    ), 200);
}

/**
 * Callback para eliminar avatar personalizado
 */
function starter_delete_avatar_callback($request) {
    $user_id = get_current_user_id();

    $attachment_id = get_user_meta($user_id, '_custom_avatar_id', true);
    if ($attachment_id) {
        // Verificar que el attachment pertenece al usuario
        $attachment = get_post(intval($attachment_id));
        if ($attachment && (int) $attachment->post_author === $user_id) {
            wp_delete_attachment(intval($attachment_id), true);
        }
    }

    delete_user_meta($user_id, '_custom_avatar_id');
    delete_user_meta($user_id, '_custom_avatar_url');

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'avatar' => '',
        ),
    ), 200);
}

/**
 * Ocultar avatares de usuario de la biblioteca de medios de WP
 * Filtra la query AJAX que usa la grilla de medios en wp-admin
 */
add_filter('ajax_query_attachments_args', function($query) {
    if (!is_admin()) {
        return $query;
    }

    // Excluir attachments marcados como avatar de usuario
    if (!isset($query['meta_query'])) {
        $query['meta_query'] = array();
    }

    $query['meta_query'][] = array(
        'key'     => '_starter_user_avatar',
        'compare' => 'NOT EXISTS',
    );

    return $query;
});

/**
 * También ocultar de la vista de lista de la biblioteca de medios
 */
add_action('pre_get_posts', function($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'upload.php') {
        return;
    }

    $meta_query = $query->get('meta_query') ?: array();
    $meta_query[] = array(
        'key'     => '_starter_user_avatar',
        'compare' => 'NOT EXISTS',
    );
    $query->set('meta_query', $meta_query);
});

/**
 * Mostrar avatar personalizado en la lista de usuarios de WP Admin
 * Sobreescribe el Gravatar por defecto cuando el usuario tiene avatar custom
 */
add_filter('get_avatar_url', function($url, $id_or_email, $args) {
    $user_id = 0;

    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_string($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        if ($user) {
            $user_id = $user->ID;
        }
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Post) {
        $user_id = (int) $id_or_email->post_author;
    } elseif ($id_or_email instanceof WP_Comment) {
        if (!empty($id_or_email->user_id)) {
            $user_id = (int) $id_or_email->user_id;
        }
    }

    if ($user_id > 0) {
        $custom_url = get_user_meta($user_id, '_custom_avatar_url', true);
        if ($custom_url) {
            $attachment_id = get_user_meta($user_id, '_custom_avatar_id', true);
            if ($attachment_id && get_post($attachment_id)) {
                return $custom_url;
            }
        }
    }

    return $url;
}, 10, 3);

/**
 * Helper: obtener URL del avatar personalizado de un usuario
 * 
 * @param int $user_id
 * @return string URL del avatar o cadena vacía
 */
function starter_get_custom_avatar_url($user_id) {
    $url = get_user_meta($user_id, '_custom_avatar_url', true);
    if ($url) {
        // Verificar que el attachment aún existe
        $attachment_id = get_user_meta($user_id, '_custom_avatar_id', true);
        if ($attachment_id && get_post($attachment_id)) {
            return $url;
        }
        // Limpiar meta huérfano
        delete_user_meta($user_id, '_custom_avatar_id');
        delete_user_meta($user_id, '_custom_avatar_url');
    }
    return '';
}
