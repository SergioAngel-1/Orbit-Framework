<?php
/**
 * Manejador de acciones POST para la administración de secciones
 * Sistema de herencia en cascada: niveles inferiores heredan secciones de niveles superiores
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Procesa las acciones POST del formulario
 */
function fihs_process_admin_actions() {
    if (!isset($_POST['action'])) {
        return;
    }
    
    check_admin_referer('starter_home_sections_action');
    
    switch ($_POST['action']) {
        case 'migrate':
            fihs_action_migrate();
            break;
            
        case 'migrate_to_new_format':
            fihs_action_migrate_to_new_format();
            break;
            
        case 'delete_legacy_sections':
            fihs_action_delete_legacy();
            break;
            
        case 'cascade_update':
            fihs_action_cascade_update();
            break;
            
        case 'remove_duplicates':
            fihs_action_remove_duplicates();
            break;
            
        case 'save_sections_by_level':
            fihs_action_save_sections();
            break;
            
        case 'export_config':
            fihs_action_export_config();
            break;
            
        case 'import_config':
            fihs_action_import_config();
            break;
    }
}

/**
 * Acción: Migrar secciones antiguas
 */
function fihs_action_migrate() {
    $result = Starter_Home_Sections_Migration::migrate_old_sections();
    if ($result['success']) {
        echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
    }
}

/**
 * Acción: Migrar al nuevo formato
 */
function fihs_action_migrate_to_new_format() {
    $old_sections = get_option('starter_home_sections_list', array());
    if (empty($old_sections)) {
        return;
    }
    
    $new_sections = get_option('starter_home_sections_by_level', array());
    if (!isset($new_sections[0])) {
        $new_sections[0] = array();
    }
    
    foreach ($old_sections as $section_id => $section_data) {
        $new_sections[0][] = array(
            'id' => $section_id,
            'layout_type' => $section_data['layout_type'] ?? 'horizontal',
            'zone' => $section_data['zone'] ?? 'middle',
            'category_id' => intval($section_data['category_id'] ?? 0),
            'title' => $section_data['title'] ?? '',
            'subtitle' => $section_data['subtitle'] ?? '',
            'random' => !empty($section_data['random']),
            'order' => intval($section_data['order'] ?? 0),
            'enabled' => $section_data['enabled'] ?? true,
        );
    }
    
    update_option('starter_home_sections_by_level', $new_sections);
    delete_option('starter_home_sections_list');
    do_action('starter_home_sections_changed');
    
    echo '<div class="notice notice-success is-dismissible"><p>Se migraron ' . count($old_sections) . ' secciones al nuevo formato (nivel Zanahoria/Público).</p></div>';
}

/**
 * Acción: Eliminar secciones legacy
 */
function fihs_action_delete_legacy() {
    delete_option('starter_home_sections_list');
    do_action('starter_home_sections_changed');
    echo '<div class="notice notice-success is-dismissible"><p>Secciones del formato antiguo eliminadas.</p></div>';
}

/**
 * Acción: Actualizar en cascada
 * Propaga secciones de niveles INFERIORES a SUPERIORES
 * Las secciones creadas en niveles bajos (ej: Zanahoria nivel 0) se heredan a niveles superiores
 * respetando los permisos de categoría (si la categoría requiere nivel mínimo, no se propaga a niveles inferiores a ese)
 */
