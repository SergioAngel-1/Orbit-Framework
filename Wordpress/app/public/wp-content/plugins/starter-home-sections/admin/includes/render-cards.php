<?php
/**
 * Renderizado de las tarjetas de membresía
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza una tarjeta de membresía completa
 */
function fihs_render_membership_card($level_id, $level, $own_sections, $inherited_sections, $inherited_active_count, $excluded_for_level, $available_categories, $layout_types, $zones, $membership_levels) {
    ?>
    <div class="membership-card" data-level="<?php echo $level_id; ?>" style="border-left-color: <?php echo esc_attr($level['color']); ?>">
        <?php fihs_render_card_header($level_id, $level, $own_sections, $inherited_active_count); ?>
        
        <div class="membership-body">
            <?php 
            fihs_render_inherited_section($level_id, $inherited_sections, $excluded_for_level, $layout_types, $membership_levels);
            fihs_render_own_section($level_id, $level, $own_sections, $available_categories, $layout_types, $zones, $membership_levels);
            ?>
        </div>
    </div>
    <?php
}

/**
 * Renderiza el header de la tarjeta
 */
function fihs_render_card_header($level_id, $level, $own_sections, $inherited_active_count) {
    ?>
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
    <?php
}

/**
 * Renderiza la sección de heredadas
 */
function fihs_render_inherited_section($level_id, $inherited_sections, $excluded_for_level, $layout_types, $membership_levels) {
    if ($level_id >= 5 || empty($inherited_sections)) {
        return;
    }
    
    $inherited_active_count = fihs_count_active_inherited($inherited_sections, $excluded_for_level);
    ?>
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
    <?php
}

/**
 * Renderiza la sección de propias
 */
function fihs_render_own_section($level_id, $level, $own_sections, $available_categories, $layout_types, $zones, $membership_levels) {
    ?>
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
                    fihs_render_section_item($level_id, $idx, $section, $available_categories, $layout_types, $zones, $membership_levels);
                endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Renderiza un item de sección individual
 */
function fihs_render_section_item($level_id, $idx, $section, $available_categories, $layout_types, $zones, $membership_levels) {
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
            
            <div class="section-field-row category-2-field" style="<?php echo ($section['layout_type'] === 'compact_pair') ? '' : 'display: none;'; ?>">
                <label>Categoría 2:</label>
                <select name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][category_id_2]" class="section-category-select-2">
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
            
            <div class="section-field-row title-2-field" style="<?php echo ($section['layout_type'] === 'compact_pair') ? '' : 'display: none;'; ?>">
                <label>Título 2:</label>
                <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][title_2]" 
                       value="<?php echo esc_attr($section['title_2'] ?? ''); ?>" placeholder="Usar nombre de categoría 2">
            </div>
            
            <div class="section-field-row subtitle-2-field" style="<?php echo ($section['layout_type'] === 'compact_pair') ? '' : 'display: none;'; ?>">
                <label>Subtítulo 2:</label>
                <input type="text" name="sections_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][subtitle_2]" 
                       value="<?php echo esc_attr($section['subtitle_2'] ?? ''); ?>">
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
    <?php
}
