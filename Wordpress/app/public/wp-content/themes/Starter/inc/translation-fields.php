<?php
/**
 * Translation Meta Fields for WooCommerce Products & Categories
 * 
 * Registers custom meta fields for English translations of product/category
 * names and descriptions. The default language (Spanish) uses the native
 * WooCommerce fields. Non-default languages are stored as post/term meta.
 * 
 * Meta keys registered:
 *   Products:  _name_en, _short_description_en, _description_en
 *   Categories: _name_en, _description_en
 * 
 * @package Starter
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────

define('STARTER_TRANSLATION_LANGS', ['en']);
define('STARTER_TRANSLATION_LANG_LABELS', ['en' => 'English']);

// ─── Product Translation Fields (WooCommerce Product Data Tab) ───────────────

/**
 * Add a "Translations" tab to the WooCommerce product data panel
 */
function starter_add_translation_product_tab($tabs) {
    $tabs['starter_translations'] = [
        'label'    => __('Translations', 'starter'),
        'target'   => 'starter_translations_panel',
        'class'    => ['show_if_simple', 'show_if_variable', 'show_if_grouped', 'show_if_external'],
        'priority' => 80,
    ];
    return $tabs;
}
add_filter('woocommerce_product_data_tabs', 'starter_add_translation_product_tab');

/**
 * Render the translations panel inside the product data metabox
 */
function starter_render_translation_product_panel() {
    global $post;
    $product_id = $post->ID;

    echo '<div id="starter_translations_panel" class="panel woocommerce_options_panel">';
    echo '<div class="options_group">';

    foreach (STARTER_TRANSLATION_LANGS as $lang) {
        $label = STARTER_TRANSLATION_LANG_LABELS[$lang] ?? strtoupper($lang);
        $name_val       = get_post_meta($product_id, "_name_{$lang}", true);
        $short_desc_val = get_post_meta($product_id, "_short_description_{$lang}", true);
        $desc_val       = get_post_meta($product_id, "_description_{$lang}", true);

        echo '<h4 style="padding: 0 12px; margin: 16px 0 8px; border-bottom: 1px solid #eee; padding-bottom: 8px;">';
        echo esc_html($label);
        echo '</h4>';

        // Name
        woocommerce_wp_text_input([
            'id'          => "_name_{$lang}",
            'label'       => __('Name', 'starter') . " ({$label})",
            'placeholder' => __('Leave empty to use default', 'starter'),
            'value'       => $name_val,
        ]);

        // Short description
        woocommerce_wp_textarea_input([
            'id'          => "_short_description_{$lang}",
            'label'       => __('Short description', 'starter') . " ({$label})",
            'placeholder' => __('Leave empty to use default', 'starter'),
            'value'       => $short_desc_val,
        ]);

        // Full description
        woocommerce_wp_textarea_input([
            'id'          => "_description_{$lang}",
            'label'       => __('Description', 'starter') . " ({$label})",
            'placeholder' => __('Leave empty to use default', 'starter'),
            'value'       => $desc_val,
            'style'       => 'min-height: 120px;',
        ]);
    }

    echo '</div>';
    echo '</div>';
}
add_action('woocommerce_product_data_panels', 'starter_render_translation_product_panel');

/**
 * Save product translation meta on product save
 */
function starter_save_product_translations($product_id) {
    foreach (STARTER_TRANSLATION_LANGS as $lang) {
        $fields = ["_name_{$lang}", "_short_description_{$lang}", "_description_{$lang}"];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = wp_kses_post(wp_unslash($_POST[$field]));
                update_post_meta($product_id, $field, $value);
            }
        }
    }
}
add_action('woocommerce_process_product_meta', 'starter_save_product_translations');

// ─── Category Translation Fields ─────────────────────────────────────────────

/**
 * Add translation fields to the "Add Category" form
 */
