<?php
/**
 * Herramientas de Recálculo — Punto de entrada
 * 
 * Carga los 3 módulos de recálculo y expone la página admin
 * con las utilidades compartidas (paginación, resultados).
 * 
 * Módulos:
 * - recalculate-commissions.php  → Comisiones de referidos (primer aporte vs posterior)
 * - recalculate-memberships.php  → FC por membresía (monthly/activation vs producto WC)
 * - recalculate-checkout.php     → FC checkout invisible (purchase sin débito compensatorio)
 * 
 * @package Starter
 * @since 1.2.0 (refactorizado desde recalculate-commissions.php monolítico)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar módulos de recálculo
require_once __DIR__ . '/recalculate-commissions.php';
require_once __DIR__ . '/recalculate-memberships.php';
require_once __DIR__ . '/recalculate-checkout.php';

/**
 * Página admin de recálculo (con subtabs: Comisiones, Membresías y Checkout)
 */
function starter_rp_recalculate_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos para acceder a esta página.', 'starter-rp'));
    }
    
    $current_subtab = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : 'commissions';
    if (!in_array($current_subtab, ['commissions', 'memberships', 'checkout'], true)) {
        $current_subtab = 'commissions';
    }
    
    ?>
    <div class="wrap starter-rp-recalculate">
        <h1><?php _e('Herramientas de Recálculo', 'starter-rp'); ?></h1>
        
        <div class="starter-rp-tabs">
            <a href="<?php echo admin_url('admin.php?page=starter-rp-dashboard'); ?>" class="tab">
                <?php _e('Dashboard', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="tab">
                <?php _e('Transacciones', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="tab">
                <?php _e('Red de Referidos', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-settings'); ?>" class="tab">
                <?php _e('Configuración', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-recalculate'); ?>" class="tab active">
                <?php _e('Recálculo', 'starter-rp'); ?>
            </a>
        </div>
        
        <div class="starter-rp-recalculate-container">
            <!-- Subtabs client-side -->
            <div class="nav-tab-wrapper wp-clearfix" style="margin-top: 15px;">
                <a href="#recalc-commissions" class="nav-tab <?php echo $current_subtab === 'commissions' ? 'nav-tab-active' : ''; ?>"><?php _e('Comisiones de Referidos', 'starter-rp'); ?></a>
                <a href="#recalc-memberships" class="nav-tab <?php echo $current_subtab === 'memberships' ? 'nav-tab-active' : ''; ?>"><?php _e('FC por Membresía', 'starter-rp'); ?></a>
                <a href="#recalc-checkout" class="nav-tab <?php echo $current_subtab === 'checkout' ? 'nav-tab-active' : ''; ?>"><?php _e('FC Checkout Invisible', 'starter-rp'); ?></a>
            </div>
            
            <div id="recalc-commissions" class="tab-content <?php echo $current_subtab === 'commissions' ? 'active' : ''; ?>">
                <?php starter_rp_render_commissions_subtab(); ?>
            </div>
            
            <div id="recalc-memberships" class="tab-content <?php echo $current_subtab === 'memberships' ? 'active' : ''; ?>">
                <?php starter_rp_render_memberships_subtab(); ?>
            </div>

            <div id="recalc-checkout" class="tab-content <?php echo $current_subtab === 'checkout' ? 'active' : ''; ?>">
                <?php starter_rp_render_checkout_subtab(); ?>
            </div>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('.starter-rp-recalculate-container .nav-tab-wrapper .nav-tab').on('click', function(e) {
            e.preventDefault();
            $('.starter-rp-recalculate-container .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.starter-rp-recalculate-container .tab-content').removeClass('active');
            $($(this).attr('href')).addClass('active');
        });
    });
    </script>
    <?php
}

/**
 * Renderizar paginación (compartido entre los 3 subtabs)
 * 
 * @param int    $current_page Página actual
 * @param int    $total_pages  Total de páginas
 * @param string $subtab       Identificador del subtab activo
 */
function starter_rp_render_pagination($current_page, $total_pages, $subtab) {
    if ($total_pages <= 1) return;
    
    $base_url = admin_url('admin.php?page=starter-rp-recalculate&subtab=' . $subtab);
    ?>
    <div class="tablenav" style="margin: 10px 0;">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php printf(__('Página %d de %d', 'starter-rp'), $current_page, $total_pages); ?></span>
            <span class="pagination-links">
                <?php if ($current_page > 1): ?>
                    <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $base_url)); ?>">&lsaquo;</a>
                <?php else: ?>
                    <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                <?php endif; ?>
                
                <span class="paging-input">
                    <strong><?php echo $current_page; ?></strong> / <?php echo $total_pages; ?>
                </span>
                
                <?php if ($current_page < $total_pages): ?>
                    <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $base_url)); ?>">&rsaquo;</a>
                <?php else: ?>
                    <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php
}

/**
 * Renderizar resultados de aplicación (compartido entre los 3 subtabs)
 * 
 * @param array $apply_results Resultado de la operación de corrección
 */
function starter_rp_render_apply_results($apply_results) {
    ?>
    <div class="starter-rp-notice success">
        <p><strong><?php _e('Correcciones aplicadas exitosamente', 'starter-rp'); ?></strong></p>
        <p><?php printf(
            __('%d transacciones corregidas. Exceso total recuperado: %s FC.', 'starter-rp'),
            $apply_results['corrected'],
            number_format($apply_results['total_excess'])
        ); ?></p>
    </div>
    
    <?php if (!empty($apply_results['users_affected'])): ?>
        <h2><?php _e('Usuarios ajustados', 'starter-rp'); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php _e('Usuario', 'starter-rp'); ?></th><th><?php _e('Ajuste en saldo', 'starter-rp'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($apply_results['users_affected'] as $u): ?>
                <tr>
                    <td><?php echo esc_html($u['name']); ?> (#<?php echo $u['user_id']; ?>)</td>
                    <td style="color: <?php echo $u['adjustment'] < 0 ? 'red' : 'green'; ?>">
                        <?php echo ($u['adjustment'] >= 0 ? '+' : '') . number_format($u['adjustment']); ?> FC
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <?php if (!empty($apply_results['errors'])): ?>
        <div class="starter-rp-notice error">
            <p><strong><?php _e('Errores:', 'starter-rp'); ?></strong></p>
            <ul><?php foreach ($apply_results['errors'] as $err): ?><li><?php echo esc_html($err); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif;
}
