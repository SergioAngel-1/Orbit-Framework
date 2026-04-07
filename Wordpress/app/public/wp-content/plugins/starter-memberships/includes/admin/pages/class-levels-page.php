<?php
/**
 * Página de Niveles de Membresía
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Pages
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Levels_Page {
    
    /**
     * Renderizar página de niveles
     */
    public static function render() {
        $levels = Starter_Memberships::get_all_membership_levels();
        
        ?>
        <div class="wrap">
            <h1>🏆 <?php _e('Niveles de Membresía', 'starter-memberships'); ?></h1>
            
            <p class="description">
                <?php _e('Los niveles de membresía están predefinidos. Para crear productos de membresía, ve a Productos → Agregar nuevo y marca la opción "Este es un producto de membresía".', 'starter-memberships'); ?>
            </p>
            
            <?php self::render_levels_table($levels); ?>
            <?php self::render_instructions(); ?>
        </div>
        <?php
    }
    
    /**
     * Obtener etiqueta de periodicidad
     */
    private static function get_duration_label($duration_days) {
        $labels = [
            '0' => __('Indefinida', 'starter-memberships'),
            '30' => __('Mensual', 'starter-memberships'),
            '60' => __('Bimestral', 'starter-memberships'),
            '90' => __('Trimestral', 'starter-memberships'),
            '180' => __('Semestral', 'starter-memberships'),
            '365' => __('Anual', 'starter-memberships'),
        ];
        
        return $labels[$duration_days] ?? sprintf(__('%d días', 'starter-memberships'), $duration_days);
    }
    
    /**
     * Renderizar tabla de niveles
     */
    private static function render_levels_table($levels) {
        ?>
        <table class="widefat striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th><?php _e('Nivel', 'starter-memberships'); ?></th>
                    <th><?php _e('Nombre', 'starter-memberships'); ?></th>
                    <th><?php _e('Precio', 'starter-memberships'); ?></th>
                    <th><?php _e('Periodicidad', 'starter-memberships'); ?></th>
                    <th><?php _e('Virtual Coins/mes', 'starter-memberships'); ?></th>
                    <th><?php _e('Descripción', 'starter-memberships'); ?></th>
                    <th><?php _e('Producto', 'starter-memberships'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($levels as $level_id => $level) : 
                    // Obtener producto de membresía asociado a este nivel
                    $product = function_exists('starter_get_membership_product_by_level') 
                        ? starter_get_membership_product_by_level($level_id) 
                        : null;
                    $product_id = $product ? $product->get_id() : null;
                    $product_price = $product ? $product->get_price() : null;
                    $product_name = $product ? $product->get_name() : null;
                    
                    // Obtener datos del producto
                    $product_monthly_points = $product_id ? get_post_meta($product_id, '_membership_monthly_points', true) : null;
                    $product_duration = $product_id ? get_post_meta($product_id, '_membership_duration_days', true) : null;
                    
                    // Usar datos del producto si existen, sino usar defaults del nivel
                    $monthly_points = $product_monthly_points ? intval($product_monthly_points) : $level['monthly_points'];
                    $duration_label = $product_duration !== null && $product_duration !== '' 
                        ? self::get_duration_label($product_duration) 
                        : '—';
                ?>
                    <tr>
                        <td>
                            <span style="font-size: 24px;"><?php echo esc_html($level['icon']); ?></span>
                            <strong><?php echo $level_id; ?></strong>
                        </td>
                        <td>
                            <span style="color: <?php echo esc_attr($level['color']); ?>; font-weight: bold;">
                                <?php echo esc_html($level['name']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($product && $product_price > 0) : ?>
                                <?php echo starter_format_cop($product_price); ?>
                            <?php elseif ($level_id === 0 || $level_id === 5) : ?>
                                <em><?php _e('Gratis', 'starter-memberships'); ?></em>
                            <?php else : ?>
                                <span style="color: #d63638;">
                                    <?php _e('Sin producto', 'starter-memberships'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($product) : ?>
                                <?php echo esc_html($duration_label); ?>
                            <?php elseif ($level_id === 0 || $level_id === 5) : ?>
                                <em><?php _e('N/A', 'starter-memberships'); ?></em>
                            <?php else : ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo starter_format_fc($monthly_points); ?>
                        </td>
                        <td><?php echo esc_html($level['description']); ?></td>
                        <td>
                            <?php if ($product) : ?>
                                <a href="<?php echo get_edit_post_link($product_id); ?>" title="<?php echo esc_attr($product_name); ?>">
                                    <?php echo esc_html(wp_trim_words($product_name, 3, '...')); ?>
                                </a>
                            <?php elseif ($level_id === 0 || $level_id === 5) : ?>
                                <em style="color: #666;"><?php _e('N/A', 'starter-memberships'); ?></em>
                            <?php else : ?>
                                <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button button-small">
                                    <?php _e('Crear', 'starter-memberships'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Renderizar instrucciones
     */
    private static function render_instructions() {
        ?>
        <div class="card" style="margin-top: 20px; padding: 20px;">
            <h3 style="margin-top: 0;">📝 <?php _e('Crear Productos de Membresía', 'starter-memberships'); ?></h3>
            <ol>
                <li><?php _e('Ve a <strong>Productos → Agregar nuevo</strong>', 'starter-memberships'); ?></li>
                <li><?php _e('Crea un producto <strong>Simple</strong> y <strong>Virtual</strong>', 'starter-memberships'); ?></li>
                <li><?php _e('En la pestaña <strong>Membresía</strong>, marca "Este es un producto de membresía"', 'starter-memberships'); ?></li>
                <li><?php _e('Selecciona el nivel de membresía correspondiente', 'starter-memberships'); ?></li>
                <li><?php _e('Asigna el producto a la categoría <strong>Membresías</strong>', 'starter-memberships'); ?></li>
            </ol>
            <p>
                <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button button-primary">
                    <?php _e('Crear Producto de Membresía', 'starter-memberships'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
