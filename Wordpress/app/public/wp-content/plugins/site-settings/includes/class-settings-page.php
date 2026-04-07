<?php
/**
 * Página de administración para Site Settings
 * 
 * Renderiza el panel de configuración con tabs por sección,
 * campos de formulario y media uploader para imágenes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Site_Settings_Page {

    private static $instance = null;
    const PAGE_SLUG = 'site-settings';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Agregar página al menú de administración
     */
    public function add_menu_page() {
        add_menu_page(
            __('Configuración del Sitio', 'site-settings'),
            __('Config. del Sitio', 'site-settings'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-admin-settings',
            2
        );
    }

    /**
     * Registrar opciones con la Settings API de WordPress
     */
    public function register_settings() {
        $fields_config = Site_Settings::get_fields_config();

        foreach ($fields_config as $section_key => $section) {
            foreach ($section['fields'] as $field_key => $field) {
                $option_name = SITE_SETTINGS_OPTION_PREFIX . $field_key;

                register_setting(self::PAGE_SLUG, $option_name, [
                    'type'              => $this->get_wp_type($field['type']),
                    'sanitize_callback' => [$this, 'sanitize_field'],
                    'default'           => Site_Settings::get_defaults()[$field_key] ?? '',
                ]);
            }
        }
    }

    /**
     * Cargar estilos y scripts solo en la página del plugin
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'site-settings-admin',
            SITE_SETTINGS_URL . 'assets/css/admin.css',
            [],
            SITE_SETTINGS_VERSION
        );

        // Media uploader para campos de imagen
        wp_enqueue_media();

        wp_enqueue_script(
            'site-settings-admin',
            '',
            ['jquery'],
            SITE_SETTINGS_VERSION,
            true
        );

        // Inline script para media uploader y tabs
        $this->enqueue_inline_script();
    }

    /**
     * Script inline para media uploader y navegación por tabs
     */
    private function enqueue_inline_script() {
        $script = "
        jQuery(document).ready(function($) {
            // Navegación por tabs
            var tabs = $('.site-settings-tab');
            var panels = $('.site-settings-panel');

            tabs.on('click', function(e) {
                e.preventDefault();
                var target = $(this).data('tab');

                tabs.removeClass('active');
                $(this).addClass('active');

                panels.removeClass('active');
                $('#panel-' + target).addClass('active');

                // Guardar tab activo en localStorage
                localStorage.setItem('site_settings_active_tab', target);
            });

            // Restaurar tab activo
            var savedTab = localStorage.getItem('site_settings_active_tab');
            if (savedTab && tabs.filter('[data-tab=\"' + savedTab + '\"]').length) {
                tabs.filter('[data-tab=\"' + savedTab + '\"]').trigger('click');
            }

            // Media uploader
            $(document).on('click', '.site-settings-upload-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var inputId = btn.data('input');
                var previewId = btn.data('preview');

                var frame = wp.media({
                    title: 'Seleccionar imagen',
                    button: { text: 'Usar esta imagen' },
                    multiple: false,
                    library: { type: 'image' }
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#' + inputId).val(attachment.id);
                    var previewUrl = attachment.sizes && attachment.sizes.thumbnail 
                        ? attachment.sizes.thumbnail.url 
                        : attachment.url;
                    $('#' + previewId).html('<img src=\"' + previewUrl + '\" style=\"max-width:150px;max-height:100px;\">');
                    $('#' + inputId).closest('.site-settings-image-field').find('.site-settings-remove-btn').show();
                });

                frame.open();
            });

            // Remover imagen
            $(document).on('click', '.site-settings-remove-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var inputId = btn.data('input');
                var previewId = btn.data('preview');
                $('#' + inputId).val('');
                $('#' + previewId).html('');
                btn.hide();
            });
        });
        ";

        wp_add_inline_script('jquery', $script);
    }

    /**
     * Renderizar la página de configuración
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $fields_config = Site_Settings::get_fields_config();
        $sections = array_keys($fields_config);
        $active_tab = $sections[0] ?? 'identity';

        ?>
        <div class="wrap site-settings-wrap">
            <h1>
                <span class="dashicons dashicons-admin-settings" style="font-size:28px;margin-right:8px;vertical-align:middle;"></span>
                <?php _e('Configuración del Sitio', 'site-settings'); ?>
            </h1>
            <p class="description" style="margin-bottom:20px;">
                <?php _e('Configura la identidad, branding, contacto y más. Estos valores se usan en todo el sitio (backend y frontend).', 'site-settings'); ?>
            </p>

            <?php settings_errors(); ?>

            <div class="site-settings-container">
                <!-- Sidebar con tabs -->
                <nav class="site-settings-sidebar">
                    <?php foreach ($fields_config as $key => $section) : ?>
                        <a href="#" 
                           class="site-settings-tab <?php echo ($key === $active_tab) ? 'active' : ''; ?>"
                           data-tab="<?php echo esc_attr($key); ?>">
                            <span class="dashicons <?php echo esc_attr($section['icon']); ?>"></span>
                            <?php echo esc_html($section['title']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Contenido -->
                <div class="site-settings-content">
                    <form method="post" action="options.php">
                        <?php settings_fields(self::PAGE_SLUG); ?>

                        <?php foreach ($fields_config as $section_key => $section) : ?>
                            <div id="panel-<?php echo esc_attr($section_key); ?>" 
                                 class="site-settings-panel <?php echo ($section_key === $active_tab) ? 'active' : ''; ?>">
                                
                                <h2>
                                    <span class="dashicons <?php echo esc_attr($section['icon']); ?>"></span>
                                    <?php echo esc_html($section['title']); ?>
                                </h2>

                                <?php if (isset($section['type']) && $section['type'] === 'plugin_list') : ?>
                                    <?php $this->render_plugins_panel(); ?>
                                <?php else : ?>
                                <table class="form-table" role="presentation">
                                    <?php foreach ($section['fields'] as $field_key => $field) : ?>
                                        <tr>
                                            <th scope="row">
                                                <label for="<?php echo esc_attr($field_key); ?>">
                                                    <?php echo esc_html($field['label']); ?>
                                                </label>
                                            </th>
                                            <td>
                                                <?php $this->render_field($field_key, $field); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php submit_button(__('Guardar Configuración', 'site-settings')); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar un campo individual según su tipo
     */
    private function render_field($key, $field) {
        $option_name = SITE_SETTINGS_OPTION_PREFIX . $key;
        $value = get_option($option_name, Site_Settings::get_defaults()[$key] ?? '');
        $id = esc_attr($key);
        $name = esc_attr($option_name);
        $description = isset($field['description']) ? $field['description'] : '';

        switch ($field['type']) {
            case 'text':
            case 'url':
            case 'email':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" class="regular-text">',
                    esc_attr($field['type']),
                    $id,
                    $name,
                    esc_attr($value)
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" class="small-text" step="any">',
                    $id,
                    $name,
                    esc_attr($value)
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>',
                    $id,
                    $name,
                    esc_textarea($value)
                );
                break;

            case 'color':
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="regular-text site-settings-color-field" data-default-color="%s">
                     <input type="color" value="%s" class="site-settings-color-picker" 
                            onchange="document.getElementById(\'%s\').value=this.value"
                            style="vertical-align:middle;margin-left:8px;cursor:pointer;width:40px;height:30px;padding:0;border:1px solid #ccc;">',
                    $id,
                    $name,
                    esc_attr($value),
                    esc_attr($value),
                    esc_attr($value),
                    $id
                );
                break;

            case 'select':
                $options = $field['options'] ?? [];
                printf('<select id="%s" name="%s" class="regular-text">', $id, $name);
                foreach ($options as $opt_value => $opt_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($opt_value),
                        selected($value, $opt_value, false),
                        esc_html($opt_label)
                    );
                }
                echo '</select>';
                break;

            case 'image':
                $preview_html = '';
                if (!empty($value) && is_numeric($value)) {
                    $img_url = wp_get_attachment_image_url((int)$value, 'thumbnail');
                    if ($img_url) {
                        $preview_html = '<img src="' . esc_url($img_url) . '" style="max-width:150px;max-height:100px;">';
                    }
                }
                $has_image = !empty($preview_html);
                printf(
                    '<div class="site-settings-image-field">
                        <input type="hidden" id="%s" name="%s" value="%s">
                        <div id="preview-%s" class="site-settings-image-preview">%s</div>
                        <button type="button" class="button site-settings-upload-btn" data-input="%s" data-preview="preview-%s">
                            %s
                        </button>
                        <button type="button" class="button site-settings-remove-btn" data-input="%s" data-preview="preview-%s" style="%s">
                            %s
                        </button>
                    </div>',
                    $id,
                    $name,
                    esc_attr($value),
                    $id,
                    $preview_html,
                    $id,
                    $id,
                    __('Seleccionar imagen', 'site-settings'),
                    $id,
                    $id,
                    $has_image ? '' : 'display:none;',
                    __('Quitar', 'site-settings')
                );
                break;
        }

        if (!empty($description)) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }

    /**
     * Renderizar panel de plugins requeridos
     */
    private function render_plugins_panel() {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = Site_Settings::get_required_plugins();

        // Separar por tipo
        $custom_plugins = array_filter($plugins, fn($p) => $p['type'] === 'custom');
        $commercial_plugins = array_filter($plugins, fn($p) => $p['type'] === 'commercial');
        
        // Obtener información del tema requerido
        $required_theme = Site_Settings::get_required_theme();
        ?>

        <p class="description" style="margin-bottom:16px;">
            <?php _e('Lista de plugins y tema requerido por este template. Los marcados como <strong>requerido</strong> son necesarios para el funcionamiento base.', 'site-settings'); ?>
        </p>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- SECCIÓN: TEMA REQUERIDO -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <h3 style="margin-top:24px;margin-bottom:8px;">
            <span class="dashicons dashicons-admin-appearance" style="color:#2271b1;"></span>
            <?php _e('Tema Requerido', 'site-settings'); ?>
        </h3>
        <?php $this->render_theme_status($required_theme); ?>

        <h3 style="margin-top:28px;margin-bottom:8px;">
            <span class="dashicons dashicons-admin-tools" style="color:#2271b1;"></span>
            <?php _e('Plugins del Template (personalizados)', 'site-settings'); ?>
        </h3>
        <?php $this->render_plugins_table($custom_plugins); ?>

        <h3 style="margin-top:28px;margin-bottom:8px;">
            <span class="dashicons dashicons-plugins-checked" style="color:#2271b1;"></span>
            <?php _e('Plugins de Terceros (comerciales / open source)', 'site-settings'); ?>
        </h3>
        <?php $this->render_plugins_table($commercial_plugins); ?>
        <?php
    }

    /**
     * Renderizar tabla de plugins con estado
     */
    private function render_plugins_table($plugins) {
        ?>
        <table class="widefat striped site-settings-plugins-table">
            <thead>
                <tr>
                    <th style="width:24px;"></th>
                    <th><?php _e('Plugin', 'site-settings'); ?></th>
                    <th><?php _e('Descripci&oacute;n', 'site-settings'); ?></th>
                    <th style="width:100px;text-align:center;"><?php _e('Requerido', 'site-settings'); ?></th>
                    <th style="width:120px;text-align:center;"><?php _e('Estado', 'site-settings'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plugins as $plugin) :
                    $is_installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin['slug']);
                    $is_active = is_plugin_active($plugin['slug']);

                    if ($is_active) {
                        $status_label = __('Activo', 'site-settings');
                        $status_class = 'site-settings-status-active';
                        $status_icon = 'dashicons-yes-alt';
                    } elseif ($is_installed) {
                        $status_label = __('Inactivo', 'site-settings');
                        $status_class = 'site-settings-status-inactive';
                        $status_icon = 'dashicons-marker';
                    } else {
                        $status_label = __('No instalado', 'site-settings');
                        $status_class = 'site-settings-status-missing';
                        $status_icon = 'dashicons-dismiss';
                    }
                ?>
                    <tr>
                        <td style="text-align:center;">
                            <span class="dashicons <?php echo esc_attr($status_icon); ?> <?php echo esc_attr($status_class); ?>"></span>
                        </td>
                        <td><strong><?php echo esc_html($plugin['name']); ?></strong></td>
                        <td><?php echo esc_html($plugin['description']); ?></td>
                        <td style="text-align:center;">
                            <?php if ($plugin['required']) : ?>
                                <span class="site-settings-badge-required"><?php _e('Requerido', 'site-settings'); ?></span>
                            <?php else : ?>
                                <span class="site-settings-badge-optional"><?php _e('Opcional', 'site-settings'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <span class="site-settings-status-label <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Renderizar estado del tema requerido
     */
    private function render_theme_status($theme) {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Verificar estado del tema
        $is_active = site_is_required_theme_active();
        $theme_info = wp_get_theme();
        
        if ($is_active) {
            $status_label = __('Activo', 'site-settings');
            $status_class = 'site-settings-status-active';
            $status_icon = 'dashicons-yes-alt';
        } else {
            $status_label = __('No activo', 'site-settings');
            $status_class = 'site-settings-status-missing';
            $status_icon = 'dashicons-dismiss';
        }

        // Obtener tema activo actual para mostrar si no es el correcto
        $current_theme_name = $theme_info->get('Name');
        $current_theme_version = $theme_info->get('Version');
        ?>
        <table class="widefat striped site-settings-plugins-table">
            <thead>
                <tr>
                    <th style="width:24px;"></th>
                    <th><?php _e('Tema', 'site-settings'); ?></th>
                    <th><?php _e('Descripci&oacute;n', 'site-settings'); ?></th>
                    <th style="width:100px;text-align:center;"><?php _e('Requerido', 'site-settings'); ?></th>
                    <th style="width:120px;text-align:center;"><?php _e('Estado', 'site-settings'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="text-align:center;">
                        <span class="dashicons <?php echo esc_attr($status_icon); ?> <?php echo esc_attr($status_class); ?>"></span>
                    </td>
                    <td>
                        <strong><?php echo esc_html($theme['name']); ?></strong>
                        <?php if (!$is_active) : ?>
                            <br><small style="color:#d63638;">
                                <?php printf(__('Tema activo actual: %s (v%s)', 'site-settings'), esc_html($current_theme_name), esc_html($current_theme_version)); ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($theme['description']); ?></td>
                    <td style="text-align:center;">
                        <span class="site-settings-badge-required"><?php _e('Requerido', 'site-settings'); ?></span>
                    </td>
                    <td style="text-align:center;">
                        <span class="site-settings-status-label <?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html($status_label); ?>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Sanitizar valores de campos
     * También invalida el transient como seguridad extra: si WordPress detecta que
     * el valor no cambió, no dispara update_option y el transient podría quedar stale.
     */
    public function sanitize_field($value) {
        // Invalidar transient en cada save del formulario (belt-and-suspenders)
        delete_transient(SITE_SETTINGS_TRANSIENT_KEY);

        if (is_string($value)) {
            return sanitize_text_field($value);
        }
        return $value;
    }

    /**
     * Mapear tipo de campo a tipo de WP Settings API
     */
    private function get_wp_type($field_type) {
        $map = [
            'text'     => 'string',
            'url'      => 'string',
            'email'    => 'string',
            'textarea' => 'string',
            'color'    => 'string',
            'number'   => 'number',
            'image'    => 'integer',
            'select'   => 'string',
        ];
        return $map[$field_type] ?? 'string';
    }
}
