<?php
/**
 * Página de administración centralizada para grillas promocionales por membresía
 * 
 * Sigue el patrón de home-sections con tarjetas por nivel de membresía,
 * herencia en cascada y exclusiones visuales.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar módulos
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/helpers.php';
require_once dirname(__FILE__) . '/actions.php';
require_once dirname(__FILE__) . '/styles.php';
require_once dirname(__FILE__) . '/scripts.php';

// Obtener configuración
$membership_levels = fipg_get_membership_levels();
$display_order = fipg_get_display_order();

// Procesar acciones POST
fipg_process_admin_actions();

// Verificar si hay grillas del CPT sin migrar
$grids_migrated = get_option('starter_grids_migrated_to_levels', false);
$cpt_grids_count = 0;
if (!$grids_migrated) {
    $cpt_grids_count = wp_count_posts('promotional_grid')->publish + wp_count_posts('promotional_grid')->draft;
}

// Obtener grillas guardadas por nivel
$saved_grids_by_level = fipg_get_grids_by_level();

// Pre-calcular productos disponibles por nivel
$products_by_level = array();
for ($lvl = 0; $lvl <= 5; $lvl++) {
    $products_by_level[$lvl] = fipg_get_products_for_level($lvl);
}

// Mostrar errores/éxitos
settings_errors('starter_promotional_grids');
?>

<div class="wrap fipg-admin-wrap">
    <h1>
        <span class="dashicons dashicons-grid-view" style="font-size: 30px; margin-right: 10px;"></span>
        Grillas Promocionales por Membresía
    </h1>
    
    <p class="description" style="font-size: 14px; margin-bottom: 20px;">
        Configura las grillas de productos promocionales para cada nivel de membresía.
        <br><strong>Jerarquía (mayor a menor):</strong> 👑 Antigüedad → 💎 Diamante → 🥇 Dorada → 🥈 Plateada → 🥉 Bronce → 🥕 Zanahoria (público/base).
        <br><strong>Herencia:</strong> Las grillas de niveles inferiores se heredan automáticamente a los niveles superiores (ej: Dorada → Diamante → Antigüedad).
    </p>
    
    <?php if (!$grids_migrated && $cpt_grids_count > 0): ?>
    <!-- Panel de migración -->
    <div class="migration-panel">
        <h3 style="margin: 0 0 10px 0;">
            <span class="dashicons dashicons-update" style="margin-right: 5px;"></span>
            Migración Disponible
        </h3>
        <p style="margin: 0 0 10px 0;">
            Se encontraron <strong><?php echo $cpt_grids_count; ?></strong> grillas en el sistema anterior. 
            Puedes migrarlas al nuevo formato organizado por niveles de membresía.
        </p>
        <form method="post" style="margin: 0;">
            <?php wp_nonce_field('starter_promotional_grids_action'); ?>
            <input type="hidden" name="action" value="migrate_from_cpt">
            <button type="submit" class="button button-primary">
                <span class="dashicons dashicons-migrate" style="margin-top: 4px;"></span>
                Migrar Grillas Existentes
            </button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Panel de herramientas -->
    <div class="tools-panel">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
            <div>
                <h3>
                    <span class="dashicons dashicons-admin-tools" style="margin-right: 5px;"></span>
                    Herramientas
                </h3>
                <p class="description" style="margin: 0;">
                    Gestiona las grillas en cascada entre niveles de membresía.
                </p>
            </div>
            <div class="tools-buttons">
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('starter_promotional_grids_action'); ?>
                    <input type="hidden" name="action" value="cascade_update">
                    <button type="submit" class="button button-primary" onclick="return confirm('¿Propagar grillas de niveles superiores a inferiores?');">
                        <span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
                        Actualizar Grillas
                    </button>
                </form>
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('starter_promotional_grids_action'); ?>
                    <input type="hidden" name="action" value="remove_duplicates">
                    <button type="submit" class="button" onclick="return confirm('¿Eliminar grillas duplicadas?');">
                        <span class="dashicons dashicons-dismiss" style="margin-top: 4px;"></span>
                        Limpiar Duplicados
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <form method="post" id="grids-form">
        <?php wp_nonce_field('starter_promotional_grids_action'); ?>
        <input type="hidden" name="action" value="save_grids_by_level">
        
        <div class="membership-cards-grid">
            <?php 
            // Pre-calcular grillas heredadas para cada nivel
            // Los niveles superiores heredan de los inferiores (ej: Diamante hereda de Dorada, Plateada, etc.)
            $all_inherited = array();
            for ($lvl = 1; $lvl <= 5; $lvl++) {
                $all_inherited[$lvl] = fipg_get_inherited_grids($lvl, $saved_grids_by_level);
            }
            
            foreach ($display_order as $level_id):
                $level = $membership_levels[$level_id];
                $own_grids = isset($saved_grids_by_level[$level_id]) ? $saved_grids_by_level[$level_id] : array();
                
                // Exclusiones
                $cascade_excluded = fipg_get_cascade_exclusions($level_id);
                $own_excluded = fipg_get_excluded_grids($level_id);
                
                // Grillas heredadas filtradas
                $inherited_grids = isset($all_inherited[$level_id]) ? $all_inherited[$level_id] : array();
                $filtered_inherited = fipg_filter_inherited_grids($inherited_grids, $level_id, $cascade_excluded);
                
                // Contar heredadas activas
                $inherited_active_count = 0;
                foreach ($filtered_inherited as $grid) {
                    if (!in_array($grid['id'], $own_excluded)) {
                        $inherited_active_count++;
                    }
                }
                
                // Productos disponibles para este nivel
                $available_products = $products_by_level[$level_id];
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
                        <span class="stat-own"><?php echo count($own_grids); ?> propias</span>
                        <?php if ($level_id > 0): ?>
                            <span class="stat-inherited"><?php echo $inherited_active_count; ?> heredadas</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="membership-body">
                    <?php if ($level_id > 0 && !empty($filtered_inherited)): ?>
                    <!-- Grillas heredadas -->
                    <div class="inherited-section">
                        <div class="section-label">
                            <span class="dashicons dashicons-arrow-up-alt"></span>
                            Grillas heredadas de niveles inferiores: <strong class="inherited-count"><?php echo $inherited_active_count; ?></strong>
                            <span class="inherited-hint" style="color: #d63638;">(click para eliminar permanentemente)</span>
                        </div>
                        <div class="inherited-items">
                            <?php foreach ($filtered_inherited as $grid): 
                                $is_excluded = in_array($grid['id'], $own_excluded);
                                $from_level = $membership_levels[$grid['from_level'] ?? 0];
                                $type_label = $grid['type'] === 'category' ? 'Cat' : 'Def';
                            ?>
                            <label class="grid-chip inherited <?php echo $is_excluded ? 'excluded' : ''; ?>" 
                                   title="Click para marcar y eliminar al guardar">
                                <input type="checkbox" 
                                       name="excluded_grids[<?php echo $level_id; ?>][]" 
                                       value="<?php echo esc_attr($grid['id']); ?>"
                                       class="inherited-exclude-checkbox"
                                       <?php checked($is_excluded); ?>
                                       style="display: none;">
                                <span class="chip-icon" style="color: <?php echo esc_attr($from_level['color']); ?>"><?php echo esc_html($from_level['icon']); ?></span>
                                <span class="chip-text"><?php echo esc_html($grid['title'] ?: 'Sin título'); ?></span>
                                <span class="chip-type"><?php echo esc_html($type_label); ?></span>
                                <span class="chip-exclude-icon"><?php echo $is_excluded ? '✓' : '✗'; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Grillas propias -->
                    <div class="own-section">
                        <div class="section-label">
                            <span>
                                <span class="dashicons dashicons-star-filled" style="color: <?php echo esc_attr($level['color']); ?>"></span>
                                Grillas propias de <?php echo esc_html($level['name']); ?>
                            </span>
                            <button type="button" class="button button-small add-grid-btn" data-level="<?php echo $level_id; ?>">
                                <span class="dashicons dashicons-plus-alt2"></span> Agregar
                            </button>
                        </div>
                        
                        <div class="own-grids-list" id="grids-level-<?php echo $level_id; ?>">
                            <?php if (empty($own_grids)): ?>
                                <p class="no-grids">No hay grillas propias para este nivel.</p>
                            <?php else: ?>
                                <?php foreach ($own_grids as $idx => $grid): ?>
                                <div class="grid-item <?php echo empty($grid['enabled']) ? 'disabled' : ''; ?>" data-index="<?php echo $idx; ?>">
                                    <div class="grid-item-header">
                                        <strong class="grid-title-display"><?php echo esc_html($grid['title'] ?: 'Sin título'); ?></strong>
                                        <span class="grid-type-badge <?php echo $grid['type'] === 'category' ? 'category' : ''; ?>">
                                            <?php echo $grid['type'] === 'category' ? 'Categoría' : 'Por defecto'; ?>
                                        </span>
                                        <span class="grid-products-count"><?php echo count($grid['products']); ?> productos</span>
                                        <button type="button" class="button-link toggle-grid-details">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                        </button>
                                        <button type="button" class="button-link delete-grid-btn" title="Eliminar">
                                            <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                                        </button>
                                    </div>
                                    <div class="grid-item-details" style="display: none;">
                                        <input type="hidden" name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][id]" value="<?php echo esc_attr($grid['id']); ?>">
                                        
                                        <div class="grid-field-row">
                                            <label>Título:</label>
                                            <input type="text" name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][title]" 
                                                   class="grid-title-input"
                                                   value="<?php echo esc_attr($grid['title']); ?>" placeholder="Nombre de la grilla">
                                        </div>
                                        
                                        <div class="grid-field-row">
                                            <label>Tipo:</label>
                                            <select name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][type]" class="grid-type-select">
                                                <option value="default" <?php selected($grid['type'], 'default'); ?>>🏠 Por defecto (Home)</option>
                                                <option value="category" <?php selected($grid['type'], 'category'); ?>>📁 Por categoría</option>
                                            </select>
                                        </div>
                                        
                                        <div class="grid-field-row">
                                            <label>Visibilidad:</label>
                                            <select name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][visibility_mode]" class="visibility-mode-select">
                                                <option value="cascade" <?php selected($grid['visibility_mode'] ?? 'cascade', 'cascade'); ?>>📈 Cascada (este nivel y superiores)</option>
                                                <option value="exact" <?php selected($grid['visibility_mode'] ?? 'cascade', 'exact'); ?>>🎯 Exacto (solo este nivel)</option>
                                            </select>
                                        </div>
                                        
                                        <div class="grid-field-row category-row" style="<?php echo $grid['type'] !== 'category' ? 'display: none;' : ''; ?>">
                                            <label>Categoría:</label>
                                            <select name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][category_id]">
                                                <option value="">-- Seleccionar --</option>
                                                <?php 
                                                $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
                                                foreach ($categories as $cat): 
                                                ?>
                                                <option value="<?php echo $cat->term_id; ?>" <?php selected($grid['category_id'], $cat->term_id); ?>>
                                                    <?php echo esc_html($cat->name); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="grid-field-row">
                                            <label>Productos:</label>
                                            <div class="products-selector">
                                                <?php for ($p = 0; $p < 3; $p++): 
                                                    $selected_product = isset($grid['products'][$p]) ? $grid['products'][$p] : '';
                                                ?>
                                                <select name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][products][]" class="product-select">
                                                    <option value="">-- Seleccionar --</option>
                                                    <?php foreach ($available_products as $product): ?>
                                                    <option value="<?php echo $product->ID; ?>" <?php selected($selected_product, $product->ID); ?>>
                                                        <?php echo esc_html($product->post_title); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="grid-field-row grid-checkboxes">
                                            <label>
                                                <input type="checkbox" name="grids_by_level[<?php echo $level_id; ?>][<?php echo $idx; ?>][enabled]" 
                                                       value="1" <?php checked(!empty($grid['enabled'])); ?>>
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
            <?php endforeach; ?>
        </div>
        
        <div class="submit-section">
            <button type="submit" class="button button-primary button-large">
                <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                Guardar Todas las Grillas
            </button>
        </div>
    </form>
</div>

<?php 
// Renderizar estilos y scripts
fipg_render_admin_styles();
fipg_render_admin_scripts($products_by_level, $membership_levels);
?>
