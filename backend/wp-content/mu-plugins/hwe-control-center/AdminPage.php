<?php

namespace HWE\ControlCenter;

use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\ControlCenter\Walkers\DefaultsWalker;

/**
 * Página de administración en wp-admin para el Control Center.
 *
 * - Menú: HWE Config (entry principal en la sidebar de WP).
 * - Interfaz con pestañas para organizar las secciones de configuración.
 * - Formulario generado recursivamente desde el esquema (AdminFormWalker).
 * - Guardado: nonce + capacidad + sanitización recursiva por tipo de campo.
 * - Separa valores públicos (Storage) de secretos (SecretsStorage).
 * - Dispara revalidación ISR en el frontend al guardar.
 */
class AdminPage {

    private const MENU_SLUG  = 'hwe-config';
    private const NONCE_KEY  = 'hwe_config_save';

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

        wp_enqueue_style(
            'hwe-admin',
            $baseUrl . '/assets/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'hwe-admin',
            $baseUrl . '/assets/admin.js',
            [],
            '1.0.0',
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
            $notice = self::handleSave();
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

        // Build tabs from sections.
        $tabs = [];
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
        ];

        foreach ($sections as $key => $html) {
            $label = $schema[$key]['label'] ?? $key;
            $icon  = $tabIcons[$key] ?? 'dashicons-admin-generic';
            $tabs[$key] = ['label' => $label, 'icon' => $icon, 'content' => $html];
        }

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
        wp_nonce_field(self::NONCE_KEY, 'hwe_nonce');

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

    // -------------------------------------------------------------------------
    // Guardado
    // -------------------------------------------------------------------------

    private static function handleSave(): string {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hwe_nonce'] ?? '')), self::NONCE_KEY)) {
            return self::notice('error', __('Nonce inválido. Por favor recarga la página e inténtalo de nuevo.', 'hwe'));
        }

        $raw = isset($_POST['hwe_config']) && is_array($_POST['hwe_config'])
            ? wp_unslash($_POST['hwe_config'])
            : [];

        [$publicData, $secretData] = self::sanitizeAndSplit(Schema::get(), (array) $raw);

        Storage::setAll($publicData);
        SecretsStorage::mergeNonEmpty($secretData);
        BackupConfig::write($publicData['backups'] ?? []);
        Revalidation::trigger();

        return self::notice('success', __('Configuración guardada correctamente.', 'hwe'));
    }

    /**
     * Recorre el esquema y los datos enviados de forma recursiva.
     * Devuelve [publicData, secretData] separados.
     */
    private static function sanitizeAndSplit(array $schema, array $submitted, array $path = []): array {
        $publicData = [];
        $secretData = [];

        foreach ($schema as $key => $node) {
            $currentPath = array_merge($path, [$key]);
            $rawValue    = $submitted[$key] ?? null;
            $nodeType    = $node['type'] ?? 'text';

            if ($nodeType === 'group') {
                $groupRaw = is_array($rawValue) ? $rawValue : [];
                [$gPublic, $gSecrets] = self::sanitizeAndSplit($node['children'] ?? [], $groupRaw, $currentPath);
                if (!empty($gPublic))   $publicData[$key]  = $gPublic;
                if (!empty($gSecrets))  $secretData[$key]  = $gSecrets;

            } elseif ($nodeType === 'secret') {
                $val = sanitize_text_field((string) ($rawValue ?? ''));
                if ($val !== '') {
                    $secretData[$key] = $val;
                }

            } else {
                $publicData[$key] = self::sanitizeField($node, $rawValue);
            }
        }

        return [$publicData, $secretData];
    }

    private static function sanitizeField(array $field, mixed $value): mixed {
        return match ($field['type']) {
            'textarea' => sanitize_textarea_field((string) ($value ?? '')),
            'url'      => esc_url_raw((string) ($value ?? '')),
            'email'    => sanitize_email((string) ($value ?? '')),
            'color'    => self::sanitizeColor((string) ($value ?? '')),
            'boolean'  => !empty($value) && $value !== '0',
            'select'   => self::sanitizeSelect($field, (string) ($value ?? '')),
            default    => sanitize_text_field((string) ($value ?? '')),
        };
    }

    private static function sanitizeColor(string $value): string {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '';
    }

    private static function sanitizeSelect(array $field, string $value): string {
        $options = $field['options'] ?? [];
        return array_key_exists($value, $options) ? $value : (string) ($field['default'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Utilidades
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

    private static function notice(string $type, string $message): string {
        return sprintf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
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
            $notices = self::notice('success', __('Email de prueba enviado. Revisa la bandeja de entrada (y la carpeta de spam).', 'hwe'));
        } elseif ($status === 'failed') {
            $err = get_transient('hwe_smtp_last_error');
            $msg = __('No se pudo enviar el email de prueba.', 'hwe');
            if (is_string($err) && $err !== '') {
                $msg .= ' ' . $err;
            }
            $notices = self::notice('error', $msg);
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
}
