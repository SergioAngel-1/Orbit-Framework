<?php
/**
 * Funciones para gestionar documentos legales
 * 
 * Este archivo contiene las funciones para registrar un Custom Post Type
 * para documentos legales y crear endpoints de API REST para acceder a ellos.
 */

/**
 * Registrar Custom Post Type para documentos legales
 */
function register_legal_post_type() {
    $labels = array(
        'name'               => 'Documentos Legales',
        'singular_name'      => 'Documento Legal',
        'menu_name'          => 'Legal',
        'name_admin_bar'     => 'Documento Legal',
        'add_new'            => 'Añadir Nuevo',
        'add_new_item'       => 'Añadir Nuevo Documento Legal',
        'new_item'           => 'Nuevo Documento Legal',
        'edit_item'          => 'Editar Documento Legal',
        'view_item'          => 'Ver Documento Legal',
        'all_items'          => 'Todos los Documentos Legales',
        'search_items'       => 'Buscar Documentos Legales',
        'parent_item_colon'  => 'Documento Legal Padre:',
        'not_found'          => 'No se encontraron documentos legales.',
        'not_found_in_trash' => 'No se encontraron documentos legales en la papelera.'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'legal'),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => 20,
        'menu_icon'          => 'dashicons-shield',
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest'       => true,
    );

    register_post_type('legal', $args);
}
add_action('init', 'register_legal_post_type');

/**
 * Añadir metabox para el tipo de documento legal
 */
function add_legal_document_type_meta_box() {
    add_meta_box(
        'legal_document_type',
        'Tipo de Documento Legal',
        'legal_document_type_meta_box_callback',
        'legal',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'add_legal_document_type_meta_box');

/**
 * Callback para el metabox de tipo de documento legal
 */
function legal_document_type_meta_box_callback($post) {
    wp_nonce_field('legal_document_type_meta_box', 'legal_document_type_meta_box_nonce');

    $value = get_post_meta($post->ID, '_legal_document_type', true);
    $lang_value = get_post_meta($post->ID, '_legal_language', true);
    if (empty($lang_value)) {
        $lang_value = 'es';
    }

    ?>
    <div style="padding: 10px 0;">
        <p><strong>Selecciona el tipo de documento legal:</strong></p>
        <p style="color: #d63638; font-size: 12px; margin-bottom: 10px;">* Estos campos son obligatorios</p>
        
        <select name="legal_document_type" id="legal_document_type" style="width: 100%; padding: 8px; margin-bottom: 10px;" required>
            <option value="" <?php selected($value, ''); ?>>-- Seleccionar tipo de documento --</option>
            <option value="privacy_policy" <?php selected($value, 'privacy_policy'); ?>>Política de Privacidad</option>
            <option value="terms_conditions" <?php selected($value, 'terms_conditions'); ?>>Términos y Condiciones</option>
            <option value="referral_policy" <?php selected($value, 'referral_policy'); ?>>Política de Invitados</option>
            <option value="cookies_policy" <?php selected($value, 'cookies_policy'); ?>>Política de Cookies</option>
            <option value="return_policy" <?php selected($value, 'return_policy'); ?>>Política de Devoluciones</option>
            <option value="shipping_policy" <?php selected($value, 'shipping_policy'); ?>>Política de Envíos</option>
            <option value="police_procedure" <?php selected($value, 'police_procedure'); ?>>Guía ante Requisa Policial</option>
            <option value="protective_laws" <?php selected($value, 'protective_laws'); ?>>Marco Legal Protector</option>
            <option value="other" <?php selected($value, 'other'); ?>>Otro</option>
        </select>
        
        <p style="font-size: 12px; color: #666; margin-top: 5px;">
            Este tipo se utilizará para mostrar el documento en la sección correspondiente del sitio web.
        </p>
        
        <hr style="margin: 15px 0;" />
        
        <p><strong>Idioma del documento:</strong></p>
        <select name="legal_language" id="legal_language" style="width: 100%; padding: 8px; margin-bottom: 10px;" required>
            <option value="es" <?php selected($lang_value, 'es'); ?>>🇪🇸 Español</option>
            <option value="en" <?php selected($lang_value, 'en'); ?>>🇬🇧 English</option>
        </select>
        <p style="font-size: 12px; color: #666; margin-top: 5px;">
            Crea un documento por tipo por cada idioma. La API servirá el documento del idioma solicitado.
        </p>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Verificar el campo antes de publicar
        $('#publish, #save-post').click(function(e) {
            var documentType = $('#legal_document_type').val();
            if (!documentType) {
                e.preventDefault();
                alert('Por favor, selecciona un tipo de documento legal antes de guardar.');
                $('#legal_document_type').focus();
                $('#legal_document_type_meta_box').css('border', '1px solid #d63638');
            }
        });
        
        // Resaltar el campo cuando cambia
        $('#legal_document_type').change(function() {
            if ($(this).val()) {
                $(this).css('border-color', '#8bc34a');
            } else {
                $(this).css('border-color', '#d63638');
            }
        });
    });
    </script>
    <?php
}

