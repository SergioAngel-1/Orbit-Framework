<?php
/**
 * Scripts JavaScript para la página de administración de grillas
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderizar scripts de la página de admin
 */
function fipg_render_admin_scripts($products_by_level, $membership_levels) {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Toggle detalles de grilla
        $(document).on('click', '.toggle-grid-details', function(e) {
            e.preventDefault();
            var $item = $(this).closest('.grid-item');
            var $details = $item.find('.grid-item-details');
            var $icon = $(this).find('.dashicons');
            
            $details.slideToggle(200);
            $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
        });
        
        // Click en header también toggle
        $(document).on('click', '.grid-item-header', function(e) {
            if (!$(e.target).closest('button').length) {
                $(this).find('.toggle-grid-details').click();
            }
        });
        
        // Eliminar grilla
        $(document).on('click', '.delete-grid-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (confirm('¿Eliminar esta grilla?')) {
                var $item = $(this).closest('.grid-item');
                $item.slideUp(200, function() {
                    $(this).remove();
                    updateGridStats();
                });
            }
        });
        
        // Agregar nueva grilla
        $(document).on('click', '.add-grid-btn', function(e) {
            e.preventDefault();
            var level = $(this).data('level');
            var $list = $('#grids-level-' + level);
            var newIndex = $list.find('.grid-item').length;
            var gridId = 'grid_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            var template = getGridTemplate(level, newIndex, gridId);
            
            $list.find('.no-grids').remove();
            $list.append(template);
            
            // Abrir detalles de la nueva grilla
            $list.find('.grid-item').last().find('.toggle-grid-details').click();
            
            updateGridStats();
        });
        
        // Manejar exclusiones de grillas heredadas
        $(document).on('click', '.grid-chip.inherited', function(e) {
            e.preventDefault();
            var $chip = $(this);
            var $checkbox = $chip.find('.inherited-exclude-checkbox');
            
            $chip.toggleClass('excluded');
            $checkbox.prop('checked', $chip.hasClass('excluded'));
            
            updateInheritedCount($chip.closest('.membership-card'));
        });
        
        // Cambio de tipo de grilla
        $(document).on('change', '.grid-type-select', function() {
            var $item = $(this).closest('.grid-item');
            var type = $(this).val();
            var $categoryRow = $item.find('.category-row');
            
            if (type === 'category') {
                $categoryRow.show();
            } else {
                $categoryRow.hide();
                $categoryRow.find('select').val('');
            }
            
            updateGridBadge($item);
        });
        
        // Actualizar título display
        $(document).on('change keyup', '.grid-title-input', function() {
            var $item = $(this).closest('.grid-item');
            var title = $(this).val() || 'Sin título';
            $item.find('.grid-title-display').text(title);
        });
        
        // Actualizar conteo de productos
        $(document).on('change', '.product-select', function() {
            var $item = $(this).closest('.grid-item');
            updateProductCount($item);
        });
        
        function updateGridBadge($item) {
            var type = $item.find('.grid-type-select').val();
            var $badge = $item.find('.grid-type-badge');
            
            if (type === 'category') {
                $badge.text('Categoría').addClass('category');
            } else {
                $badge.text('Por defecto').removeClass('category');
            }
        }
        
        function updateProductCount($item) {
            var count = $item.find('.product-select').filter(function() {
                return $(this).val() !== '';
            }).length;
            $item.find('.grid-products-count').text(count + ' productos');
        }
        
        function updateInheritedCount($card) {
            var count = $card.find('.grid-chip.inherited:not(.excluded)').length;
            $card.find('.inherited-count').text(count);
            $card.find('.stat-inherited').text(count + ' heredadas');
        }
        
        function updateGridStats() {
            $('.membership-card').each(function() {
                var $card = $(this);
                var ownCount = $card.find('.grid-item').length;
                $card.find('.stat-own').text(ownCount + ' propias');
            });
        }
        
        function getGridTemplate(level, index, gridId) {
            var productsData = <?php echo json_encode($products_by_level); ?>;
            var products = productsData[level] || [];
            
            var productOptions = '<option value="">-- Seleccionar --</option>';
            products.forEach(function(p) {
                productOptions += '<option value="' + p.ID + '">' + p.post_title + '</option>';
            });
            
            var categoriesHtml = '';
            <?php
            $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (!empty($categories) && !is_wp_error($categories)):
            ?>
            var categories = <?php echo json_encode(array_map(function($c) {
                return array('id' => $c->term_id, 'name' => $c->name);
            }, $categories)); ?>;
            
            categoriesHtml = '<option value="">-- Seleccionar --</option>';
            categories.forEach(function(c) {
                categoriesHtml += '<option value="' + c.id + '">' + c.name + '</option>';
            });
            <?php endif; ?>
            
            return `
                <div class="grid-item" data-index="${index}">
                    <div class="grid-item-header">
                        <strong class="grid-title-display">Nueva Grilla</strong>
                        <span class="grid-type-badge">Por defecto</span>
                        <span class="grid-products-count">0 productos</span>
                        <button type="button" class="button-link toggle-grid-details">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                        </button>
                        <button type="button" class="button-link delete-grid-btn" title="Eliminar">
                            <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                        </button>
                    </div>
                    <div class="grid-item-details">
                        <input type="hidden" name="grids_by_level[${level}][${index}][id]" value="${gridId}">
                        
                        <div class="grid-field-row">
                            <label>Título:</label>
                            <input type="text" name="grids_by_level[${level}][${index}][title]" 
                                   class="grid-title-input" placeholder="Nombre de la grilla">
                        </div>
                        
                        <div class="grid-field-row">
                            <label>Tipo:</label>
                            <select name="grids_by_level[${level}][${index}][type]" class="grid-type-select">
                                <option value="default">🏠 Por defecto (Home)</option>
                                <option value="category">📁 Por categoría</option>
                            </select>
                        </div>
                        
                        <div class="grid-field-row">
                            <label>Visibilidad:</label>
                            <select name="grids_by_level[${level}][${index}][visibility_mode]" class="visibility-mode-select">
                                <option value="cascade">📈 Cascada (este nivel y superiores)</option>
                                <option value="exact">🎯 Exacto (solo este nivel)</option>
                            </select>
                        </div>
                        
                        <div class="grid-field-row category-row" style="display: none;">
                            <label>Categoría:</label>
                            <select name="grids_by_level[${level}][${index}][category_id]">
                                ${categoriesHtml}
                            </select>
                        </div>
                        
                        <div class="grid-field-row">
                            <label>Productos:</label>
                            <div class="products-selector">
                                <select name="grids_by_level[${level}][${index}][products][]" class="product-select">
                                    ${productOptions}
                                </select>
                                <select name="grids_by_level[${level}][${index}][products][]" class="product-select">
                                    ${productOptions}
                                </select>
                                <select name="grids_by_level[${level}][${index}][products][]" class="product-select">
                                    ${productOptions}
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid-field-row grid-checkboxes">
                            <label>
                                <input type="checkbox" name="grids_by_level[${level}][${index}][enabled]" value="1" checked>
                                Activa
                            </label>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Inicializar conteos
        updateGridStats();
    });
    </script>
    <?php
}
