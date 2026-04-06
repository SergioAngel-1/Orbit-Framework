<?php
/**
 * Funciones helper para la administración de secciones
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calcula las secciones acumuladas para herencia (de arriba hacia abajo)
 * Filtra por nivel mínimo de categoría para cada nivel destino
 */
function fihs_calculate_accumulated_sections($saved_sections_by_level) {
    $all_accumulated = array();
    $all_sections_with_level = array();
    
    // Primero, recopilar todas las secciones con su nivel de origen
    for ($lvl_id = 5; $lvl_id >= 0; $lvl_id--) {
        $own = isset($saved_sections_by_level[$lvl_id]) ? $saved_sections_by_level[$lvl_id] : array();
        foreach ($own as $sec) {
            $sec['from_level'] = $lvl_id;
            $all_sections_with_level[] = $sec;
        }
    }
    
    // Para cada nivel, calcular qué secciones de niveles superiores puede heredar
    for ($target_level = 5; $target_level >= 0; $target_level--) {
        $all_accumulated[$target_level] = array();
        
        foreach ($all_sections_with_level as $sec) {
            // Solo heredar de niveles superiores
            if ($sec['from_level'] <= $target_level) {
                continue;
            }
            
            // Verificar si la categoría es accesible para este nivel
            $cat_min_level = intval(get_term_meta($sec['category_id'], '_min_membership_level', true));
            if ($cat_min_level > $target_level) {
                // Esta categoría requiere un nivel superior
                continue;
            }
            
            $all_accumulated[$target_level][] = $sec;
        }
    }
    
    return $all_accumulated;
}

/**
 * Obtiene las exclusiones en cascada para un nivel
 */
function fihs_get_cascade_excluded($level_id) {
    $cascade_excluded = array();
    for ($higher_lvl = 5; $higher_lvl > $level_id; $higher_lvl--) {
        $exc = get_option('starter_excluded_sections_level_' . $higher_lvl, array());
        if (is_array($exc)) {
            $cascade_excluded = array_merge($cascade_excluded, $exc);
        }
    }
    return array_unique($cascade_excluded);
}

/**
 * Filtra secciones heredadas excluyendo las marcadas
 */
function fihs_filter_inherited_sections($accumulated_sections, $cascade_excluded) {
    $inherited = array();
    foreach ($accumulated_sections as $sec) {
        if (!in_array($sec['id'], $cascade_excluded)) {
            $inherited[] = $sec;
        }
    }
    return $inherited;
}

/**
 * Cuenta secciones heredadas activas (no excluidas en el nivel actual)
 */
function fihs_count_active_inherited($inherited_sections, $excluded_for_level) {
    $count = 0;
    foreach ($inherited_sections as $sec) {
        if (!in_array($sec['id'], $excluded_for_level)) {
            $count++;
        }
    }
    return $count;
}