/**
 * Guardar metadatos del documento legal
 */
function save_legal_document_type_meta($post_id) {
    if (!isset($_POST['legal_document_type_meta_box_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['legal_document_type_meta_box_nonce'], 'legal_document_type_meta_box')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Verificar que el campo no esté vacío
    if (!isset($_POST['legal_document_type']) || empty($_POST['legal_document_type'])) {
        // Si está vacío, establecer un mensaje de error
        add_filter('redirect_post_location', function($location) {
            return add_query_arg('legal_type_error', 1, $location);
        });
        return;
    }

    update_post_meta($post_id, '_legal_document_type', sanitize_text_field($_POST['legal_document_type']));
    
    // Guardar idioma del documento
    $language = isset($_POST['legal_language']) ? sanitize_text_field($_POST['legal_language']) : 'es';
    if (!in_array($language, array('es', 'en'), true)) {
        $language = 'es';
    }
    update_post_meta($post_id, '_legal_language', $language);
}
add_action('save_post_legal', 'save_legal_document_type_meta');

/**
 * Mostrar mensaje de error si el tipo de documento no se ha seleccionado
 */
function legal_document_type_admin_notice() {
    $screen = get_current_screen();
    
    if ($screen->post_type === 'legal' && isset($_GET['legal_type_error'])) {
        ?>
        <div class="error notice">
            <p><strong>Error:</strong> Debes seleccionar un tipo de documento legal antes de guardar.</p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'legal_document_type_admin_notice');

/**
 * Añadir columna de tipo de documento en el listado de documentos legales
 */
function add_legal_document_type_column($columns) {
    $columns['legal_document_type'] = 'Tipo de Documento';
    $columns['legal_language'] = 'Idioma';
    return $columns;
}
add_filter('manage_legal_posts_columns', 'add_legal_document_type_column');

/**
 * Mostrar el valor de la columna de tipo de documento
 */
function show_legal_document_type_column($column, $post_id) {
    if ($column === 'legal_document_type') {
        $type = get_post_meta($post_id, '_legal_document_type', true);
        $types = array(
            'privacy_policy' => 'Política de Privacidad',
            'terms_conditions' => 'Términos y Condiciones',
            'referral_policy' => 'Política de Invitados',
            'cookies_policy' => 'Política de Cookies',
            'return_policy' => 'Política de Devoluciones',
            'shipping_policy' => 'Política de Envíos',
            'police_procedure' => 'Guía ante Requisa Policial',
            'protective_laws' => 'Marco Legal Protector',
            'other' => 'Otro'
        );
        
        echo isset($types[$type]) ? $types[$type] : 'No definido';
    }
    
    if ($column === 'legal_language') {
        $lang = get_post_meta($post_id, '_legal_language', true);
        $langs = array(
            'es' => '🇪🇸 Español',
            'en' => '🇬🇧 English',
        );
        echo isset($langs[$lang]) ? $langs[$lang] : '🇪🇸 Español';
    }
}
add_action('manage_legal_posts_custom_column', 'show_legal_document_type_column', 10, 2);

/**
 * Registrar endpoint de API REST para documentos legales
 */
function register_legal_documents_rest_route() {
    register_rest_route('starter/v1', '/legal', array(
        'methods' => 'GET',
        'callback' => 'get_legal_documents_callback',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('starter/v1', '/legal/(?P<type>[a-zA-Z_]+)', array(
        'methods' => 'GET',
        'callback' => 'get_legal_document_by_type_callback',
        'permission_callback' => '__return_true',
        'args' => array(
            'type' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_string($param);
                }
            )
        )
    ));
    
}
add_action('rest_api_init', 'register_legal_documents_rest_route', 10);

/**
 * Callback para el endpoint de todos los documentos legales
 */
function get_legal_documents_callback($request) {
    // Detect language
    $lang = 'es';
    if (function_exists('starter_get_request_lang')) {
        $lang = starter_get_request_lang($request->get_param('lang'));
    } elseif ($request->get_param('lang')) {
        $lang = sanitize_text_field($request->get_param('lang'));
    }
    
    $args = array(
        'post_type' => 'legal',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_legal_language',
                'value' => $lang,
                'compare' => '='
            )
        )
    );

    $posts = get_posts($args);
    
    // Fallback: si no hay documentos en el idioma solicitado, buscar en español
    if (empty($posts) && $lang !== 'es') {
        $args['meta_query'][0]['value'] = 'es';
        $posts = get_posts($args);
    }
    
    // Fallback legacy: documentos sin meta _legal_language (se asumen español)
    if (empty($posts) && $lang === 'es') {
        $args_legacy = array(
            'post_type' => 'legal',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_legal_language',
                    'value' => 'es',
                    'compare' => '='
                ),
                array(
                    'key' => '_legal_language',
                    'compare' => 'NOT EXISTS'
                )
            )
        );
        $posts = get_posts($args_legacy);
    }

    if (empty($posts)) {
        return new WP_REST_Response(array(
            'message' => 'No se encontraron documentos legales.',
            'data' => array()
        ), 200);
    }

    $data = array();

    foreach ($posts as $post) {
        $data[] = starter_format_legal_document($post);
    }

    return new WP_REST_Response(array(
        'message' => 'Documentos legales obtenidos correctamente.',
        'data' => $data
    ), 200);
}

