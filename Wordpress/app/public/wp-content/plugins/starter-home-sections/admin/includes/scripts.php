<?php
/**
 * Scripts JavaScript para la página de administración
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza los scripts JavaScript
 */
function fihs_render_admin_scripts($categories_by_level, $membership_levels, $layout_types, $zones) {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Toggle detalles de sección
        $(document).on('click', '.toggle-section-details', function() {
            var $item = $(this).closest('.section-item');
            var $details = $item.find('.section-item-details');
            $details.slideToggle(200);
            $item.toggleClass('expanded');
        });
        
        // Eliminar sección
        $(document).on('click', '.delete-section-btn', function() {
            if (confirm('¿Estás seguro de eliminar esta sección?')) {
                $(this).closest('.section-item').remove();
                updateSectionIndexes();
            }
        });
        
        // Excluir/incluir sección heredada
        $(document).on('click', '.section-chip.inherited', function(e) {
            e.preventDefault();
            var $chip = $(this);
            var $checkbox = $chip.find('.inherited-exclude-checkbox');
            
            if ($chip.hasClass('excluded')) {
                $chip.removeClass('excluded');
                $checkbox.prop('checked', false);
            } else {
                $chip.addClass('excluded');
                $checkbox.prop('checked', true);
            }
            
            updateInheritedCount($chip.closest('.membership-card'));
        });
        
        function updateInheritedCount($card) {
            var activeCount = $card.find('.section-chip.inherited:not(.excluded)').length;
            $card.find('.inherited-count').text(activeCount);
            $card.find('.stat-inherited').text(activeCount + ' heredadas');
        }
        
        // Agregar nueva sección
        $('.add-section-btn').on('click', function() {
            var level = $(this).data('level');
            var $list = $('#sections-level-' + level);
            var index = $list.find('.section-item').length;
            var uniqueId = Date.now();
            
            // Obtener categorías disponibles para este nivel
            var categoriesHtml = getCategoriesForLevel(level);
            var layoutsHtml = getLayoutsHtml();
            var zonesHtml = getZonesHtml();
            
            var template = $('#section-template').html()
                .replace(/__LEVEL__/g, level)
                .replace(/__INDEX__/g, index)
                .replace(/__ID__/g, uniqueId)
                .replace('__CATEGORIES__', categoriesHtml)
                .replace('__LAYOUTS__', layoutsHtml)
                .replace('__ZONES__', zonesHtml);
            
            $list.find('.no-sections').remove();
            $list.append(template);
            
            // Abrir detalles de la nueva sección
            $list.find('.section-item').last().find('.section-item-details').show();
            $list.find('.section-item').last().addClass('expanded');
            
            updateOwnCount($list.closest('.membership-card'));
        });
        
        function updateOwnCount($card) {
            var count = $card.find('.own-sections-list .section-item').length;
            $card.find('.stat-own').text(count + ' propias');
        }
        
        function updateSectionIndexes() {
            $('.own-sections-list').each(function() {
                var level = $(this).attr('id').replace('sections-level-', '');
                $(this).find('.section-item').each(function(idx) {
                    $(this).attr('data-index', idx);
                    $(this).find('input, select').each(function() {
                        var name = $(this).attr('name');
                        if (name) {
                            name = name.replace(/\[\d+\]/g, '[' + idx + ']');
                            $(this).attr('name', name);
                        }
                    });
                });
                updateOwnCount($(this).closest('.membership-card'));
            });
        }
        
        // Datos para templates
        var categoriesByLevel = <?php echo json_encode($categories_by_level); ?>;
        var membershipLevels = <?php echo json_encode($membership_levels); ?>;
        var layoutTypes = <?php echo json_encode($layout_types); ?>;
        var zones = <?php echo json_encode($zones); ?>;
        
        function getCategoriesForLevel(level) {
            var html = '';
            for (var l = 0; l <= level; l++) {
                if (categoriesByLevel[l]) {
                    categoriesByLevel[l].forEach(function(cat) {
                        var levelInfo = membershipLevels[l] || {icon: '🥕'};
                        html += '<option value="' + cat.term_id + '">' + cat.name + ' (' + levelInfo.icon + ')</option>';
                    });
                }
            }
            return html;
        }
        
        function getLayoutsHtml() {
            var html = '';
            for (var key in layoutTypes) {
                html += '<option value="' + key + '">' + layoutTypes[key].name + '</option>';
            }
            return html;
        }
        
        function getZonesHtml() {
            var html = '';
            for (var key in zones) {
                html += '<option value="' + key + '">' + zones[key] + '</option>';
            }
            return html;
        }
        
        // Mapa de categoría ID a nivel de membresía
        var categoryLevelMap = {};
        for (var lvl in categoriesByLevel) {
            if (categoriesByLevel[lvl]) {
                categoriesByLevel[lvl].forEach(function(cat) {
                    categoryLevelMap[cat.term_id] = parseInt(lvl);
                });
            }
        }
        
        // Función para obtener categorías del mismo nivel de membresía
        function getCategoriesForSameLevel(categoryId) {
            var level = categoryLevelMap[categoryId];
            if (level === undefined) return '';
            
            var html = '<option value="">-- Seleccionar categoría 2 --</option>';
            if (categoriesByLevel[level]) {
                var levelInfo = membershipLevels[level] || {icon: '🥕'};
                categoriesByLevel[level].forEach(function(cat) {
                    // Excluir la misma categoría
                    if (cat.term_id != categoryId) {
                        html += '<option value="' + cat.term_id + '">' + cat.name + ' (' + levelInfo.icon + ')</option>';
                    }
                });
            }
            return html;
        }
        
        // Actualizar título y filtrar Categoría 2 al cambiar Categoría 1
        $(document).on('change', '.section-category-select', function() {
            var $item = $(this).closest('.section-item');
            var selectedText = $(this).find('option:selected').text().replace(/\s*\([^)]*\)$/, '');
            $item.find('.section-title-display').text(selectedText);
            
            // Si es compact_pair, actualizar opciones de Categoría 2
            var layout = $item.find('.section-layout-select').val();
            if (layout === 'compact_pair') {
                var categoryId = $(this).val();
                var $category2Select = $item.find('.section-category-select-2');
                var currentValue2 = $category2Select.val();
                
                // Regenerar opciones de Categoría 2 con solo categorías del mismo nivel
                $category2Select.html(getCategoriesForSameLevel(categoryId));
                
                // Intentar mantener el valor anterior si sigue siendo válido
                if (currentValue2 && $category2Select.find('option[value="' + currentValue2 + '"]').length) {
                    $category2Select.val(currentValue2);
                }
            }
        });
        
        // Mostrar/ocultar campo de Categoría 2 según layout
        $(document).on('change', '.section-layout-select', function() {
            var $item = $(this).closest('.section-item');
            var layout = $(this).val();
            var $category2Field = $item.find('.category-2-field');
            
            if (layout === 'compact_pair') {
                $category2Field.show();
                $item.find('.title-2-field').show();
                $item.find('.subtitle-2-field').show();
                // Filtrar opciones de Categoría 2 según Categoría 1 seleccionada
                var categoryId = $item.find('.section-category-select').val();
                var $category2Select = $item.find('.section-category-select-2');
                $category2Select.html(getCategoriesForSameLevel(categoryId));
            } else {
                $category2Field.hide();
                $item.find('.title-2-field').hide();
                $item.find('.subtitle-2-field').hide();
            }
            
            // Actualizar badge de layout
            var layoutName = layoutTypes[layout] ? layoutTypes[layout].name : layout;
            $item.find('.section-layout-badge').text(layoutName);
        });
        
        // Inicializar visibilidad y filtrado de Categoría 2 en secciones existentes
        $('.section-layout-select').each(function() {
            var $item = $(this).closest('.section-item');
            var layout = $(this).val();
            if (layout === 'compact_pair') {
                $item.find('.category-2-field').show();
                $item.find('.title-2-field').show();
                $item.find('.subtitle-2-field').show();
                // Filtrar opciones de Categoría 2 según Categoría 1
                var categoryId = $item.find('.section-category-select').val();
                var $category2Select = $item.find('.section-category-select-2');
                var currentValue2 = $category2Select.val();
                $category2Select.html(getCategoriesForSameLevel(categoryId));
                // Restaurar valor si sigue siendo válido
                if (currentValue2 && $category2Select.find('option[value="' + currentValue2 + '"]').length) {
                    $category2Select.val(currentValue2);
                }
            }
        });
        
        // Botón de importar configuración
        $('#import-config-btn').on('click', function() {
            if (confirm('¿Importar configuración?\n\nEsto reemplazará TODA la configuración actual de secciones.')) {
                $('#config-file-input').click();
            }
        });
        
        // Enviar formulario cuando se selecciona archivo
        $('#config-file-input').on('change', function() {
            if (this.files.length > 0) {
                $('#import-config-form').submit();
            }
        });
    });
    </script>
    <?php
}
