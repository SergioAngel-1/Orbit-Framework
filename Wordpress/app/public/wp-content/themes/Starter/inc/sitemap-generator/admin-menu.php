<?php
/**
 * Sitemap Generator — Menú y página de administración.
 *
 * Bug #7 corregido: todos los echo de STARTER_SITEMAP_BASE_URL usan
 *   esc_html() para prevenir XSS si la constante fuese modificada en runtime.
 * Bug #8 aplicado: usa starter_sitemap_excluded_slugs() como fuente de verdad.
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra el menú de administración.
 */
function starter_add_sitemap_generator_menu() {
    add_menu_page(
        'Generar Sitemaps',
        'Generar Sitemaps',
        'manage_options',
        'starter-sitemap-generator',
        'starter_render_sitemap_generator_page',
        'dashicons-admin-site-alt3',
        81
    );
}
add_action('admin_menu', 'starter_add_sitemap_generator_menu');

/**
 * Renderiza la página de administración del generador de sitemaps.
 */
function starter_render_sitemap_generator_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
    }

    $messages = [];

    // Procesar acciones POST
    if (isset($_POST['starter_generate_sitemaps']) && check_admin_referer('starter_sitemap_action', 'starter_sitemap_nonce')) {
        $action = sanitize_text_field($_POST['starter_generate_sitemaps']);

        if ($action === 'categories' || $action === 'all') {
            $messages[] = starter_generate_categories_sitemap();
        }

        if ($action === 'products' || $action === 'all') {
            $messages[] = starter_generate_products_sitemap();
        }

        // Siempre actualizar el sitemap-index después de generar
        $messages[] = starter_update_sitemap_index();
    }

    // Info del directorio
    $sitemaps_dir  = starter_get_sitemaps_dir();
    $dir_exists    = is_dir($sitemaps_dir);
    $dir_writable  = $dir_exists && is_writable($sitemaps_dir);

    // Estado de cada archivo
    $files_info    = [];
    $sitemap_files = ['categories.xml', 'products.xml', 'pages.xml', 'legal.xml'];
    foreach ($sitemap_files as $file) {
        $path = $sitemaps_dir . '/' . $file;
        if (file_exists($path)) {
            $files_info[$file] = [
                'exists'   => true,
                'size'     => size_format(filesize($path)),
                'modified' => date('Y-m-d H:i:s', filemtime($path)),
            ];
        } else {
            $files_info[$file] = ['exists' => false];
        }
    }

    // Contar categorías públicas (Bug #8: fuente centralizada de slugs excluidos)
    $public_cat_count = 0;
    $public_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_min_membership_level', 'value' => '0', 'compare' => '='],
            ['key' => '_min_membership_level', 'compare' => 'NOT EXISTS'],
        ],
    ]);
    if (!is_wp_error($public_cats)) {
        $excluded_slugs   = starter_sitemap_excluded_slugs();
        $public_cats      = array_filter($public_cats, function ($cat) use ($excluded_slugs) {
            return !in_array($cat->slug, $excluded_slugs, true);
        });
        $public_cat_count = count($public_cats);
    }

    // Contar productos públicos en stock (mismos filtros que el generador)
    $public_product_count = 0;
    if (!empty($public_cats)) {
        $public_cat_ids  = array_map(function ($cat) { return $cat->term_id; }, $public_cats);
        $public_prod_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $public_cat_ids,
                'operator' => 'IN',
            ]],
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => '_is_membership_product', 'compare' => 'NOT EXISTS'],
                    ['key' => '_is_membership_product', 'value' => 'yes', 'compare' => '!='],
                ],
                ['key' => '_stock_status', 'value' => 'instock', 'compare' => '='],
            ],
        ]);
        $public_product_count = count($public_prod_ids);
    }

    // Total de <url> en los sitemaps generados (categories.xml + products.xml)
    $total_urls = 0;
    foreach (['categories.xml', 'products.xml'] as $_sf) {
        $_sp = $sitemaps_dir . '/' . $_sf;
        if (file_exists($_sp)) {
            $total_urls += substr_count(file_get_contents($_sp), '<url>');
        }
    }
    unset($_sf, $_sp);

    // Última generación: mtime más reciente entre categories.xml y products.xml
    $last_gen_ts  = 0;
    foreach (['categories.xml', 'products.xml'] as $_sf) {
        $_sp = $sitemaps_dir . '/' . $_sf;
        if (file_exists($_sp)) {
            $last_gen_ts = max($last_gen_ts, filemtime($_sp));
        }
    }
    unset($_sf, $_sp);
    $last_gen_str = $last_gen_ts ? wp_date('Y-m-d H:i:s', $last_gen_ts) : '—';

    // Próxima ejecución del cron
    $next_cron_ts  = wp_next_scheduled(STARTER_SITEMAP_CRON_HOOK);
    $next_cron_str = $next_cron_ts ? wp_date('Y-m-d H:i:s', $next_cron_ts) : 'No programado';
    $cron_active   = (bool) $next_cron_ts;

    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-sitemap" style="font-size: 28px; margin-right: 8px; vertical-align: middle;"></span> Generar Sitemaps SEO</h1>
        <p class="description" style="font-size: 14px; margin-top: 8px;">
            Genera sitemaps XML dinámicos para Google Search Console. Solo incluye categorías y productos <strong>sin membresía requerida</strong> (públicos).
        </p>

        <?php if (!empty($messages)) : ?>
            <?php foreach ($messages as $msg) : ?>
                <div class="notice notice-<?php echo $msg['success'] ? 'success' : 'error'; ?> is-dismissible" style="margin-top: 15px;">
                    <p>
                        <strong><?php echo $msg['success'] ? '✅' : '❌'; ?></strong>
                        <?php echo esc_html($msg['message']); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$dir_exists) : ?>
            <div class="notice notice-error" style="margin-top: 15px;">
                <p><strong>❌ Error:</strong> Directorio de sitemaps no encontrado: <code><?php echo esc_html($sitemaps_dir); ?></code></p>
            </div>
        <?php elseif (!$dir_writable) : ?>
            <div class="notice notice-error" style="margin-top: 15px;">
                <p><strong>❌ Error:</strong> El directorio de sitemaps no tiene permisos de escritura: <code><?php echo esc_html($sitemaps_dir); ?></code></p>
            </div>
        <?php endif; ?>

        <!-- Estadísticas rápidas -->
        <div style="display: flex; gap: 16px; margin-top: 20px; flex-wrap: wrap;">
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 28px; font-weight: 700; color: #2271b1;"><?php echo (int) $public_cat_count; ?></div>
                <div style="color: #50575e; font-size: 13px;">Categorías públicas</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #0ea5e9; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 28px; font-weight: 700; color: #0ea5e9;"><?php echo (int) $public_product_count; ?></div>
                <div style="color: #50575e; font-size: 13px;">Productos públicos en stock</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #0d9488; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 28px; font-weight: 700; color: #0d9488;"><?php echo (int) $total_urls; ?></div>
                <div style="color: #50575e; font-size: 13px;">URLs indexadas (ES+EN)</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #00a32a; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 28px; font-weight: 700; color: #00a32a;"><?php echo count($sitemap_files); ?></div>
                <div style="color: #50575e; font-size: 13px;">Archivos de sitemap</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo $last_gen_ts ? '#ea580c' : '#d63638'; ?>; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 14px; font-weight: 700; color: <?php echo $last_gen_ts ? '#ea580c' : '#d63638'; ?>; line-height: 1.4;">
                    <?php echo esc_html($last_gen_str); ?>
                </div>
                <div style="color: #50575e; font-size: 13px; margin-top: 4px;">Última generación</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #dba617; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 28px; font-weight: 700; color: #dba617;"><?php echo $dir_writable ? '✓' : '✗'; ?></div>
                <div style="color: #50575e; font-size: 13px;">Permisos de escritura</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo $cron_active ? '#8b5cf6' : '#d63638'; ?>; padding: 16px 20px; border-radius: 4px; min-width: 200px;">
                <div style="font-size: 14px; font-weight: 700; color: <?php echo $cron_active ? '#8b5cf6' : '#d63638'; ?>; line-height: 1.4;">
                    <?php echo $cron_active ? '🕛 ' . esc_html($next_cron_str) : '✗ No programado'; ?>
                </div>
                <div style="color: #50575e; font-size: 13px; margin-top: 4px;">Próxima ejecución automática</div>
            </div>
        </div>

        <!-- Acciones de generación -->
        <div style="display: flex; gap: 16px; margin-top: 24px; flex-wrap: wrap;">
            <!-- Generar todo -->
            <div style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; flex: 1; min-width: 280px;">
                <h2 style="margin-top: 0; font-size: 16px;">🚀 Generar Todo</h2>
                <p style="color: #50575e; font-size: 13px;">
                    Regenera los sitemaps de categorías y productos, y actualiza el sitemap-index.xml con la fecha de hoy.
                </p>
                <form method="post">
                    <?php wp_nonce_field('starter_sitemap_action', 'starter_sitemap_nonce'); ?>
                    <input type="hidden" name="starter_generate_sitemaps" value="all" />
                    <button type="submit" class="button button-primary button-hero" <?php echo !$dir_writable ? 'disabled' : ''; ?>>
                        <span class="dashicons dashicons-update" style="margin-top: 4px;"></span> Generar Todos los Sitemaps
                    </button>
                </form>
            </div>

            <!-- Solo categorías -->
            <div style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; flex: 1; min-width: 280px;">
                <h2 style="margin-top: 0; font-size: 16px;">📂 Solo Categorías</h2>
                <p style="color: #50575e; font-size: 13px;">
                    Regenera únicamente <code>categories.xml</code> con las categorías públicas actuales.
                </p>
                <form method="post">
                    <?php wp_nonce_field('starter_sitemap_action', 'starter_sitemap_nonce'); ?>
                    <input type="hidden" name="starter_generate_sitemaps" value="categories" />
                    <button type="submit" class="button button-secondary" <?php echo !$dir_writable ? 'disabled' : ''; ?>>
                        Generar Categorías
                    </button>
                </form>
            </div>

            <!-- Solo productos -->
            <div style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; flex: 1; min-width: 280px;">
                <h2 style="margin-top: 0; font-size: 16px;">🛍️ Solo Productos</h2>
                <p style="color: #50575e; font-size: 13px;">
                    Regenera únicamente <code>products.xml</code> con productos en categorías sin membresía.
                </p>
                <form method="post">
                    <?php wp_nonce_field('starter_sitemap_action', 'starter_sitemap_nonce'); ?>
                    <input type="hidden" name="starter_generate_sitemaps" value="products" />
                    <button type="submit" class="button button-secondary" <?php echo !$dir_writable ? 'disabled' : ''; ?>>
                        Generar Productos
                    </button>
                </form>
            </div>
        </div>

        <!-- Estado actual de archivos -->
        <h2 style="margin-top: 30px;">📄 Estado de Archivos</h2>
        <table class="widefat striped" style="max-width: 800px;">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Estado</th>
                    <th>Tamaño</th>
                    <th>Última modificación</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>sitemap-index.xml</code></td>
                    <?php
                    $index_path = starter_get_sitemap_index_path();
                    if (file_exists($index_path)) : ?>
                        <td><span style="color: #00a32a;">✓ Existe</span></td>
                        <td><?php echo esc_html(size_format(filesize($index_path))); ?></td>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', filemtime($index_path))); ?></td>
                    <?php else : ?>
                        <td><span style="color: #d63638;">✗ No existe</span></td>
                        <td>—</td>
                        <td>—</td>
                    <?php endif; ?>
                </tr>
                <?php foreach ($files_info as $filename => $info) : ?>
                    <tr>
                        <td><code>sitemaps/<?php echo esc_html($filename); ?></code></td>
                        <?php if ($info['exists']) : ?>
                            <td><span style="color: #00a32a;">✓ Existe</span></td>
                            <td><?php echo esc_html($info['size']); ?></td>
                            <td><?php echo esc_html($info['modified']); ?></td>
                        <?php else : ?>
                            <td><span style="color: #d63638;">✗ No existe</span></td>
                            <td>—</td>
                            <td>—</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Info técnica -->
        <div style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 16px; border-radius: 4px; margin-top: 24px; max-width: 800px;">
            <h3 style="margin-top: 0; font-size: 14px;">ℹ️ Información Técnica</h3>
            <ul style="font-size: 13px; color: #50575e; margin: 0; padding-left: 20px;">
                <?php /* Bug #7: esc_html() en todos los echo de la constante */ ?>
                <li><strong>Base URL:</strong> <code><?php echo esc_html(STARTER_SITEMAP_BASE_URL); ?></code></li>
                <li><strong>Directorio sitemaps:</strong> <code><?php echo esc_html($sitemaps_dir); ?></code></li>
                <li><strong>Sitemap index:</strong> <code><?php echo esc_html(starter_get_sitemap_index_path()); ?></code></li>
                <li><strong>robots.txt apunta a:</strong> <code><?php echo esc_html(STARTER_SITEMAP_BASE_URL); ?>/sitemap-index.xml</code></li>
                <li>Los productos de membresía (<code>_is_membership_product = yes</code>) se excluyen automáticamente.</li>
                <li>Un producto se incluye solo si <strong>todas</strong> sus categorías son públicas (nivel 0) y tiene <code>_stock_status = instock</code>.</li>
                <li>Cada URL se genera en versión ES y EN con hreflang bidireccional.</li>
                <li>
                    <strong>Cron automático:</strong>
                    <?php if ($cron_active) : ?>
                        Activo — hook <code><?php echo esc_html(STARTER_SITEMAP_CRON_HOOK); ?></code>,
                        próxima ejecución: <code><?php echo esc_html($next_cron_str); ?></code>.
                    <?php else : ?>
                        <span style="color: #d63638;">No programado.</span>
                        Visita cualquier página del sitio para que WordPress registre el evento.
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
    <?php
}
