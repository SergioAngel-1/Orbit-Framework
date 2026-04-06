<?php
/**
 * Clase para migrar datos del formato antiguo al nuevo
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class Starter_Home_Sections_Migration {
    
    /**
     * Migrar secciones del formato antiguo al nuevo
     */
    public static function migrate_old_sections() {
        // Verificar si ya se migró
        if (get_option('starter_home_sections_migrated', false)) {
            return array('success' => false, 'message' => 'Las secciones ya fueron migradas');
        }
        
        $old_options = get_option('starter_home_sections_options', array());
        
        if (empty($old_options)) {
            return array('success' => false, 'message' => 'No hay secciones antiguas para migrar');
        }
        
        $plugin = Starter_Home_Sections::get_instance();
        $new_sections = array();
        
        // Mapeo de secciones antiguas a nuevas
        $section_mapping = array(
            'section_top_1' => array(
                'layout_type' => 'standard',
                'zone' => 'top',
                'order' => 0
            ),
            'section_top_2' => array(
                'layout_type' => 'horizontal',
                'zone' => 'top',
                'order' => 1
            ),
            'section_middle_1' => array(
                'layout_type' => 'standard',
                'zone' => 'middle',
                'order' => 0
            ),
            'section_middle_2' => array(
                'layout_type' => 'horizontal',
                'zone' => 'middle',
                'order' => 1
            ),
            'section_bottom_1' => array(
                'layout_type' => 'compact',
                'zone' => 'bottom',
                'order' => 0
            ),
            'section_bottom_2' => array(
                'layout_type' => 'compact',
                'zone' => 'bottom',
                'order' => 1
            ),
        );
        
        foreach ($section_mapping as $old_id => $config) {
            $category_id = isset($old_options[$old_id . '_category']) ? $old_options[$old_id . '_category'] : '';
            
            // Saltar secciones sin categoría
            if (empty($category_id)) {
                continue;
            }
            
            $new_sections[$old_id] = array(
                'id' => $old_id,
                'layout_type' => $config['layout_type'],
                'zone' => $config['zone'],
                'category_id' => intval($category_id),
                'title' => isset($old_options[$old_id . '_title']) ? $old_options[$old_id . '_title'] : '',
                'subtitle' => isset($old_options[$old_id . '_subtitle']) ? $old_options[$old_id . '_subtitle'] : '',
                'random' => isset($old_options[$old_id . '_random']) && $old_options[$old_id . '_random'] == '1',
                'order' => $config['order'],
                'enabled' => true,
            );
        }
        
        // Guardar las nuevas secciones
        update_option('starter_home_sections_list', $new_sections);
        
        // Marcar como migrado
        update_option('starter_home_sections_migrated', true);
        
        // Hacer backup de las opciones antiguas
        update_option('starter_home_sections_options_backup', $old_options);
        
        return array(
            'success' => true, 
            'message' => 'Migración completada exitosamente',
            'sections_migrated' => count($new_sections)
        );
    }
    
    /**
     * Revertir migración (restaurar formato antiguo)
     */
    public static function rollback_migration() {
        $backup = get_option('starter_home_sections_options_backup', array());
        
        if (empty($backup)) {
            return array('success' => false, 'message' => 'No hay backup disponible');
        }
        
        // Restaurar opciones antiguas
        update_option('starter_home_sections_options', $backup);
        
        // Limpiar nuevas secciones
        delete_option('starter_home_sections_list');
        
        // Marcar como no migrado
        delete_option('starter_home_sections_migrated');
        
        return array('success' => true, 'message' => 'Migración revertida exitosamente');
    }
}