function starter_add_category_translation_fields() {
    foreach (STARTER_TRANSLATION_LANGS as $lang) {
        $label = STARTER_TRANSLATION_LANG_LABELS[$lang] ?? strtoupper($lang);
        ?>
        <div class="form-field">
            <label for="starter_name_<?php echo esc_attr($lang); ?>">
                <?php echo esc_html(__('Name', 'starter') . " ({$label})"); ?>
            </label>
            <input type="text" name="_name_<?php echo esc_attr($lang); ?>" id="starter_name_<?php echo esc_attr($lang); ?>" value="" />
            <p class="description"><?php esc_html_e('Leave empty to use the default name.', 'starter'); ?></p>
        </div>
        <div class="form-field">
            <label for="starter_description_<?php echo esc_attr($lang); ?>">
                <?php echo esc_html(__('Description', 'starter') . " ({$label})"); ?>
            </label>
            <textarea name="_description_<?php echo esc_attr($lang); ?>" id="starter_description_<?php echo esc_attr($lang); ?>" rows="3"></textarea>
            <p class="description"><?php esc_html_e('Leave empty to use the default description.', 'starter'); ?></p>
        </div>
        <?php
    }
}
add_action('product_cat_add_form_fields', 'starter_add_category_translation_fields', 20);

/**
 * Add translation fields to the "Edit Category" form
 */
function starter_edit_category_translation_fields($term) {
    foreach (STARTER_TRANSLATION_LANGS as $lang) {
        $label    = STARTER_TRANSLATION_LANG_LABELS[$lang] ?? strtoupper($lang);
        $name_val = get_term_meta($term->term_id, "_name_{$lang}", true);
        $desc_val = get_term_meta($term->term_id, "_description_{$lang}", true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="starter_name_<?php echo esc_attr($lang); ?>">
                    <?php echo esc_html(__('Name', 'starter') . " ({$label})"); ?>
                </label>
            </th>
            <td>
                <input type="text" name="_name_<?php echo esc_attr($lang); ?>" id="starter_name_<?php echo esc_attr($lang); ?>" value="<?php echo esc_attr($name_val); ?>" />
                <p class="description"><?php esc_html_e('Leave empty to use the default name.', 'starter'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row">
                <label for="starter_description_<?php echo esc_attr($lang); ?>">
                    <?php echo esc_html(__('Description', 'starter') . " ({$label})"); ?>
                </label>
            </th>
            <td>
                <textarea name="_description_<?php echo esc_attr($lang); ?>" id="starter_description_<?php echo esc_attr($lang); ?>" rows="3"><?php echo esc_textarea($desc_val); ?></textarea>
                <p class="description"><?php esc_html_e('Leave empty to use the default description.', 'starter'); ?></p>
            </td>
        </tr>
        <?php
    }
}
add_action('product_cat_edit_form_fields', 'starter_edit_category_translation_fields', 20);

/**
 * Save category translation meta on create/edit
 */
function starter_save_category_translations($term_id) {
    foreach (STARTER_TRANSLATION_LANGS as $lang) {
        $fields = ["_name_{$lang}", "_description_{$lang}"];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = sanitize_text_field(wp_unslash($_POST[$field]));
                update_term_meta($term_id, $field, $value);
            }
        }
    }
}
add_action('created_product_cat', 'starter_save_category_translations');
add_action('edited_product_cat', 'starter_save_category_translations');

// ─── Translation Helper ──────────────────────────────────────────────────────

/**
 * Get the requested language from the current REST request.
 * Returns 'es' (default) if no lang param or unsupported lang.
 *
 * Resolution order:
 *   1. Explicit $lang parameter (when caller already knows the lang)
 *   2. $_GET['lang'] (available on normal HTTP REST requests)
 *   3. $_REQUEST['lang'] (covers POST body params)
 *
 * @param string|null $lang Optional explicit language code to validate and return.
 * @return string Language code ('es', 'en', etc.)
 */
