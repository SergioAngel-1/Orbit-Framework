<?php
/**
 * Página de administración mejorada con interfaz para gestionar secciones por membresía
 * Sistema de herencia en cascada: niveles inferiores heredan secciones de niveles superiores
 * (Antigüedad → Diamante → Dorada → Plateada → Bronce → Zanahoria)
 * 
 * Archivo refactorizado - carga módulos desde /includes/
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar módulos
require_once FIHS_PLUGIN_DIR . 'includes/class-migration.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/config.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/actions.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/helpers.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/render-legacy.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/render-cards.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/render-template.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/styles.php';
require_once FIHS_PLUGIN_DIR . 'admin/includes/scripts.php';

// Obtener configuración
$membership_levels = fihs_get_membership_levels();
$display_order = fihs_get_display_order();
$categories_by_level = fihs_get_categories_by_level();

// Inicializar plugin y obtener datos
$plugin = Starter_Home_Sections::get_instance();
$layout_types = $plugin->get_layout_types();
$zones = $plugin->get_zones();

// Procesar acciones POST
fihs_process_admin_actions();

// Obtener secciones guardadas por nivel
$saved_sections_by_level = get_option('starter_home_sections_by_level', array());

// Obtener secciones del formato antiguo (legacy)
$legacy_sections = get_option('starter_home_sections_list', array());
?>

<div class="wrap fihs-admin-wrap">
    <h1>
        <span class="dashicons dashicons-layout" style="font-size: 30px; margin-right: 10px;"></span>
        Secciones de Inicio por Membresía
    </h1>
    
    <p class="description" style="font-size: 14px; margin-bottom: 20px;">
        Configura las secciones de productos que se mostrarán en la página de inicio para cada nivel de membresía.
        <br><strong>Jerarquía (mayor a menor):</strong> 👑 Antigüedad → 💎 Diamante → 🥇 Dorada → 🥈 Plateada → 🥉 Bronce → 🥕 Zanahoria (público/base).
        <br><strong>Herencia:</strong> Las secciones configuradas en niveles superiores se heredan automáticamente a los niveles inferiores (puedes excluirlas si lo deseas).
    </p>
    
    <!-- Panel de herramientas -->
    <div class="cascade-update-panel" style="background: #f0f6fc; border: 1px solid #c3d9ed; border-left: 4px solid #2271b1; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
            <div>
                <h3 style="margin: 0 0 5px 0; color: #2271b1;">
                    <span class="dashicons dashicons-admin-tools" style="margin-right: 5px;"></span>
                    Herramientas de Secciones
                </h3>
                <p class="description" style="margin: 0;">
                    Gestiona las secciones en cascada y elimina duplicados.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('starter_home_sections_action'); ?>
                    <input type="hidden" name="action" value="remove_duplicates">
                    <button type="submit" class="button" onclick="return confirm('¿Eliminar todas las secciones duplicadas?\n\nSe mantendrá solo una sección por categoría en cada nivel.');">
                        <span class="dashicons dashicons-dismiss" style="margin-top: 4px; color: #d63638;"></span>
                        Eliminar Duplicados
                    </button>
                </form>
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('starter_home_sections_action'); ?>
                    <input type="hidden" name="action" value="cascade_update">
                    <button type="submit" class="button button-primary" onclick="return confirm('¿Propagar las secciones de niveles superiores a los inferiores?\n\nSolo se copiarán secciones cuyas categorías sean accesibles para cada nivel.');">
                        <span class="dashicons dashicons-arrow-down-alt" style="margin-top: 4px;"></span>
                        Actualizar en Cascada
                    </button>
                </form>
                <span style="border-left: 1px solid #c3d9ed; margin: 0 5px;"></span>
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('starter_home_sections_action'); ?>
                    <input type="hidden" name="action" value="export_config">
                    <button type="submit" class="button">
                        <span class="dashicons dashicons-download" style="margin-top: 4px; color: #2271b1;"></span>
                        Exportar Config
                    </button>
                </form>
                <button type="button" class="button" id="import-config-btn">
                    <span class="dashicons dashicons-upload" style="margin-top: 4px; color: #00a32a;"></span>
                    Importar Config
                </button>
                <form method="post" enctype="multipart/form-data" style="display: none;" id="import-config-form">
                    <?php wp_nonce_field('starter_home_sections_action'); ?>
                    <input type="hidden" name="action" value="import_config">
                    <input type="file" name="config_file" id="config-file-input" accept=".json">
                </form>
            </div>
        </div>
    </div>
    
    <?php fihs_render_legacy_panel($legacy_sections, $layout_types, $zones); ?>
    
    <form method="post" id="sections-form">
        <?php wp_nonce_field('starter_home_sections_action'); ?>
        <input type="hidden" name="action" value="save_sections_by_level">
        
        <div class="membership-cards-grid">
            <?php 
            // Pre-calcular secciones acumuladas para cada nivel (herencia de arriba hacia abajo)
            // Los niveles inferiores heredan de los superiores: Antigüedad → Zanahoria
            $all_accumulated = array();
            $temp_accumulated = array();
            // Iterar desde el nivel más alto (5) hacia el más bajo (0)
            for ($lvl_id = 5; $lvl_id >= 0; $lvl_id--) {
                $own = isset($saved_sections_by_level[$lvl_id]) ? $saved_sections_by_level[$lvl_id] : array();
                $all_accumulated[$lvl_id] = $temp_accumulated;
                foreach ($own as $sec) {
                    $sec['from_level'] = $lvl_id;
                    $temp_accumulated[] = $sec;
                }
            }
            
            // Iterar en orden de visualización (Diamante primero, Zanahoria último)
            foreach ($display_order as $level_id):
                $level = $membership_levels[$level_id]; 
                // Obtener secciones propias de este nivel
                $own_sections = isset($saved_sections_by_level[$level_id]) ? $saved_sections_by_level[$level_id] : array();
                
                // Recopilar exclusiones en cascada (de niveles superiores hacia abajo)
                $cascade_excluded = array();
                for ($higher_lvl = 5; $higher_lvl > $level_id; $higher_lvl--) {
                    $exc = get_option('starter_excluded_sections_level_' . $higher_lvl, array());
                    if (is_array($exc)) {
                        $cascade_excluded = array_merge($cascade_excluded, $exc);
                    }
                }
                $cascade_excluded = array_unique($cascade_excluded);
                
                // Exclusiones propias de este nivel
                $excluded_for_level = get_option('starter_excluded_sections_level_' . $level_id, array());
                if (!is_array($excluded_for_level)) $excluded_for_level = array();
                
                // Filtrar secciones heredadas:
                // 1. Que no estén excluidas en cascada
                // 2. Cuya categoría sea accesible para este nivel de membresía
                $accumulated_sections = $all_accumulated[$level_id];
                $inherited_sections = array();
                foreach ($accumulated_sections as $sec) {
                    // Verificar si está excluida
                    if (in_array($sec['id'], $cascade_excluded)) {
                        continue;
                    }
                    
                    // Verificar si la categoría es accesible para este nivel
                    $cat_min_level = intval(get_term_meta($sec['category_id'], '_min_membership_level', true));
                    if ($cat_min_level > $level_id) {
                        // Esta categoría requiere un nivel superior, no heredar
                        continue;
                    }
                    
                    $inherited_sections[] = $sec;
                }
                
                // Contar heredadas activas (no excluidas en este nivel)
                $inherited_active_count = 0;
                foreach ($inherited_sections as $sec) {
                    if (!in_array($sec['id'], $excluded_for_level)) {
                        $inherited_active_count++;
                    }
                }
                
                // Categorías disponibles para este nivel (membresía mínima <= nivel actual)
                $available_categories = array();
                for ($cat_level = 0; $cat_level <= $level_id; $cat_level++) {
                    if (isset($categories_by_level[$cat_level])) {
                        $available_categories = array_merge($available_categories, $categories_by_level[$cat_level]);
                    }
                }
            ?>
            <div class="membership-card" data-level="<?php echo $level_id; ?>" style="border-left-color: <?php echo esc_attr($level['color']); ?>">
                <div class="membership-header">
                    <div class="membership-title">
                        <span class="membership-icon"><?php echo esc_html($level['icon']); ?></span>
                        <span class="membership-name"><?php echo esc_html($level['name']); ?></span>
                        <?php if ($level_id === 0): ?>
                            <span class="membership-badge" style="background: #FF6B35;">Base/Público</span>
                        <?php elseif ($level_id === 5): ?>
                            <span class="membership-badge" style="background: #9B59B6;">Nivel más alto</span>
                        <?php endif; ?>
                    </div>
                    <div class="membership-stats">
                        <span class="stat-own"><?php echo count($own_sections); ?> propias</span>
                        <?php if ($level_id < 5): ?>
                            <span class="stat-inherited"><?php echo $inherited_active_count; ?> heredadas</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="membership-body">
                    <?php if ($level_id < 5 && !empty($inherited_sections)): ?>
                    <div class="inherited-section">
                        <div class="section-label">
                            <span class="dashicons dashicons-arrow-down-alt"></span>
                            Secciones heredadas de niveles superiores: <strong class="inherited-count"><?php echo $inherited_active_count; ?></strong>
                            <span class="inherited-hint">(click para excluir en este nivel y los inferiores)</span>
                        </div>
                        <div class="inherited-items">
                            <?php foreach ($inherited_sections as $sec): 
                                $cat = get_term($sec['category_id'], 'product_cat');
                                $is_excluded = in_array($sec['id'], $excluded_for_level);
                                $sec_level = $membership_levels[$sec['from_level'] ?? 0];
                            ?>
                            <label class="section-chip inherited <?php echo $is_excluded ? 'excluded' : ''; ?>" 
                                   title="<?php echo $is_excluded ? 'Excluida - click para incluir' : 'Click para excluir'; ?>">
                                <input type="checkbox" 
                                       name="excluded_sections[<?php echo $level_id; ?>][]" 
                                       value="<?php echo esc_attr($sec['id']); ?>"
                                       class="inherited-exclude-checkbox"
                                       <?php checked($is_excluded); ?>
                                       style="display: none;">
                                <span class="chip-icon" style="color: <?php echo esc_attr($sec_level['color']); ?>"><?php echo esc_html($sec_level['icon']); ?></span>
                                <span class="chip-text"><?php echo esc_html($sec['title'] ?: ($cat ? $cat->name : 'Sin título')); ?></span>
                                <span class="chip-layout"><?php echo esc_html($layout_types[$sec['layout_type']]['name'] ?? $sec['layout_type']); ?></span>
                                <span class="chip-exclude-icon"></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="own-section">
                        <div class="section-label">
                            <span class="dashicons dashicons-star-filled" style="color: <?php echo esc_attr($level['color']); ?>"></span>
                            Secciones propias de <?php echo esc_html($level['name']); ?>
                            <button type="button" class="button button-small add-section-btn" data-level="<?php echo $level_id; ?>">
                                <span class="dashicons dashicons-plus-alt2"></span> Agregar
                            </button>
                        </div>
                        
                        <div class="own-sections-list" id="sections-level-<?php echo $level_id; ?>">
                            <?php if (empty($own_sections)): ?>
                                <p class="no-sections">No hay secciones propias para este nivel.</p>
                            <?php else: ?>
                                <?php foreach ($own_sections as $idx => $section): 
                                    $cat = get_term($section['category_id'], 'product_cat');
                                ?>
                                <div class="section-item" data-index="<?php echo $idx; ?>">
                                    <div class="section-item-header">
                                        <span class="section-drag-handle dashicons dashicons-menu"></span>
                                        <strong class="section-title-display"><?php echo esc_html($section['title'] ?: ($cat ? $cat->name : 'Sin título')); ?></strong>
                                        <span class="section-layout-badge"><?php echo esc_html($layout_types[$section['layout_type']]['name'] ?? $section['layout_type']); ?></span>
                                        <span class="section-zone-badge"><?php echo esc_html($zones[$section['zone']] ?? $section['zone']); ?></span>
                                        <button type="button" class="button-link toggle-section-details">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                        </button>
                                        <button type="button" class="button-link delete-section-btn" title="Eliminar">
                                            <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                                        </button>
                                    </div>
                                    <?php $is_compact_pair = ($section['layout_type'] === 'compact_pair'); ?>
                                    <div class="section-item-details" style="display: none;">
                                        <input type="hidden" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][id]" value="<?php echo esc_attr($section['id']); ?>">
                                        
                                        <div class="section-field-row">
                                            <label>Layout:</label>
                                            <select name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][layout_type]" class="section-layout-select">
                                                <?php foreach ($layout_types as $lt_key => $lt): ?>
                                                <option value="<?php echo esc_attr($lt_key); ?>" <?php selected($section['layout_type'], $lt_key); ?>>
                                                    <?php echo esc_html($lt['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="section-field-row">
                                            <label>Categoría:</label>
                                            <select name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][category_id]" class="section-category-select">
                                                <?php foreach ($available_categories as $avail_cat): 
                                                    $cat_min_level = intval(get_term_meta($avail_cat->term_id, '_min_membership_level', true));
                                                    $cat_level_info = $membership_levels[$cat_min_level];
                                                ?>
                                                <option value="<?php echo esc_attr($avail_cat->term_id); ?>" <?php selected($section['category_id'], $avail_cat->term_id); ?>>
                                                    <?php echo esc_html($avail_cat->name); ?> (<?php echo esc_html($cat_level_info['icon']); ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="section-field-row category-2-field" style="<?php echo $is_compact_pair ? '' : 'display: none;'; ?>">
                                            <label>Categoría 2:</label>
                                            <select name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][category_id_2]" class="section-category-select-2">
                                                <option value="">-- Seleccionar categoría 2 --</option>
                                                <?php foreach ($available_categories as $avail_cat): 
                                                    $cat_min_level = intval(get_term_meta($avail_cat->term_id, '_min_membership_level', true));
                                                    $cat_level_info = $membership_levels[$cat_min_level];
                                                ?>
                                                <option value="<?php echo esc_attr($avail_cat->term_id); ?>" <?php selected($section['category_id_2'] ?? 0, $avail_cat->term_id); ?>>
                                                    <?php echo esc_html($avail_cat->name); ?> (<?php echo esc_html($cat_level_info['icon']); ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="section-field-row">
                                            <label>Zona:</label>
                                            <select name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][zone]">
                                                <?php foreach ($zones as $z_key => $z_name): ?>
                                                <option value="<?php echo esc_attr($z_key); ?>" <?php selected($section['zone'], $z_key); ?>>
                                                    <?php echo esc_html($z_name); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="section-field-row">
                                            <label>Título:</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][title]" 
                                                   value="<?php echo esc_attr($section['title']); ?>" placeholder="Usar nombre de categoría">
                                        </div>
                                        
                                        <div class="section-field-row">
                                            <label>Subtítulo:</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][subtitle]" 
                                                   value="<?php echo esc_attr($section['subtitle'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="section-field-row fihs-translation-field">
                                            <label>🇬🇧 Title (EN):</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][title_en]" 
                                                   value="<?php echo esc_attr($section['title_en'] ?? ''); ?>" placeholder="English title (leave empty to use category translation)">
                                        </div>
                                        
                                        <div class="section-field-row fihs-translation-field">
                                            <label>🇬🇧 Subtitle (EN):</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][subtitle_en]" 
                                                   value="<?php echo esc_attr($section['subtitle_en'] ?? ''); ?>" placeholder="English subtitle">
                                        </div>
                                        
                                        <div class="section-field-row title-2-field" style="<?php echo $is_compact_pair ? '' : 'display: none;'; ?>">
                                            <label>Título 2:</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][title_2]" 
                                                   value="<?php echo esc_attr($section['title_2'] ?? ''); ?>" placeholder="Usar nombre de categoría 2">
                                        </div>
                                        
                                        <div class="section-field-row subtitle-2-field" style="<?php echo $is_compact_pair ? '' : 'display: none;'; ?>">
                                            <label>Subtítulo 2:</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][subtitle_2]" 
                                                   value="<?php echo esc_attr($section['subtitle_2'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="section-field-row title-2-field fihs-translation-field" style="<?php echo $is_compact_pair ? '' : 'display: none;'; ?>">
                                            <label>🇬🇧 Title 2 (EN):</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][title_2_en]" 
                                                   value="<?php echo esc_attr($section['title_2_en'] ?? ''); ?>" placeholder="English title 2">
                                        </div>
                                        
                                        <div class="section-field-row subtitle-2-field fihs-translation-field" style="<?php echo $is_compact_pair ? '' : 'display: none;'; ?>">
                                            <label>🇬🇧 Subtitle 2 (EN):</label>
                                            <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][subtitle_2_en]" 
                                                   value="<?php echo esc_attr($section['subtitle_2_en'] ?? ''); ?>" placeholder="English subtitle 2">
                                        </div>
                                        
                                        <div class="section-field-row">
                                            <label>Orden:</label>
                                            <input type="number" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][order]" 
                                                   value="<?php echo intval($section['order']); ?>" min="0" style="width: 80px;">
                                        </div>
                                        
                                        <div class="section-field-row section-checkboxes">
                                            <label>
                                                <input type="checkbox" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][random]" 
                                                       value="1" <?php checked(!empty($section['random'])); ?>>
                                                Aleatorio
                                            </label>
                                            <label>
                                                <input type="checkbox" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][enabled]" 
                                                       value="1" <?php checked($section['enabled'] ?? true); ?>>
                                                Activa
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php 
            endforeach; 
            ?>
        </div>
        
        <div class="submit-section">
            <button type="submit" class="button button-primary button-large">
                <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                Guardar Todas las Secciones
            </button>
        </div>
    </form>
</div>

<?php 
// Renderizar estilos, template y scripts desde módulos
fihs_render_admin_styles();
fihs_render_section_template();
fihs_render_admin_scripts($categories_by_level, $membership_levels, $layout_types, $zones);
?>
