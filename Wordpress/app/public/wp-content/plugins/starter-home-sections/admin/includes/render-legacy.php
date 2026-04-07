<?php
/**
 * Renderizado del panel de secciones legacy
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza el panel de secciones del formato antiguo
 */
function fihs_render_legacy_panel($legacy_sections, $layout_types, $zones) {
    if (empty($legacy_sections)) {
        return;
    }
    ?>
    <div class="legacy-sections-panel">
        <h2>
            <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
            Secciones del Formato Antiguo (<?php echo count($legacy_sections); ?>)
        </h2>
        <p class="description">Estas secciones están en el formato antiguo. Puedes migrarlas al nuevo formato (se asignarán al nivel Zanahoria/Público) o eliminarlas.</p>
        
        <table class="wp-list-table widefat fixed striped" style="margin: 15px 0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Título</th>
                    <th>Layout</th>
                    <th>Zona</th>
                    <th>Categoría</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($legacy_sections as $section_id => $section): 
                    $cat = get_term($section['category_id'] ?? 0, 'product_cat');
                ?>
                <tr>
                    <td><code><?php echo esc_html($section_id); ?></code></td>
                    <td><?php echo esc_html($section['title'] ?: ($cat && !is_wp_error($cat) ? $cat->name : 'Sin título')); ?></td>
                    <td><?php echo esc_html($layout_types[$section['layout_type']]['name'] ?? $section['layout_type']); ?></td>
                    <td><?php echo esc_html($zones[$section['zone']] ?? $section['zone']); ?></td>
                    <td><?php echo ($cat && !is_wp_error($cat)) ? esc_html($cat->name) : '<em>No encontrada</em>'; ?></td>
                    <td>
                        <?php if (!empty($section['enabled'])): ?>
                            <span style="color: green;">✓ Activa</span>
                        <?php else: ?>
                            <span style="color: #999;">Inactiva</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="legacy-actions">
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('starter_home_sections_action'); ?>
                <input type="hidden" name="action" value="migrate_to_new_format">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-migrate" style="margin-top: 4px;"></span>
                    Migrar al Nuevo Formato (Nivel Zanahoria)
                </button>
            </form>
            <form method="post" style="display: inline; margin-left: 10px;">
                <?php wp_nonce_field('starter_home_sections_action'); ?>
                <input type="hidden" name="action" value="delete_legacy_sections">
                <button type="submit" class="button button-link-delete" onclick="return confirm('¿Estás seguro de eliminar todas las secciones del formato antiguo? Esta acción no se puede deshacer.')">
                    <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
                    Eliminar Todas
                </button>
            </form>
        </div>
    </div>
    <?php
}