function starter_get_request_lang($lang = null) {
    // 1. Explicit parameter from caller
    if ($lang !== null) {
        $lang = sanitize_text_field($lang);
        if ($lang && in_array($lang, STARTER_TRANSLATION_LANGS, true)) {
            return $lang;
        }
    }

    // 2. $_GET (standard REST query params)
    if (isset($_GET['lang'])) {
        $get_lang = sanitize_text_field($_GET['lang']);
        if ($get_lang && in_array($get_lang, STARTER_TRANSLATION_LANGS, true)) {
            return $get_lang;
        }
    }

    // 3. $_REQUEST (covers POST/PUT body params)
    if (isset($_REQUEST['lang'])) {
        $req_lang = sanitize_text_field($_REQUEST['lang']);
        if ($req_lang && in_array($req_lang, STARTER_TRANSLATION_LANGS, true)) {
            return $req_lang;
        }
    }

    return 'es'; // default
}

/**
 * Apply translations to a single product data array.
 * Replaces name, short_description, description with translated versions
 * if they exist for the requested language.
 * Also translates category names embedded in the product.
 *
 * @param array  $product Product data array (from WC REST API)
 * @param string $lang    Target language code
 * @return array Modified product data
 */
function starter_translate_product($product, $lang) {
    if ($lang === 'es' || !is_array($product) || !isset($product['id'])) {
        return $product;
    }

    $product_id = intval($product['id']);

    // Translate product fields
    $field_map = [
        'name'              => "_name_{$lang}",
        'short_description' => "_short_description_{$lang}",
        'description'       => "_description_{$lang}",
    ];

    foreach ($field_map as $api_field => $meta_key) {
        $translated = get_post_meta($product_id, $meta_key, true);
        if (!empty($translated)) {
            $product[$api_field] = $translated;
        }
    }

    // Translate embedded category names
    if (isset($product['categories']) && is_array($product['categories'])) {
        foreach ($product['categories'] as &$category) {
            $cat_id = isset($category['id']) ? intval($category['id']) : 0;
            if ($cat_id > 0) {
                $translated_name = get_term_meta($cat_id, "_name_{$lang}", true);
                if (!empty($translated_name)) {
                    $category['name'] = $translated_name;
                }
            }
        }
        unset($category);
    }

    return $product;
}

/**
 * Preload term meta cache for all category IDs embedded in a products array.
 * Call this before translating a list of products to avoid N+1 queries
 * on get_term_meta() inside starter_translate_product().
 *
 * @param array $products Array of product data arrays (from WC REST API)
 */
function starter_preload_category_translation_meta($products) {
    if (!is_array($products)) {
        return;
    }

    $cat_ids = [];
    foreach ($products as $product) {
        if (isset($product['categories']) && is_array($product['categories'])) {
            foreach ($product['categories'] as $cat) {
                if (isset($cat['id'])) {
                    $cat_ids[] = intval($cat['id']);
                }
            }
        }
    }

    $cat_ids = array_unique($cat_ids);
    if (!empty($cat_ids)) {
        update_meta_cache('term', $cat_ids);
    }
}

/**
 * Apply translations to a single category data array.
 *
 * @param array  $category Category data array
 * @param string $lang     Target language code
 * @return array Modified category data
 */
function starter_translate_category($category, $lang) {
    if ($lang === 'es' || !is_array($category)) {
        return $category;
    }

    $cat_id = isset($category['id']) ? intval($category['id']) 
            : (isset($category['term_id']) ? intval($category['term_id']) : 0);

    if ($cat_id <= 0) {
        return $category;
    }

    $translated_name = get_term_meta($cat_id, "_name_{$lang}", true);
    if (!empty($translated_name)) {
        $category['name'] = $translated_name;
    }

    $translated_desc = get_term_meta($cat_id, "_description_{$lang}", true);
    if (!empty($translated_desc)) {
        $category['description'] = $translated_desc;
    }

    return $category;
}