function fihs_action_cascade_update() {
    $current_sections = get_option('starter_home_sections_by_level', array());
    $propagated_count = 0;
    
    // Recopilar secciones ORIGINALES (no propagadas) por nivel
    $original_sections = array();
    
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        $original_sections[$lvl] = array();
        
        if (isset($current_sections[$lvl]) && is_array($current_sections[$lvl])) {
            foreach ($current_sections[$lvl] as $section) {
                // Solo considerar secciones originales (no propagadas)
                if (empty($section['cascaded_from'])) {
                    $original_sections[$lvl][] = $section;
                }
            }
        }
    }
    
    // Limpiar secciones propagadas anteriormente y reconstruir
    $new_sections = array();
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        $new_sections[$lvl] = $original_sections[$lvl];
    }
    
    // Propagar desde niveles INFERIORES hacia SUPERIORES
    // Nivel 0 (Zanahoria) → Nivel 5 (Antigüedad)
    for ($source_level = 0; $source_level <= 4; $source_level++) {
        foreach ($original_sections[$source_level] as $section) {
            $category_id = intval($section['category_id']);
            if ($category_id <= 0) continue;
            
            // Obtener el nivel mínimo de membresía de la categoría
            $cat_min_level = intval(get_term_meta($category_id, '_min_membership_level', true));
            
            // Propagar a niveles SUPERIORES
            for ($target_level = $source_level + 1; $target_level <= 5; $target_level++) {
                // Solo propagar si el nivel destino puede ver esta categoría
                // (el nivel destino debe ser >= al nivel mínimo de la categoría)
                if ($target_level < $cat_min_level) {
                    continue;
                }
                
                // Verificar si ya existe una sección con esta categoría en el nivel destino
                $category_exists = false;
                foreach ($new_sections[$target_level] as $existing) {
                    if (intval($existing['category_id']) === $category_id) {
                        $category_exists = true;
                        break;
                    }
                }
                
                // Si no existe, agregarla
                if (!$category_exists) {
                    $new_section = $section;
                    $new_section['id'] = $section['id'] . '_cascade_' . $target_level;
                    $new_section['cascaded_from'] = $source_level;
                    $new_sections[$target_level][] = $new_section;
                    $propagated_count++;
                }
            }
        }
    }
    
    // Guardar las secciones actualizadas
    update_option('starter_home_sections_by_level', $new_sections);
    do_action('starter_home_sections_changed');
    
    if ($propagated_count > 0) {
        echo '<div class="notice notice-success is-dismissible"><p>Se propagaron ' . $propagated_count . ' secciones en cascada a los niveles superiores.</p></div>';
    } else {
        echo '<div class="notice notice-info is-dismissible"><p>No hay secciones nuevas para propagar. Las secciones ya están sincronizadas.</p></div>';
    }
}

/**
 * Acción: Eliminar secciones duplicadas
 * Mantiene solo una sección por category_id, priorizando el nivel más alto
 * Cruza entre todos los niveles para eliminar duplicados globales
 */
function fihs_action_remove_duplicates() {
    $current_sections = get_option('starter_home_sections_by_level', array());
    $removed_count = 0;
    
    // Paso 1: Recopilar todas las categorías que existen en cada nivel
    // Priorizar niveles superiores (5 → 0)
    $global_categories = array(); // category_id => nivel donde se mantiene
    
    // Primero, identificar en qué nivel más alto existe cada categoría
    for ($lvl = 5; $lvl >= 0; $lvl--) {
        if (!isset($current_sections[$lvl]) || !is_array($current_sections[$lvl])) {
            continue;
        }
        
        foreach ($current_sections[$lvl] as $section) {
            $category_id = intval($section['category_id']);
            if ($category_id <= 0) continue;
            
            // Si esta categoría no está registrada, este es su nivel más alto
            if (!isset($global_categories[$category_id])) {
                $global_categories[$category_id] = $lvl;
            }
        }
    }
    
    // Paso 2: Reconstruir secciones, eliminando duplicados
    // Mantener solo la sección en el nivel más alto donde aparece
    $new_sections = array();
    
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        $new_sections[$lvl] = array();
        $seen_in_level = array(); // Para duplicados dentro del mismo nivel
        
        if (!isset($current_sections[$lvl]) || !is_array($current_sections[$lvl])) {
            continue;
        }
        
        foreach ($current_sections[$lvl] as $section) {
            $category_id = intval($section['category_id']);
            if ($category_id <= 0) continue;
            
            // Si esta categoría ya existe en un nivel SUPERIOR, eliminarla de este nivel
            if (isset($global_categories[$category_id]) && $global_categories[$category_id] > $lvl) {
                $removed_count++;
                continue;
            }
            
            // Si ya vimos esta categoría en ESTE nivel, es duplicado interno
            if (in_array($category_id, $seen_in_level)) {
                $removed_count++;
                continue;
            }
            
            $seen_in_level[] = $category_id;
            $new_sections[$lvl][] = $section;
        }
    }
    
    update_option('starter_home_sections_by_level', $new_sections);
    do_action('starter_home_sections_changed');
    
    if ($removed_count > 0) {
        echo '<div class="notice notice-success is-dismissible"><p>Se eliminaron ' . $removed_count . ' secciones duplicadas (se mantuvieron en el nivel más alto).</p></div>';
    } else {
        echo '<div class="notice notice-info is-dismissible"><p>No se encontraron secciones duplicadas.</p></div>';
    }
}