/**
 * Callback para el endpoint de documento legal por tipo
 */
function get_legal_document_by_type_callback($request) {
    $type = $request->get_param('type');
    
    // Detect language
    $lang = 'es';
    if (function_exists('starter_get_request_lang')) {
        $lang = starter_get_request_lang($request->get_param('lang'));
    } elseif ($request->get_param('lang')) {
        $lang = sanitize_text_field($request->get_param('lang'));
    }

    // Buscar documento del tipo e idioma solicitado
    $args = array(
        'post_type' => 'legal',
        'posts_per_page' => 1,
        'post_status' => 'publish',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_legal_document_type',
                'value' => $type,
                'compare' => '='
            ),
            array(
                'key' => '_legal_language',
                'value' => $lang,
                'compare' => '='
            )
        )
    );

    $posts = get_posts($args);
    
    // Fallback: si no hay documento en el idioma solicitado, buscar en español
    if (empty($posts) && $lang !== 'es') {
        $args['meta_query'][1]['value'] = 'es';
        $posts = get_posts($args);
    }
    
    // Fallback legacy: documentos sin meta _legal_language (se asumen español)
    if (empty($posts)) {
        $args_legacy = array(
            'post_type' => 'legal',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_legal_document_type',
                    'value' => $type,
                    'compare' => '='
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => '_legal_language',
                        'value' => 'es',
                        'compare' => '='
                    ),
                    array(
                        'key' => '_legal_language',
                        'compare' => 'NOT EXISTS'
                    )
                )
            )
        );
        $posts = get_posts($args_legacy);
    }

    if (empty($posts)) {
        return new WP_REST_Response(array(
            'message' => 'No se encontró el documento legal solicitado.',
            'data' => null
        ), 404);
    }

    $data = starter_format_legal_document($posts[0]);

    return new WP_REST_Response(array(
        'message' => 'Documento legal obtenido correctamente.',
        'data' => $data
    ), 200);
}

/**
 * Formatear un documento legal para la API
 */
function starter_format_legal_document($post) {
    $document_type = get_post_meta($post->ID, '_legal_document_type', true);
    $language = get_post_meta($post->ID, '_legal_language', true);
    
    return array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'content' => apply_filters('the_content', $post->post_content),
        'excerpt' => $post->post_excerpt,
        'type' => $document_type,
        'language' => $language ?: 'es',
        'slug' => $post->post_name,
        'date' => $post->post_date,
        'modified' => $post->post_modified,
    );
}
