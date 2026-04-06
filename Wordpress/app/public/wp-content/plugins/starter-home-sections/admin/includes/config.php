<?php
/**
 * Configuración y constantes para la administración de secciones
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtiene la definición de niveles de membresía
 */
function fihs_get_membership_levels() {
    return array(
        0 => array('name' => 'Zanahoria (Público)', 'icon' => '🥕', 'color' => '#FF6B35'),
        1 => array('name' => 'Bronce', 'icon' => '🥉', 'color' => '#CD7F32'),
        2 => array('name' => 'Plateada', 'icon' => '🥈', 'color' => '#C0C0C0'),
        3 => array('name' => 'Dorada', 'icon' => '🥇', 'color' => '#FFD700'),
        4 => array('name' => 'Diamante', 'icon' => '💎', 'color' => '#B9F2FF'),
        5 => array('name' => 'Antigüedad', 'icon' => '👑', 'color' => '#9B59B6'),
    );
}

/**
 * Obtiene el orden de visualización (mayor a menor)
 */
function fihs_get_display_order() {
    return array(5, 4, 3, 2, 1, 0);
}

/**
 * Obtiene las categorías agrupadas por nivel de membresía
 */
function fihs_get_categories_by_level() {
    $all_categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    $categories_by_level = array();
    
    if (!is_wp_error($all_categories)) {
        foreach ($all_categories as $cat) {
            $min_level = intval(get_term_meta($cat->term_id, '_min_membership_level', true));
            if (!isset($categories_by_level[$min_level])) {
                $categories_by_level[$min_level] = array();
            }
            $categories_by_level[$min_level][] = $cat;
        }
    }
    
    return $categories_by_level;
}

/**
 * Obtiene las categorías disponibles para un nivel específico
 */
function fihs_get_available_categories_for_level($level_id, $categories_by_level) {
    $available = array();
    for ($cat_level = 0; $cat_level <= $level_id; $cat_level++) {
        if (isset($categories_by_level[$cat_level])) {
            $available = array_merge($available, $categories_by_level[$cat_level]);
        }
    }
    return $available;
}
