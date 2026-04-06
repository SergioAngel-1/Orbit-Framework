<?php
/**
 * Template HTML para nuevas secciones
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza el template para agregar nuevas secciones
 */
function fihs_render_section_template() {
    ?>
    <template id="section-template">
        <div class="section-item" data-index="__INDEX__">
            <div class="section-item-header">
                <span class="section-drag-handle dashicons dashicons-menu"></span>
                <strong class="section-title-display">Nueva Sección</strong>
                <span class="section-layout-badge">Horizontal</span>
                <span class="section-zone-badge">Intermedia</span>
                <button type="button" class="button-link toggle-section-details">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <button type="button" class="button-link delete-section-btn" title="Eliminar">
                    <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                </button>
            </div>
            <div class="section-item-details">
                <input type="hidden" name="sections_by_level[__LEVEL__][__INDEX__][id]" value="section___ID__">
                
                <div class="section-field-row">
                    <label>Layout:</label>
                    <select name="sections_by_level[__LEVEL__][__INDEX__][layout_type]" class="section-layout-select">
                        __LAYOUTS__
                    </select>
                </div>
                
                <div class="section-field-row">
                    <label>Categoría:</label>
                    <select name="sections_by_level[__LEVEL__][__INDEX__][category_id]" class="section-category-select">
                        __CATEGORIES__
                    </select>
                </div>
                
                <div class="section-field-row category-2-field" style="display: none;">
                    <label>Categoría 2:</label>
                    <select name="sections_by_level[__LEVEL__][__INDEX__][category_id_2]" class="section-category-select-2">
                        __CATEGORIES__
                    </select>
                </div>
                
                <div class="section-field-row">
                    <label>Zona:</label>
                    <select name="sections_by_level[__LEVEL__][__INDEX__][zone]">
                        __ZONES__
                    </select>
                </div>
                
                <div class="section-field-row">
                    <label>Título:</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][title]" value="" placeholder="Usar nombre de categoría">
                </div>
                
                <div class="section-field-row">
                    <label>Subtítulo:</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][subtitle]" value="">
                </div>
                
                <div class="section-field-row fihs-translation-field">
                    <label>🇬🇧 Title (EN):</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][title_en]" value="" placeholder="English title (leave empty to use category translation)">
                </div>
                
                <div class="section-field-row fihs-translation-field">
                    <label>🇬🇧 Subtitle (EN):</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][subtitle_en]" value="" placeholder="English subtitle">
                </div>
                
                <div class="section-field-row title-2-field" style="display: none;">
                    <label>Título 2:</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][title_2]" value="" placeholder="Usar nombre de categoría 2">
                </div>
                
                <div class="section-field-row subtitle-2-field" style="display: none;">
                    <label>Subtítulo 2:</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][subtitle_2]" value="">
                </div>
                
                <div class="section-field-row title-2-field fihs-translation-field" style="display: none;">
                    <label>🇬🇧 Title 2 (EN):</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][title_2_en]" value="" placeholder="English title 2">
                </div>
                
                <div class="section-field-row subtitle-2-field fihs-translation-field" style="display: none;">
                    <label>🇬🇧 Subtitle 2 (EN):</label>
                    <input type="text" name="sections_by_level[__LEVEL__][__INDEX__][subtitle_2_en]" value="" placeholder="English subtitle 2">
                </div>
                
                <div class="section-field-row">
                    <label>Orden:</label>
                    <input type="number" name="sections_by_level[__LEVEL__][__INDEX__][order]" value="0" min="0" style="width: 80px;">
                </div>
                
                <div class="section-field-row section-checkboxes">
                    <label>
                        <input type="checkbox" name="sections_by_level[__LEVEL__][__INDEX__][random]" value="1">
                        Aleatorio
                    </label>
                    <label>
                        <input type="checkbox" name="sections_by_level[__LEVEL__][__INDEX__][enabled]" value="1" checked>
                        Activa
                    </label>
                </div>
            </div>
        </div>
    </template>
    <?php
}
