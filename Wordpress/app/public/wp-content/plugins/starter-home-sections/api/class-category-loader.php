<?php
/**
 * Cargador de categorías optimizado para Home Sections
 * Evita N+1 queries precargando categorías en batch
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIHS_Category_Loader {
    
    /**
     * Precarga categorías y sus metas para evitar N+1 queries
     * 
     * @param array $sections_by_level Array de secciones organizadas por nivel
     * @return array Mapa de categorías indexado por term_id
     */
    public static function preload_for_sections($sections_by_level) {
        if (!is_array($sections_by_level) || empty($sections_by_level)) {
            return array();
        }
        
        $category_ids = self::collect_category_ids($sections_by_level);
        
        if (empty($category_ids)) {
            return array();
        }
        
        return self::load_categories($category_ids);
    }
    
    /**
     * Recolectar IDs de categorías de todas las secciones
     */
    private static function collect_category_ids($sections_by_level) {
        $category_ids = array();
        
        foreach ($sections_by_level as $level => $level_sections) {
            if (!is_array($level_sections)) {
                continue;
            }
            foreach ($level_sections as $section_data) {
                if (!is_array($section_data)) {
                    continue;
                }
                if (!empty($section_data['category_id'])) {
                    $category_ids[] = intval($section_data['category_id']);
                }
                if (!empty($section_data['category_id_2'])) {
                    $category_ids[] = intval($section_data['category_id_2']);
                }
            }
        }
        
        return array_values(array_unique($category_ids));
    }
    
    /**
     * Cargar categorías en una sola query
     */
    private static function load_categories($category_ids) {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'include' => $category_ids,
            'hide_empty' => false,
        ));
        
        if (is_wp_error($categories) || empty($categories)) {
            return array();
        }
        
        // Precargar term_meta en una sola query
        if (function_exists('update_term_meta_cache')) {
            update_term_meta_cache($category_ids);
        }
        
        // Crear mapa indexado por term_id
        $category_map = array();
        foreach ($categories as $category) {
            if (!is_object($category) || !isset($category->term_id)) {
                continue;
            }
            $min_level = intval(get_term_meta($category->term_id, '_min_membership_level', true));
            $category_map[$category->term_id] = array(
                'term' => $category,
                'name' => $category->name,
                'slug' => $category->slug,
                'min_membership_level' => $min_level,
            );
        }
        
        return $category_map;
    }
    
    /**
     * Precargar categorías desde un array simple de IDs
     */
    public static function preload_from_ids($category_ids) {
        if (empty($category_ids)) {
            return array();
        }
        
        $category_ids = array_values(array_unique(array_map('intval', $category_ids)));
        return self::load_categories($category_ids);
    }
}