/**
 * Acción: Guardar secciones por nivel
 */
function fihs_action_save_sections() {
    $sections_by_level = isset($_POST['sections_by_level']) ? $_POST['sections_by_level'] : array();
    $clean_sections = array();
    
    foreach ($sections_by_level as $level => $sections) {
        $level = intval($level);
        $clean_sections[$level] = array();
        
        if (is_array($sections)) {
            foreach ($sections as $section) {
                $clean_sections[$level][] = array(
                    'id' => sanitize_text_field($section['id'] ?? uniqid('section_')),
                    'layout_type' => sanitize_text_field($section['layout_type'] ?? 'horizontal'),
                    'zone' => sanitize_text_field($section['zone'] ?? 'middle'),
                    'category_id' => intval($section['category_id'] ?? 0),
                    'category_id_2' => intval($section['category_id_2'] ?? 0),
                    'title' => sanitize_text_field($section['title'] ?? ''),
                    'subtitle' => sanitize_text_field($section['subtitle'] ?? ''),
                    'title_en' => sanitize_text_field($section['title_en'] ?? ''),
                    'subtitle_en' => sanitize_text_field($section['subtitle_en'] ?? ''),
                    'title_2' => sanitize_text_field($section['title_2'] ?? ''),
                    'subtitle_2' => sanitize_text_field($section['subtitle_2'] ?? ''),
                    'title_2_en' => sanitize_text_field($section['title_2_en'] ?? ''),
                    'subtitle_2_en' => sanitize_text_field($section['subtitle_2_en'] ?? ''),
                    'random' => isset($section['random']) && $section['random'] === '1',
                    'order' => intval($section['order'] ?? 0),
                    'enabled' => isset($section['enabled']) && $section['enabled'] === '1',
                );
            }
        }
    }
    
    update_option('starter_home_sections_by_level', $clean_sections);
    
    // Guardar exclusiones por nivel
    $excluded_by_level = isset($_POST['excluded_sections']) ? $_POST['excluded_sections'] : array();
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        delete_option('starter_excluded_sections_level_' . $lvl);
    }
    foreach ($excluded_by_level as $level => $excluded) {
        $level = intval($level);
        $clean_excluded = array_map('sanitize_text_field', $excluded);
        update_option('starter_excluded_sections_level_' . $level, $clean_excluded);
    }
    
    // Invalidar cache
    do_action('starter_home_sections_changed');
    
    echo '<div class="notice notice-success is-dismissible"><p>Secciones guardadas correctamente.</p></div>';
}

/**
 * Acción: Exportar configuración de secciones a JSON
 */
function fihs_action_export_config() {
    // Recopilar toda la configuración
    $config = array(
        'version' => FIHS_VERSION,
        'exported_at' => current_time('mysql'),
        'sections_by_level' => get_option('starter_home_sections_by_level', array()),
        'legacy_sections' => get_option('starter_home_sections_list', array()),
        'excluded_sections' => array(),
    );
    
    // Recopilar exclusiones por nivel
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        $excluded = get_option('starter_excluded_sections_level_' . $lvl, array());
        if (!empty($excluded)) {
            $config['excluded_sections'][$lvl] = $excluded;
        }
    }
    
    // Generar nombre de archivo
    $filename = 'starter-home-sections-' . date('Y-m-d-His') . '.json';
    
    // Enviar headers para descarga
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Acción: Importar configuración de secciones desde JSON
 */
