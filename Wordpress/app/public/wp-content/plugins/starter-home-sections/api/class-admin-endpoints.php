<?php
/**
 * Endpoints de administración para Home Sections
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIHS_Admin_Endpoints {
    
    /**
     * Crear nueva sección
     */
    public static function create_section($request) {
        $plugin = Starter_Home_Sections::get_instance();
        
        $data = array(
            'layout_type' => $request->get_param('layout_type'),
            'zone' => $request->get_param('zone'),
            'category_id' => $request->get_param('category_id'),
            'title' => $request->get_param('title'),
            'subtitle' => $request->get_param('subtitle'),
            'random' => $request->get_param('random'),
            'order' => $request->get_param('order'),
            'enabled' => $request->get_param('enabled'),
        );
        
        $result = $plugin->create_section($data);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'error' => $result->get_error_message()
            ), 400);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'section_id' => $result
        ), 201);
    }
    
    /**
     * Actualizar sección existente
     */
    public static function update_section($request) {
        $section_id = $request->get_param('section_id');
        $plugin = Starter_Home_Sections::get_instance();
        
        $data = array();
        $allowed_fields = array('layout_type', 'zone', 'category_id', 'title', 'subtitle', 'random', 'order', 'enabled');
        
        foreach ($allowed_fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }
        
        $result = $plugin->update_section($section_id, $data);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'error' => $result->get_error_message()
            ), 400);
        }
        
        return new WP_REST_Response(array('success' => true), 200);
    }
    
    /**
     * Eliminar sección
     */
    public static function delete_section($request) {
        $section_id = $request->get_param('section_id');
        $plugin = Starter_Home_Sections::get_instance();
        
        $result = $plugin->delete_section($section_id);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'error' => $result->get_error_message()
            ), 400);
        }
        
        return new WP_REST_Response(array('success' => true), 200);
    }
    
    /**
     * Endpoint de debug
     */
    public static function debug_sections() {
        $plugin = Starter_Home_Sections::get_instance();
        $legacy_sections = $plugin->get_sections();
        $sections_by_level = get_option('starter_home_sections_by_level', array());
        
        $debug_info = array(
            'legacy_sections_count' => count($legacy_sections),
            'new_format_sections_by_level' => array(),
            'cache_info' => array(
                'cache_keys' => get_option('fihs_cache_keys', array()),
            ),
        );
        
        foreach ($sections_by_level as $level => $level_sections) {
            $debug_info['new_format_sections_by_level'][$level] = array(
                'count' => is_array($level_sections) ? count($level_sections) : 0,
                'sections' => $level_sections,
            );
        }
        
        return new WP_REST_Response($debug_info, 200);
    }
    
    /**
     * Limpiar caché manualmente
     */
    public static function clear_cache() {
        $cache = FIHS_Cache_Manager::get_instance();
        $cache->invalidate();
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Cache invalidado correctamente',
            'timestamp' => current_time('mysql'),
        ), 200);
    }
}
