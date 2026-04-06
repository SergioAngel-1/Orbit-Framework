<?php
/**
 * Página de Dashboard de Membresías
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Pages
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Dashboard_Page {
    
    /**
     * Renderizar página de dashboard
     */
    public static function render() {
        $stats = Starter_Stats_Service::get_stats();
        $levels = Starter_Memberships::get_all_membership_levels();
        
        ?>
        <div class="wrap">
            <h1>🥕 <?php _e('Membresías Starter', 'starter-memberships'); ?></h1>
            
            <?php self::render_stats_cards($stats); ?>
            <?php self::render_quick_actions(); ?>
            <?php self::render_level_distribution($stats, $levels); ?>
        </div>
        <?php
    }
    
    /**
     * Renderizar tarjetas de estadísticas
     */
    private static function render_stats_cards($stats) {
        ?>
        <div class="starter-dashboard" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
            
            <div class="card" style="padding: 20px;">
                <h3 style="margin-top: 0;">📊 <?php _e('Membresías Activas', 'starter-memberships'); ?></h3>
                <p style="font-size: 36px; font-weight: bold; margin: 10px 0; color: #2271b1;">
                    <?php echo number_format($stats['active_memberships']); ?>
                </p>
                <p class="description"><?php _e('Usuarios con membresía activa', 'starter-memberships'); ?></p>
            </div>
            
            <div class="card" style="padding: 20px;">
                <h3 style="margin-top: 0;">🌸 <?php _e('FC Otorgados', 'starter-memberships'); ?></h3>
                <p style="font-size: 36px; font-weight: bold; margin: 10px 0; color: #dba617;">
                    <?php echo starter_format_fc($stats['total_points_awarded']); ?>
                </p>
                <p class="description"><?php _e('Por activaciones de membresía este mes', 'starter-memberships'); ?></p>
            </div>
            
            <div class="card" style="padding: 20px;">
                <h3 style="margin-top: 0;">⏰ <?php _e('Por Expirar', 'starter-memberships'); ?></h3>
                <p style="font-size: 36px; font-weight: bold; margin: 10px 0; color: #d63638;">
                    <?php echo number_format($stats['expiring_soon']); ?>
                </p>
                <p class="description"><?php _e('Expiran en los próximos 7 días', 'starter-memberships'); ?></p>
                <?php if ($stats['expiring_soon'] > 0) : ?>
                    <p style="margin-top: 10px;">
                        <a href="<?php echo admin_url('users.php?membership_level=expiring'); ?>" class="button button-small">
                            <?php _e('Ver usuarios', 'starter-memberships'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="card" style="padding: 20px;">
                <h3 style="margin-top: 0;">❌ <?php _e('Expiradas', 'starter-memberships'); ?></h3>
                <p style="font-size: 36px; font-weight: bold; margin: 10px 0; color: #996800;">
                    <?php echo number_format($stats['expired_count']); ?>
                </p>
                <p class="description"><?php _e('Membresías que ya expiraron', 'starter-memberships'); ?></p>
                <?php if ($stats['expired_count'] > 0) : ?>
                    <p style="margin-top: 10px;">
                        <a href="<?php echo admin_url('users.php?membership_level=expired'); ?>" class="button button-small">
                            <?php _e('Ver usuarios', 'starter-memberships'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizar distribución por nivel
     */
    private static function render_level_distribution($stats, $levels) {
        ?>
        <div class="card" style="margin-top: 20px; padding: 20px;">
            <h3 style="margin-top: 0;">📈 <?php _e('Distribución por Nivel', 'starter-memberships'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Nivel', 'starter-memberships'); ?></th>
                        <th><?php _e('Usuarios', 'starter-memberships'); ?></th>
                        <th><?php _e('Porcentaje', 'starter-memberships'); ?></th>
                        <th><?php _e('FC', 'starter-memberships'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $level_id => $level) : 
                        $count = $stats['by_level'][$level_id] ?? 0;
                        $percentage = $stats['active_memberships'] > 0 
                            ? round(($count / $stats['active_memberships']) * 100, 1) 
                            : 0;
                    ?>
                        <tr>
                            <td>
                                <span style="color: <?php echo esc_attr($level['color']); ?>;">
                                    <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($count); ?></td>
                            <td>
                                <div style="background: #f0f0f0; border-radius: 3px; overflow: hidden;">
                                    <div style="background: <?php echo esc_attr($level['color']); ?>; width: <?php echo $percentage; ?>%; height: 20px;"></div>
                                </div>
                                <?php echo $percentage; ?>%
                            </td>
                            <td><?php echo starter_format_fc($level['monthly_points']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php $frozen_count = $stats['frozen_count'] ?? 0; ?>
                    <tr style="background: #f0f8ff;">
                        <td>
                            <span style="color: #0073aa;">
                                ❄️ <?php _e('Membresías Congeladas', 'starter-memberships'); ?>
                            </span>
                        </td>
                        <td><?php echo number_format($frozen_count); ?></td>
                        <td>
                            <em style="color: #666;"><?php _e('No contabilizadas en activas', 'starter-memberships'); ?></em>
                        </td>
                        <td>
                            <?php if ($frozen_count > 0) : ?>
                                <a href="<?php echo admin_url('users.php?membership_level=frozen'); ?>" class="button button-small">
                                    <?php _e('Ver usuarios', 'starter-memberships'); ?>
                                </a>
                            <?php else : ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Renderizar acciones rápidas
     */
    private static function render_quick_actions() {
        ?>
        <div class="card" style="margin-top: 20px; padding: 20px;">
            <h3 style="margin-top: 0;">⚡ <?php _e('Acciones Rápidas', 'starter-memberships'); ?></h3>
            <p>
                <a href="<?php echo admin_url('edit.php?post_type=product&product_cat=membresias'); ?>" class="button button-primary">
                    <?php _e('Ver Productos de Membresía', 'starter-memberships'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=starter-memberships-settings'); ?>" class="button">
                    <?php _e('Configuración', 'starter-memberships'); ?>
                </a>
                <a href="<?php echo admin_url('users.php?membership_level=0'); ?>" class="button">
                    <?php _e('Usuarios sin Membresía', 'starter-memberships'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
