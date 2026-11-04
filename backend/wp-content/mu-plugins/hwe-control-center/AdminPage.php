<?php

namespace HWE\ControlCenter;

use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\ControlCenter\Walkers\DefaultsWalker;

/**
 * Página de administración en wp-admin para el Control Center (RENDER).
 *
 * - Menú: HWE Config (entry principal en la sidebar de WP).
 * - Navegación lateral en tres capas (Negocio / Marca y visibilidad / Puesta en marcha) y un
 *   panel por pestaña, con secciones que dicen para qué sirven. Qué campo va dónde lo decide
 *   `PanelMap` y lo reparte `PanelLayout`; este archivo solo pinta el marco.
 * - Campos generados recursivamente desde el esquema (AdminFormWalker).
 * - La marca de la tienda (cabecera y color de acento) sale de `AdminSkin`.
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
        $dir     = dirname(__FILE__) . '/assets/';
        $version = static fn (string $file): string|int => file_exists($dir . $file) ? (int) filemtime($dir . $file) : 'v3';

        // La medioteca (`wp.media`) la usan los pickers de los tipos `image`/`file`.
        wp_enqueue_media();

        wp_enqueue_style('hwe-admin', $baseUrl . '/assets/admin.css', [], $version('admin.css'));
        // La marca de la tienda: variables de color que `admin.css` consume.
        $skin = AdminSkin::css();
        if ($skin !== '') {
            wp_add_inline_style('hwe-admin', $skin);
        }

        wp_enqueue_script('hwe-admin', $baseUrl . '/assets/admin.js', [], $version('admin.js'), true);
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

        $schema   = Schema::get();
        $stored   = Storage::getAll();
        $defaults = (new DefaultsWalker())->walk($schema);
        $merged   = self::deepMerge($defaults, $stored);

        // Para los secretos solo necesitamos saber si ya existe un valor (no el valor).
        $walker = new AdminFormWalker(self::buildSecretExistenceMap());
        $walker->walk($schema, $merged);

        $layout = PanelLayout::compose($schema, $walker->fragments(), [
            'email'   => [self::emailTestSection()],
            'banners' => [self::bannerStatusSection()],
            'backups' => BackupActions::sections(),
        ]);
        $tabs = $layout['tabs'];

        $identity = AdminSkin::identity();
        $initial  = self::initialTab($tabs);
        $formUrl  = esc_url(admin_url('admin.php?page=' . self::MENU_SLUG));

        echo '<div class="wrap hwe-wrap" data-hwe-initial-tab="' . esc_attr($initial) . '">';

        self::renderHeader($identity);
        echo '<hr class="wp-header-end">';

        echo $notice;
        echo self::smtpTestNotice();
        echo BackupActions::notice();

        echo '<form method="post" action="' . $formUrl . '" class="hwe-form" data-hwe-form>';
        wp_nonce_field(AdminSave::NONCE_KEY, 'hwe_nonce');

        echo '<div class="hwe-layout">';
        self::renderNav($tabs, $initial);

        echo '<div class="hwe-main">';
        foreach ($tabs as $key => $tab) {
            self::renderPanel((string) $key, $tab, $key === $initial);
        }
        self::renderSaveBar();

        echo '</div>'; // .hwe-main
        echo '</div>'; // .hwe-layout

        echo '</form>';

        // Formularios fuera del form principal: HTML prohíbe <form> anidados (el navegador
        // descarta el interior y su botón envía el formulario de configuración), y estas
        // acciones (prueba de email, backup manual, import de config) van a
        // admin-post.php, no al guardado normal del panel. Sus controles los referencian
        // por `form="…"`.
        $adminPostUrl = esc_url(admin_url('admin-post.php'));
        echo '<form id="hwe-smtp-form" method="post" action="' . $adminPostUrl . '" hidden></form>';
        echo '<form id="hwe-backup-run-now-form" method="post" action="' . $adminPostUrl . '" hidden></form>';
        echo '<form id="hwe-config-import-form" method="post" enctype="multipart/form-data" action="' . $adminPostUrl . '" hidden></form>';

        echo '</div>';
    }

    /**
     * Pestaña con la que se abre el panel cuando la URL no trae `#pestaña`: la de la acción que
     * acaba de redirigir aquí (prueba de correo, copias de seguridad), o la primera.
     *
     * @param array<string,array<string,mixed>> $tabs
     */
    private static function initialTab(array $tabs): string {
        $wanted = '';
        if (isset($_GET['hwe_smtp_test'])) {
            $wanted = 'email';
        } elseif (isset($_GET['hwe_backup_action'])) {
            $wanted = 'backups';
        }
        return isset($tabs[$wanted]) ? $wanted : (string) array_key_first($tabs);
    }

    /** @param array{name:string,logo:string,branded:bool} $identity */
    private static function renderHeader(array $identity): void {
        echo '<header class="hwe-header">';
        echo '<div class="hwe-header__brand">';
        if ($identity['logo'] !== '') {
            echo '<img class="hwe-header__logo" src="' . esc_url($identity['logo']) . '" alt="">';
        }
        echo '<div class="hwe-header__text">';
        echo '<h1 class="hwe-header__name" title="' . esc_attr($identity['name']) . '">' . esc_html($identity['name']) . '</h1>';
        echo '<p class="hwe-header__sub">Control Center</p>';
        echo '</div>';
        echo '</div>';
        echo '<a href="' . esc_url(rest_url('hwe/v1/config')) . '" target="_blank" rel="noopener" class="button hwe-header__api">'
            . esc_html__('Ver API', 'hwe')
            . '<span class="screen-reader-text"> ' . esc_html__('(se abre en una pestaña nueva)', 'hwe') . '</span></a>';
        echo '</header>';
    }

    /**
     * Navegación lateral: un encabezado por capa y, dentro de «Negocio», un subencabezado para
     * las pestañas de módulos. Son enlaces `#pestaña`: `admin.js` los convierte en cambio de panel.
     *
     * @param array<string,array<string,mixed>> $tabs
     * @param string                            $initial Pestaña abierta al cargar.
     */
    private static function renderNav(array $tabs, string $initial): void {
        $layers = PanelMap::layers();

        echo '<nav class="hwe-nav" aria-label="' . esc_attr__('Secciones del Control Center', 'hwe') . '">';
        echo '<button type="button" class="hwe-nav__toggle" aria-expanded="false" aria-controls="hwe-nav-list">';
        echo '<span class="hwe-nav__current" data-hwe-nav-current>' . esc_html((string) ($tabs[$initial]['label'] ?? '')) . '</span>';
        echo '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
        echo '</button>';
        echo '<div class="hwe-nav__list" id="hwe-nav-list">';

        $layer  = null;
        $module = false;
        $open   = false;
        foreach ($tabs as $key => $tab) {
            $isModule = !empty($tab['module']);
            if ($tab['layer'] !== $layer) {
                if ($open) {
                    echo '</ul>';
                }
                $layer  = $tab['layer'];
                $module = false;
                echo '<h2 class="hwe-nav__layer">' . esc_html((string) ($layers[$layer] ?? $layer)) . '</h2>';
                $open = false;
            }
            if ($isModule && !$module) {
                if ($open) {
                    echo '</ul>';
                }
                echo '<h3 class="hwe-nav__sub">' . esc_html__('Módulos', 'hwe') . '</h3>';
                $open = false;
            }
            $module = $isModule;
            if (!$open) {
                echo '<ul class="hwe-nav__items">';
                $open = true;
            }
            $current = $key === $initial ? ' aria-current="true"' : '';
            echo '<li><a href="#' . esc_attr((string) $key) . '" class="hwe-nav__link" data-hwe-tab="' . esc_attr((string) $key) . '"' . $current . '>';
            echo '<span class="dashicons ' . esc_attr((string) $tab['icon']) . '" aria-hidden="true"></span>';
            echo '<span class="hwe-nav__label">' . esc_html((string) $tab['label']) . '</span>';
            echo '<span class="hwe-nav__dirty" data-hwe-dirty-mark hidden><span class="screen-reader-text">' . esc_html__('con cambios sin guardar', 'hwe') . '</span></span>';
            echo '</a></li>';
        }
        if ($open) {
            echo '</ul>';
        }

        echo '</div>';
        echo '</nav>';
    }

    /** @param array<string,mixed> $tab */
    private static function renderPanel(string $key, array $tab, bool $active): void {
        $id = 'hwe-panel-' . $key;
        echo '<section class="hwe-panel" id="' . esc_attr($id) . '" data-hwe-panel="' . esc_attr($key) . '" aria-labelledby="' . esc_attr($id) . '-title"' . ($active ? '' : ' hidden') . '>';
        echo '<header class="hwe-panel__head">';
        echo '<h2 id="' . esc_attr($id) . '-title">' . esc_html((string) $tab['label']) . '</h2>';
        if ((string) ($tab['intro'] ?? '') !== '') {
            echo '<p>' . esc_html((string) $tab['intro']) . '</p>';
        }
        echo '</header>';

        foreach ($tab['sections'] as $i => $section) {
            $titleId = $id . '-s' . $i;
            $class   = trim('hwe-section ' . (string) ($section['class'] ?? ''));

            // Sin título (pestañas que son solo de un módulo) no hay cabecera que pulsar: sección plana.
            if ($section['title'] === '') {
                echo '<section class="' . esc_attr($class) . '">';
                echo '<div class="hwe-fields">' . $section['html'] . '</div>';
                echo '</section>';
                continue;
            }

            // Cada sección es un <details> abierto de serie: se pliega sin JS y `admin.js` lo recuerda.
            echo '<details class="' . esc_attr($class) . '" data-hwe-fold open>';
            echo '<summary class="hwe-section__head">';
            echo '<h3 id="' . esc_attr($titleId) . '">' . esc_html($section['title']) . '</h3>';
            echo '<span class="hwe-fold__summary" data-hwe-summary-out></span>';
            echo '<span class="hwe-fold__dirty" data-hwe-fold-dirty hidden><span class="screen-reader-text">' . esc_html__('con cambios sin guardar', 'hwe') . '</span></span>';
            echo '</summary>';
            if ($section['intro'] !== '') {
                echo '<p class="hwe-section__intro">' . esc_html($section['intro']) . '</p>';
            }
            echo '<div class="hwe-fields">' . $section['html'] . '</div>';
            echo '</details>';
        }
        echo '</section>';
    }

    /**
     * Barra de guardado: único sitio con botón primario, y bajo él el de abrir/cerrar todo.
     * `admin.js` la mantiene al día.
     */
    private static function renderSaveBar(): void {
        echo '<div class="hwe-savebar">';
        echo '<p class="hwe-savebar__status" role="status" aria-live="polite" data-hwe-status'
            . ' data-clean="' . esc_attr__('Sin cambios por guardar. Lo que guardes se aplica a la tienda de inmediato.', 'hwe') . '"'
            . ' data-dirty="' . esc_attr__('Cambios sin guardar en: %s.', 'hwe') . '"'
            . ' data-saving="' . esc_attr__('Guardando…', 'hwe') . '"'
            . ' data-more="' . esc_attr__('%s y %d más', 'hwe') . '">';
        echo esc_html__('Sin cambios por guardar. Lo que guardes se aplica a la tienda de inmediato.', 'hwe');
        echo '</p>';
        echo '<button type="submit" name="submit" id="submit" class="button button-primary hwe-savebar__btn">' . esc_html__('Guardar configuración', 'hwe') . '</button>';
        // Abre o cierra todos los bloques plegables de la pestaña activa; `admin.js` pone la etiqueta según el estado real.
        echo '<button type="button" class="button hwe-savebar__fold" data-hwe-fold-all'
            . ' data-open="' . esc_attr__('Abrir todo', 'hwe') . '"'
            . ' data-close="' . esc_attr__('Colapsar todo', 'hwe') . '" hidden>' . esc_html__('Abrir todo', 'hwe') . '</button>';
        echo '</div>';
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
    // Prueba de transporte SMTP (sección de la pestaña «Correo»)
    // -------------------------------------------------------------------------

    /** Plantilla de la última prueba (hwe-email-test.php), para el aviso y para dejarla seleccionada. */
    private static function smtpTestTemplate(): string {
        return isset($_GET['hwe_smtp_test_tpl'])
            ? (string) preg_replace('/[^a-z0-9_:\-]/', '', strtolower(sanitize_text_field(wp_unslash($_GET['hwe_smtp_test_tpl']))))
            : 'basic';
    }

    /** Aviso del resultado de la última prueba, sobre el formulario. Vacío si no hay. */
    private static function smtpTestNotice(): string {
        $status = isset($_GET['hwe_smtp_test'])
            ? sanitize_text_field(wp_unslash($_GET['hwe_smtp_test']))
            : '';
        if ($status !== 'sent' && $status !== 'failed') {
            return '';
        }

        $tplKey   = self::smtpTestTemplate();
        $tplLabel = function_exists('hwe_email_test_label') ? hwe_email_test_label($tplKey) : '';
        $tplNote  = $tplLabel !== '' && $tplKey !== 'basic' ? sprintf(' (%s)', $tplLabel) : '';

        if ($status === 'sent') {
            /* translators: %s: plantilla probada, entre paréntesis, o vacío */
            return AdminNotices::notice('success', sprintf(__('Email de prueba%s enviado. Revisa la bandeja de entrada (y la carpeta de spam).', 'hwe'), $tplNote));
        }

        $err = get_transient('hwe_smtp_last_error');
        /* translators: %s: plantilla probada, entre paréntesis, o vacío */
        $msg = sprintf(__('No se pudo enviar el email de prueba%s.', 'hwe'), $tplNote);
        if (is_string($err) && $err !== '') {
            $msg .= ' ' . $err;
        }
        return AdminNotices::notice('error', $msg);
    }

    /** @return array{title:string,intro:string,html:string} */
    private static function emailTestSection(): array {
        $tplKey  = self::smtpTestTemplate();
        $useReal = isset($_GET['hwe_smtp_test_real']) && $_GET['hwe_smtp_test_real'] === '1';
        $catalog = function_exists('hwe_email_test_catalog') ? hwe_email_test_catalog() : ['Transporte' => ['basic' => __('Prueba simple (sin plantilla)', 'hwe')]];

        $active = function_exists('hwe_smtp_is_active') && hwe_smtp_is_active();
        $estado = $active
            ? __('SMTP activo: el correo se enviará por el servidor configurado.', 'hwe')
            : __('SMTP inactivo: actívalo y completa el host arriba; mientras tanto se usa el envío PHP por defecto.', 'hwe');

        ob_start();
        ?>
        <div class="hwe-status hwe-status--<?php echo $active ? 'ok' : 'warn'; ?>">
            <span class="dashicons dashicons-<?php echo $active ? 'yes-alt' : 'warning'; ?>" aria-hidden="true"></span>
            <p><?php echo esc_html($estado); ?></p>
        </div>
        <?php // Controles asociados a #hwe-smtp-form, que vive FUERA del formulario principal (ver renderPage). ?>
        <input type="hidden" form="hwe-smtp-form" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('hwe_smtp_test')); ?>">
        <input type="hidden" form="hwe-smtp-form" name="action" value="hwe_smtp_test">
        <div class="hwe-field">
            <label for="hwe-smtp-test-email"><?php esc_html_e('Enviar email de prueba a', 'hwe'); ?></label>
            <div class="hwe-field-control">
                <input type="email" id="hwe-smtp-test-email" form="hwe-smtp-form" name="hwe_smtp_test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
            </div>
        </div>
        <div class="hwe-field">
            <label for="hwe-smtp-test-template"><?php esc_html_e('Plantilla', 'hwe'); ?></label>
            <div class="hwe-field-control">
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
        </div>
        <div class="hwe-field">
            <div class="hwe-field-control">
                <label class="hwe-check">
                    <input type="checkbox" form="hwe-smtp-form" name="hwe_smtp_test_real_order" value="1" <?php checked($useReal); ?>>
                    <?php esc_html_e('Usar el último pedido real en vez del pedido de ejemplo', 'hwe'); ?>
                </label>
                <p class="description"><?php esc_html_e('El correo se envía SOLO a la dirección de arriba, con «[Prueba]» en el asunto y el diseño real de la plantilla. Por defecto usa un pedido de ejemplo que no se guarda. Los enlaces con clave (restablecer, verificar) llevan una de ejemplo: muestran a dónde apuntan, pero no funcionan.', 'hwe'); ?></p>
            </div>
        </div>
        <div class="hwe-actions">
            <button type="submit" form="hwe-smtp-form" class="button button-secondary"><?php esc_html_e('Enviar email de prueba', 'hwe'); ?></button>
            <?php if (function_exists('hwe_email_test_render')) : ?>
                <button type="button" class="button" data-hwe-email-preview data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('hwe_email_preview')); ?>"><?php esc_html_e('Ver vista previa', 'hwe'); ?></button>
            <?php endif; ?>
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
                            <button type="button" class="button" data-hwe-email-preview-device="desktop" aria-pressed="true"><span class="dashicons dashicons-desktop" aria-hidden="true"></span> <?php esc_html_e('Escritorio', 'hwe'); ?></button>
                            <button type="button" class="button" data-hwe-email-preview-device="mobile" aria-pressed="false"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> <?php esc_html_e('Móvil', 'hwe'); ?></button>
                        </div>
                        <button type="button" class="button hwe-email-preview__close" data-hwe-email-preview-close aria-label="<?php esc_attr_e('Cerrar', 'hwe'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                    </div>
                </header>
                <div class="hwe-email-preview__stage">
                    <p class="hwe-email-preview__status" data-hwe-email-preview-status></p>
                    <iframe class="hwe-email-preview__frame" data-hwe-email-preview-frame title="<?php esc_attr_e('Vista previa del correo', 'hwe'); ?>" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>
                </div>
            </div>
        </div>
        <?php
        return [
            'title' => __('Probar entrega de email', 'hwe'),
            'intro' => __('Envía un correo de prueba con el diseño real de una plantilla, o mira cómo se verá antes de enviarlo.', 'hwe'),
            'html'  => (string) ob_get_clean(),
        ];
    }

    // -------------------------------------------------------------------------
    // Estado del plugin HWE Banners (detección; la autoría vive en el plugin)
    // -------------------------------------------------------------------------

    /** @return array{title:string,intro:string,html:string} */
    private static function bannerStatusSection(): array {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active    = is_plugin_active('hwe-banners/hwe-banners.php');
        $manageUrl = esc_url(admin_url('edit.php?post_type=hwe_banner'));

        $estado = $active
            ? __('Plugin "HWE Banners" activo. Gestiona los banners desde su menú.', 'hwe')
            : __('El plugin "HWE Banners" no está activo. Actívalo en Plugins para poder crear banners; el interruptor de arriba solo activa su renderizado en el frontend.', 'hwe');

        ob_start();
        ?>
        <div class="hwe-status hwe-status--<?php echo $active ? 'ok' : 'warn'; ?>">
            <span class="dashicons dashicons-<?php echo $active ? 'yes-alt' : 'warning'; ?>" aria-hidden="true"></span>
            <p><?php echo esc_html($estado); ?></p>
        </div>
        <?php if ($active) : ?>
            <div class="hwe-actions">
                <a href="<?php echo $manageUrl; ?>" class="button button-secondary"><?php echo esc_html__('Administrar banners', 'hwe'); ?></a>
            </div>
        <?php endif; ?>
        <?php
        return [
            'title' => __('Gestión de banners', 'hwe'),
            'intro' => __('Los banners se crean y ordenan desde su propio menú.', 'hwe'),
            'html'  => (string) ob_get_clean(),
        ];
    }
}
