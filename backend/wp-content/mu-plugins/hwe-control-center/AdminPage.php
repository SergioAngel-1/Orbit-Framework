<?php

namespace HWE\ControlCenter;

use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\ControlCenter\Walkers\DefaultsWalker;

/**
 * Página de administración en wp-admin para el Control Center.
 *
 * - Menú: Ajustes → HWE Config.
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
    }

    public static function addMenuPage(): void {
        add_options_page(
            'HWE Control Center',
            'HWE Config',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'renderPage']
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
        $formHtml = implode("\n", array_values($sections));
        $formUrl  = esc_url(admin_url('options-general.php?page=' . self::MENU_SLUG));

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo $notice;
        echo <<<HTML
<form method="post" action="{$formUrl}">
HTML;
        wp_nonce_field(self::NONCE_KEY, 'hwe_nonce');
        echo $formHtml;
        echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="' . esc_attr__('Guardar configuración', 'hwe') . '"></p>';
        echo '</form>';

        // Sección de prueba del transporte SMTP (envía un email de test).
        self::renderSmtpTest();

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
    // Prueba de transporte SMTP (botón "enviar email de prueba")
    // -------------------------------------------------------------------------

    private static function renderSmtpTest(): void {
        $status = isset($_GET['hwe_smtp_test'])
            ? sanitize_text_field(wp_unslash($_GET['hwe_smtp_test']))
            : '';

        if ($status === 'sent') {
            echo self::notice('success', __('Email de prueba enviado. Revisa la bandeja de entrada (y la carpeta de spam).', 'hwe'));
        } elseif ($status === 'failed') {
            $err = get_transient('hwe_smtp_last_error');
            $msg = __('No se pudo enviar el email de prueba.', 'hwe');
            if (is_string($err) && $err !== '') {
                $msg .= ' ' . $err;
            }
            echo self::notice('error', $msg);
        }

        $active = function_exists('hwe_smtp_is_active') && hwe_smtp_is_active();
        $estado = $active
            ? __('SMTP activo: el correo se enviará por el servidor configurado.', 'hwe')
            : __('SMTP inactivo: actívalo y completa el host arriba; mientras tanto se usa el envío PHP por defecto.', 'hwe');

        $actionUrl = esc_url(admin_url('admin-post.php'));
        $current   = esc_attr(wp_get_current_user()->user_email);
        $nonce     = wp_nonce_field('hwe_smtp_test', '_wpnonce', true, false);
        $title     = esc_html__('Probar entrega de email', 'hwe');
        $label     = esc_html__('Enviar email de prueba a:', 'hwe');
        $btn       = esc_attr__('Enviar email de prueba', 'hwe');
        $estadoHtml = esc_html($estado);

        echo <<<HTML
<hr style="margin:24px 0;">
<h2>{$title}</h2>
<p class="description">{$estadoHtml}</p>
<form method="post" action="{$actionUrl}" style="margin-top:8px;">
{$nonce}
<input type="hidden" name="action" value="hwe_smtp_test">
<label>{$label} <input type="email" name="hwe_smtp_test_email" value="{$current}" class="regular-text" required></label>
<p class="submit"><input type="submit" class="button" value="{$btn}"></p>
</form>
HTML;
    }
}
