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

        // Acciones de backups (manual, recientes, export/import) en el tab "backups".
        if ( isset( $sections['backups'] ) ) {
            $sections['backups'] .= BackupActions::render();
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

        // Formularios fuera del form principal: HTML prohíbe <form> anidados (el navegador
        // descarta el interior y su botón envía el formulario de configuración), y estas
        // acciones (prueba de email, backup manual, import de config) van a
        // admin-post.php, no al guardado normal del panel. Sus controles los referencian
        // por `form="…"`.
        $adminPostUrl = esc_url(admin_url('admin-post.php'));
        echo '<form id="hwe-smtp-form" method="post" action="' . $adminPostUrl . '" class="hwe-smtp-form-root"></form>';
        echo '<form id="hwe-backup-run-now-form" method="post" action="' . $adminPostUrl . '" class="hwe-smtp-form-root"></form>';
        echo '<form id="hwe-config-import-form" method="post" enctype="multipart/form-data" action="' . $adminPostUrl . '" class="hwe-smtp-form-root"></form>';

        echo '</div>';
    }

    /**
     * Pestañas del panel: una por sección del esquema.
     *
     * Un grupo raíz sin campos no tiene pestaña: «Instancia» (`instance`) y «Envío»
     * (`shipping`, reservado al módulo del mismo nombre) solo aparecen cuando alguien les
     * ha añadido campos; una pestaña muerta en todo panel estropearía la plantilla.
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
            if (empty($schema[$key]['children'] ?? [])) {
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

        // Plantilla de la última prueba (hwe-email-test.php), para el aviso y para dejarla
        // seleccionada.
        $tplKey   = isset($_GET['hwe_smtp_test_tpl'])
            ? (string) preg_replace('/[^a-z0-9_:\-]/', '', strtolower(sanitize_text_field(wp_unslash($_GET['hwe_smtp_test_tpl']))))
            : 'basic';
        $useReal  = isset($_GET['hwe_smtp_test_real']) && $_GET['hwe_smtp_test_real'] === '1';
        $catalog  = function_exists('hwe_email_test_catalog') ? hwe_email_test_catalog() : ['Transporte' => ['basic' => __('Prueba simple (sin plantilla)', 'hwe')]];
        $tplLabel = function_exists('hwe_email_test_label') ? hwe_email_test_label($tplKey) : '';
        $tplNote  = $tplLabel !== '' && $tplKey !== 'basic' ? sprintf(' (%s)', $tplLabel) : '';

        $notices = '';
        if ($status === 'sent') {
            /* translators: %s: plantilla probada, entre paréntesis, o vacío */
            $notices = AdminNotices::notice('success', sprintf(__('Email de prueba%s enviado. Revisa la bandeja de entrada (y la carpeta de spam).', 'hwe'), $tplNote));
        } elseif ($status === 'failed') {
            $err = get_transient('hwe_smtp_last_error');
            /* translators: %s: plantilla probada, entre paréntesis, o vacío */
            $msg = sprintf(__('No se pudo enviar el email de prueba%s.', 'hwe'), $tplNote);
            if (is_string($err) && $err !== '') {
                $msg .= ' ' . $err;
            }
            $notices = AdminNotices::notice('error', $msg);
        }

        $active  = function_exists('hwe_smtp_is_active') && hwe_smtp_is_active();
        $estado  = $active
            ? __('SMTP activo: el correo se enviará por el servidor configurado.', 'hwe')
            : __('SMTP inactivo: actívalo y completa el host arriba; mientras tanto se usa el envío PHP por defecto.', 'hwe');

        ob_start();
        ?>
        <div class="hwe-smtp-test">
            <div class="hwe-panel-header">
                <span class="dashicons dashicons-email-alt"></span>
                <h2><?php esc_html_e('Probar entrega de email', 'hwe'); ?></h2>
            </div>
            <div class="hwe-panel-body">
                <div class="hwe-smtp-status">
                    <span class="dashicons dashicons-<?php echo $active ? 'yes-alt' : 'warning'; ?>"></span>
                    <p><?php echo esc_html($estado); ?></p>
                </div>
                <?php // Controles asociados a #hwe-smtp-form, que vive FUERA del formulario principal (ver renderPage). ?>
                <div class="hwe-smtp-form">
                    <input type="hidden" form="hwe-smtp-form" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('hwe_smtp_test')); ?>">
                    <input type="hidden" form="hwe-smtp-form" name="action" value="hwe_smtp_test">
                    <div class="hwe-field-row">
                        <label for="hwe-smtp-test-email"><?php esc_html_e('Enviar email de prueba a:', 'hwe'); ?></label>
                        <input type="email" id="hwe-smtp-test-email" form="hwe-smtp-form" name="hwe_smtp_test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" required>
                    </div>
                    <div class="hwe-field-row">
                        <label for="hwe-smtp-test-template"><?php esc_html_e('Plantilla:', 'hwe'); ?></label>
                        <select id="hwe-smtp-test-template" form="hwe-smtp-form" name="hwe_smtp_test_template">
                            <?php foreach ($catalog as $group => $items) : ?>
                                <optgroup label="<?php echo esc_attr((string) $group); ?>">
                                    <?php foreach ($items as $key => $itemLabel) : ?>
                                        <option value="<?php echo esc_attr((string) $key); ?>" <?php selected($tplKey, (string) $key); ?>><?php echo esc_html((string) $itemLabel); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hwe-field-row">
                        <label>
                            <input type="checkbox" form="hwe-smtp-form" name="hwe_smtp_test_real_order" value="1" <?php checked($useReal); ?>>
                            <?php esc_html_e('Usar el último pedido real en vez del pedido de ejemplo', 'hwe'); ?>
                        </label>
                    </div>
                    <p class="description"><?php esc_html_e('El correo se envía SOLO a la dirección de arriba, con «[Prueba]» en el asunto y el diseño real de la plantilla. Por defecto usa un pedido de ejemplo que no se guarda. Los enlaces con clave (restablecer, verificar) llevan una de ejemplo: muestran a dónde apuntan, pero no funcionan.', 'hwe'); ?></p>
                    <p class="submit hwe-smtp-actions">
                        <button type="submit" form="hwe-smtp-form" class="button button-secondary"><span class="dashicons dashicons-email"></span> <?php esc_html_e('Enviar email de prueba', 'hwe'); ?></button>
                        <?php if (function_exists('hwe_email_test_render')) : ?>
                            <button type="button" class="button" data-hwe-email-preview data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('hwe_email_preview')); ?>"><span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Ver vista previa', 'hwe'); ?></button>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <?php // Vista previa: el HTML llega por AJAX (hwe-email-test.php) y se pinta en un iframe sandbox. ?>
        <div class="hwe-email-preview" data-hwe-email-preview-modal hidden>
            <div class="hwe-email-preview__backdrop" data-hwe-email-preview-close></div>
            <div class="hwe-email-preview__dialog" role="dialog" aria-modal="true" aria-labelledby="hwe-email-preview-title">
                <header class="hwe-email-preview__head">
                    <div class="hwe-email-preview__meta">
                        <h2 id="hwe-email-preview-title" data-hwe-email-preview-label><?php esc_html_e('Vista previa', 'hwe'); ?></h2>
                        <dl>
                            <dt><?php esc_html_e('Asunto', 'hwe'); ?></dt><dd data-hwe-email-preview-subject></dd>
                            <dt><?php esc_html_e('De', 'hwe'); ?></dt><dd data-hwe-email-preview-from></dd>
                        </dl>
                    </div>
                    <div class="hwe-email-preview__tools">
                        <div class="hwe-email-preview__devices" role="group" aria-label="<?php esc_attr_e('Ancho', 'hwe'); ?>">
                            <button type="button" class="button" data-hwe-email-preview-device="desktop" aria-pressed="true"><span class="dashicons dashicons-desktop"></span> <?php esc_html_e('Escritorio', 'hwe'); ?></button>
                            <button type="button" class="button" data-hwe-email-preview-device="mobile" aria-pressed="false"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e('Móvil', 'hwe'); ?></button>
                        </div>
                        <button type="button" class="button hwe-email-preview__close" data-hwe-email-preview-close aria-label="<?php esc_attr_e('Cerrar', 'hwe'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
                    </div>
                </header>
                <div class="hwe-email-preview__stage">
                    <p class="hwe-email-preview__status" data-hwe-email-preview-status></p>
                    <iframe class="hwe-email-preview__frame" data-hwe-email-preview-frame title="<?php esc_attr_e('Vista previa del correo', 'hwe'); ?>" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>
                </div>
            </div>
        </div>
        <?php
        return $notices . (string) ob_get_clean();
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