function fihs_action_import_config() {
    // Verificar que se subió un archivo
    if (!isset($_FILES['config_file']) || $_FILES['config_file']['error'] !== UPLOAD_ERR_OK) {
        echo '<div class="notice notice-error is-dismissible"><p>Error: No se pudo cargar el archivo. Por favor, selecciona un archivo JSON válido.</p></div>';
        return;
    }
    
    $file = $_FILES['config_file'];
    
    // Verificar extensión
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'json') {
        echo '<div class="notice notice-error is-dismissible"><p>Error: El archivo debe ser de tipo JSON.</p></div>';
        return;
    }
    
    // Leer contenido del archivo
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        echo '<div class="notice notice-error is-dismissible"><p>Error: No se pudo leer el archivo.</p></div>';
        return;
    }
    
    // Decodificar JSON
    $config = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo '<div class="notice notice-error is-dismissible"><p>Error: El archivo JSON no es válido. ' . esc_html(json_last_error_msg()) . '</p></div>';
        return;
    }
    
    // Validar estructura básica
    if (!isset($config['sections_by_level']) || !is_array($config['sections_by_level'])) {
        echo '<div class="notice notice-error is-dismissible"><p>Error: El archivo no contiene una configuración válida de secciones.</p></div>';
        return;
    }
    
    // Importar secciones por nivel
    $sections_count = 0;
    $clean_sections = array();
    
    foreach ($config['sections_by_level'] as $level => $sections) {
        $level = intval($level);
        if ($level < 0 || $level > 5) continue;
        
        $clean_sections[$level] = array();
        
        if (is_array($sections)) {
            foreach ($sections as $section) {
                $clean_sections[$level][] = array(
                    'id' => sanitize_text_field($section['id'] ?? uniqid('section_')),
                    'layout_type' => sanitize_text_field($section['layout_type'] ?? 'horizontal'),
                    'zone' => sanitize_text_field($section['zone'] ?? 'middle'),
                    'category_id' => intval($section['category_id'] ?? 0),
                    'category_id_2' => intval($section['category_id_2'] ?? 0),
                    'title' => sanitize_text_field($section['title'] ?? ''),
                    'subtitle' => sanitize_text_field($section['subtitle'] ?? ''),
                    'title_en' => sanitize_text_field($section['title_en'] ?? ''),
                    'subtitle_en' => sanitize_text_field($section['subtitle_en'] ?? ''),
                    'title_2' => sanitize_text_field($section['title_2'] ?? ''),
                    'subtitle_2' => sanitize_text_field($section['subtitle_2'] ?? ''),
                    'title_2_en' => sanitize_text_field($section['title_2_en'] ?? ''),
                    'subtitle_2_en' => sanitize_text_field($section['subtitle_2_en'] ?? ''),
                    'random' => !empty($section['random']),
                    'order' => intval($section['order'] ?? 0),
                    'enabled' => isset($section['enabled']) ? (bool)$section['enabled'] : true,
                );
                $sections_count++;
            }
        }
    }
    
    update_option('starter_home_sections_by_level', $clean_sections);
    
    // Importar exclusiones si existen
    if (isset($config['excluded_sections']) && is_array($config['excluded_sections'])) {
        // Limpiar exclusiones anteriores
        for ($lvl = 0; $lvl <= 5; $lvl++) {
            delete_option('starter_excluded_sections_level_' . $lvl);
        }
        
        foreach ($config['excluded_sections'] as $level => $excluded) {
            $level = intval($level);
            if ($level < 0 || $level > 5) continue;
            
            if (is_array($excluded)) {
                $clean_excluded = array_map('sanitize_text_field', $excluded);
                update_option('starter_excluded_sections_level_' . $level, $clean_excluded);
            }
        }
    }
    
    // Invalidar cache
    do_action('starter_home_sections_changed');
    
    $version_info = isset($config['version']) ? ' (v' . esc_html($config['version']) . ')' : '';
    $date_info = isset($config['exported_at']) ? ' del ' . esc_html($config['exported_at']) : '';
    
    echo '<div class="notice notice-success is-dismissible"><p>Configuración importada correctamente' . $version_info . $date_info . '. Se importaron ' . $sections_count . ' secciones.</p></div>';
}
