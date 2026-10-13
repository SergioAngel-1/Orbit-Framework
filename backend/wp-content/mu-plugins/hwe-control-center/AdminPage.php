<?php

namespace HWE\ControlCenter;

use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\ControlCenter\Walkers\DefaultsWalker;

/**
 * Página de administración en wp-admin para el Control Center (RENDER).
 *
 * - Menú: HWE Config (entry principal en la sidebar de WP).
 * - Interfaz con pestañas para organizar las secciones de configuración.
 * - Formulario generado recursivamente desde el esquema (AdminFormWalker).
 *
 * El guardado vive en `AdminSave` y los avisos en `AdminNotices`.
 */
class AdminPage {

    private const MENU_SLUG  = 'hwe-config';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addMenuPage(): void {
        add_menu_page(
            'HWE Control Center',
            'HWE Config',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'renderPage'],
            'dashicons-admin-settings',
            80
        );
    }

    // -------------------------------------------------------------------------
    // Asset enqueuing
    // -------------------------------------------------------------------------

    public static function enqueueAssets(string $hook): void {
        if ($hook !== 'toplevel_page_hwe-config') {
            return;
        }

        $baseUrl = content_url('mu-plugins/hwe-control-center');

        $assetVersion = file_exists(dirname(__FILE__) . '/assets/admin.css')
            ? filemtime(dirname(__FILE__) . '/assets/admin.css')
            : 'v2-fallback';

        // La medioteca (`wp.media`) la usan los pickers de los tipos `image`/`file`.
        wp_enqueue_media();

        wp_enqueue_style(
            'hwe-admin',
            $baseUrl . '/assets/admin.css',
            [],
            $assetVersion
        );

        wp_enqueue_script(
            'hwe-admin',
            $baseUrl . '/assets/admin.js',
            [],
            $assetVersion,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Página principal (render + save en el mismo callback)
    // -------------------------------------------------------------------------

    public static function renderPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permiso para acceder a esta página.', 'hwe'));
        }

        $notice = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hwe_nonce'])) {
            $notice = AdminSave::handle();
        }

        $schema  = Schema::get();
        $stored  = Storage::getAll();
        $defaults = (new DefaultsWalker())->walk($schema);
        $merged  = self::deepMerge($defaults, $stored);

        // Para los secretos solo necesitamos saber si ya existe un valor (no el valor).
        $secretsExist = self::buildSecretExistenceMap();

        $walker   = new AdminFormWalker($secretsExist);
        $sections = $walker->walk($schema, $merged);

        // Inject SMTP test into integrations tab.
        $sections['integrations'] .= self::renderSmtpTest();

        // Inyecta el estado del plugin de banners en el tab "banners".
        if ( isset( $sections['banners'] ) ) {
            $sections['banners'] .= self::renderBannerStatus();
        }

        $tabs = self::buildTabs($schema, $sections);

        $formUrl = esc_url(admin_url('admin.php?page=' . self::MENU_SLUG));

        echo '<div class="wrap hwe-wrap">';

        // Header.
        echo '<div class="hwe-header">';
        echo '<div class="hwe-header-left">';
        echo '<div class="hwe-logo"><span class="dashicons dashicons-admin-settings"></span></div>';
        echo '<div class="hwe-header-text">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<p class="hwe-subtitle">' . esc_html__('Panel de configuración central para tu tienda headless', 'hwe') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="hwe-header-actions">';
        echo '<a href="' . esc_url(rest_url('hwe/v1/config')) . '" target="_blank" class="button button-secondary hwe-api-btn"><span class="dashicons dashicons-visibility"></span> ' . esc_html__('Ver API', 'hwe') . '</a>';
        echo '</div>';
        echo '</div>';

        echo $notice;

        // Form.
        echo '<form method="post" action="' . $formUrl . '" class="hwe-form">';
        wp_nonce_field(AdminSave::NONCE_KEY, 'hwe_nonce');

        // Inline fallback: hide payment provider groups until JS activates the selected one.
        echo '<style>.hwe-subgroup[data-provider]{display:none!important}.hwe-subgroup[data-provider].is-active{display:block!important}</style>';

        // Tabs wrapper.
        echo '<div class="hwe-tabs">';

        // Tab navigation.
        echo '<nav class="hwe-tab-nav">';
        foreach ($tabs as $key => $tab) {
            $active = $key === array_key_first($tabs) ? ' active' : '';
            echo '<button type="button" class="hwe-tab-btn' . $active . '" data-tab="' . esc_attr($key) . '">';
            echo '<span class="dashicons ' . esc_attr($tab['icon']) . '"></span>';
            echo '<span class="hwe-tab-label">' . esc_html($tab['label']) . '</span>';
            echo '</button>';
        }
        echo '</nav>';

        // Tab content.
        echo '<div class="hwe-tab-content">';
        foreach ($tabs as $key => $tab) {
            $active = $key === array_key_first($tabs) ? ' active' : '';
            echo '<div class="hwe-tab-panel' . $active . '" data-panel="' . esc_attr($key) . '">';
            echo '<div class="hwe-panel-header">';
            echo '<span class="dashicons ' . esc_attr($tab['icon']) . '"></span>';
            echo '<h2>' . esc_html($tab['label']) . '</h2>';
            echo '</div>';
            echo '<div class="hwe-panel-body">';
            echo $tab['content'];
            echo '</div>';
            echo '</div>';
        }
        echo '</div>'; // .hwe-tab-content

        echo '</div>'; // .hwe-tabs

        // Sticky save bar.
        echo '<div class="hwe-save-bar">';
        echo '<div class="hwe-save-bar-inner">';
        echo '<div class="hwe-save-info">';
        echo '<span class="dashicons dashicons-info"></span>';
        echo esc_html__('Los cambios se aplican inmediatamente al frontend (revalidación ISR).', 'hwe');
        echo '</div>';
        echo '<button type="submit" name="submit" id="submit" class="button button-primary button-hero hwe-save-btn">';
        echo '<span class="dashicons dashicons-saved"></span>';
        echo '<span class="hwe-save-btn-text">' . esc_html__('Guardar configuración', 'hwe') . '</span>';
        echo '</button>';
        echo '</div>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Pestañas del panel: una por sección del esquema.
     *
     * La pestaña «Instancia» (grupo raíz `instance`) solo aparece cuando la instancia le ha
     * añadido campos: vacía no tiene nada que mostrar, y una pestaña muerta en todo panel
     * estropearía la plantilla.
     *
     * @param array<string,array>  $schema   Esquema efectivo (Schema::get()).
     * @param array<string,string> $sections HTML de cada sección, por clave raíz.
     * @return array<string,array{label:string,icon:string,content:string}>
     */
    public static function buildTabs(array $schema, array $sections): array {
        $tabIcons = [
            'brand'        => 'dashicons-admin-home',
            'social'       => 'dashicons-share',
            'legal'        => 'dashicons-media-document',
            'design'       => 'dashicons-admin-appearance',
            'ecommerce'    => 'dashicons-cart',
            'payments'     => 'dashicons-money',
            'integrations' => 'dashicons-admin-plugins',
            'backups'      => 'dashicons-backup',
            'seo'          => 'dashicons-search',
            'shipping'     => 'dashicons-store',
            'geo'          => 'dashicons-location',
            'banners'      => 'dashicons-images-alt2',
            'instance'     => 'dashicons-admin-tools',
        ];

        $tabs = [];
        foreach ($sections as $key => $html) {
            if ($key === 'instance' && empty($schema['instance']['children'] ?? [])) {
                continue;
            }
            $tabs[$key] = [
                'label'   => (string) ($schema[$key]['label'] ?? $key),
                'icon'    => $tabIcons[$key] ?? 'dashicons-admin-generic',
                'content' => $html,
            ];
        }
        return $tabs;
    }

    // -------------------------------------------------------------------------
    // Utilidades de render
    // -------------------------------------------------------------------------

    /** Construye un mapa anidado indicando si ya existe un secreto en cada ruta. */
    private static function buildSecretExistenceMap(): array {
        $all = SecretsStorage::getAll();
        return self::mapExists($all);
    }

    private static function mapExists(array $tree): array {
        $result = [];
        foreach ($tree as $key => $value) {
            $result[$key] = is_array($value)
                ? self::mapExists($value)
                : (is_string($value) && $value !== '');
        }
        return $result;
    }

    private static function deepMerge(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } elseif ($value !== null) {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    // -------------------------------------------------------------------------
    // Prueba de transporte SMTP (integrada en la pestaña de Integraciones)
    // -------------------------------------------------------------------------

    private static function renderSmtpTest(): string {
        $status = isset($_GET['hwe_smtp_test'])
            ? sanitize_text_field(wp_unslash($_GET['hwe_smtp_test']))
            : '';

        $notices = '';
        if ($status === 'sent') {
            $notices = AdminNotices::notice('success', __('Email de prueba enviado. Revisa la bandeja de entrada (y la carpeta de spam).', 'hwe'));
        } elseif ($status === 'failed') {
            $err = get_transient('hwe_smtp_last_error');
            $msg = __('No se pudo enviar el email de prueba.', 'hwe');
            if (is_string($err) && $err !== '') {
                $msg .= ' ' . $err;
            }
            $notices = AdminNotices::notice('error', $msg);
        }

        $active  = function_exists('hwe_smtp_is_active') && hwe_smtp_is_active();
        $estado  = $active
            ? __('SMTP activo: el correo se enviará por el servidor configurado.', 'hwe')
            : __('SMTP inactivo: actívalo y completa el host arriba; mientras tanto se usa el envío PHP por defecto.', 'hwe');

        $actionUrl  = esc_url(admin_url('admin-post.php'));
        $current    = esc_attr(wp_get_current_user()->user_email);
        $nonce      = wp_nonce_field('hwe_smtp_test', '_wpnonce', true, false);
        $title      = esc_html__('Probar entrega de email', 'hwe');
        $label      = esc_html__('Enviar email de prueba a:', 'hwe');
        $btn        = esc_attr__('Enviar email de prueba', 'hwe');
        $estadoHtml = esc_html($estado);
        $iconClass  = $active ? 'yes-alt' : 'warning';

        ob_start();
        ?>
        <div class="hwe-smtp-test">
            <div class="hwe-panel-header">
                <span class="dashicons dashicons-email-alt"></span>
                <h2><?php echo $title; ?></h2>
            </div>
            <div class="hwe-panel-body">
                <div class="hwe-smtp-status">
                    <span class="dashicons dashicons-<?php echo $iconClass; ?>"></span>
                    <p><?php echo $estadoHtml; ?></p>
                </div>
                <form method="post" action="<?php echo $actionUrl; ?>" class="hwe-smtp-form">
                    <?php echo $nonce; ?>
                    <input type="hidden" name="action" value="hwe_smtp_test">
                    <div class="hwe-field-row">
                        <label><?php echo $label; ?></label>
                        <input type="email" name="hwe_smtp_test_email" value="<?php echo $current; ?>" class="regular-text" required>
                    </div>
                    <p class="submit"><button type="submit" class="button button-secondary"><span class="dashicons dashicons-email"></span> <?php echo $btn; ?></button></p>
                </form>
            </div>
        </div>
        <?php
        return $notices . ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Estado del plugin HWE Banners (detección; la autoría vive en el plugin)
    // -------------------------------------------------------------------------

    private static function renderBannerStatus(): string {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active   = is_plugin_active( 'hwe-banners/hwe-banners.php' );
        $manageUrl = esc_url( admin_url( 'edit.php?post_type=hwe_banner' ) );

        if ( $active ) {
            $icon   = 'yes-alt';
            $estado = __( 'Plugin "HWE Banners" activo. Gestiona los banners desde su menú.', 'hwe' );
        } else {
            $icon   = 'warning';
            $estado = __( 'El plugin "HWE Banners" no está activo. Actívalo en Plugins para poder crear banners; el interruptor de arriba solo activa su renderizado en el frontend.', 'hwe' );
        }

        ob_start();
        ?>
        <div class="hwe-smtp-test">
            <div class="hwe-panel-header">
                <span class="dashicons dashicons-images-alt2"></span>
                <h2><?php echo esc_html__( 'Gestión de banners', 'hwe' ); ?></h2>
            </div>
            <div class="hwe-panel-body">
                <div class="hwe-smtp-status">
                    <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
                    <p><?php echo esc_html( $estado ); ?></p>
                </div>
                <?php if ( $active ) : ?>
                <p class="submit">
                    <a href="<?php echo $manageUrl; ?>" class="button button-secondary">
                        <span class="dashicons dashicons-external"></span>
                        <?php echo esc_html__( 'Administrar banners', 'hwe' ); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
