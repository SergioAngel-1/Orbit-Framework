<?php
/**
 * Limpieza y consolidación del perfil de usuario
 * 
 * Este archivo oculta secciones innecesarias del perfil de WordPress
 * y previene conflictos con el sistema headless de React.
 * 
 * OCULTA:
 * - Contraseñas de aplicación (Application Passwords)
 * - Campos de dirección de WooCommerce (manejados por React)
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ocultar la sección de "Contraseñas de aplicación"
 * 
 * Esta funcionalidad no es necesaria para un sistema headless
 * donde la autenticación se maneja vía JWT.
 * 
 * IMPORTANTE: NO deshabilitamos completamente Application Passwords porque
 * WordPress las usa internamente para validar acceso REST API de ciertos roles.
 * Solo ocultamos la UI para evitar confusión.
 */
function starter_hide_application_passwords() {
    // NO deshabilitamos Application Passwords completamente
    // Solo ocultamos la UI en el perfil
    
    $css = '<style>
        /* Ocultar sección de Contraseñas de aplicación */
        .application-passwords-section,
        .user-application-passwords-wrap,
        #application-passwords-section,
        .application-passwords,
        div.application-passwords-section,
        table.application-passwords {
            display: none !important;
        }
        
        /* Ocultar el encabezado h2 de Contraseñas de aplicación */
        h2#application-passwords-section {
            display: none !important;
        }
    </style>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Ocultar sección de contraseñas de aplicación por texto
            $("h2, h3").each(function() {
                var text = $(this).text().toLowerCase();
                if (text.includes("contraseñas de aplicación") || 
                    text.includes("application passwords")) {
                    $(this).hide();
                    $(this).nextUntil("h2, h3").hide();
                }
            });
        });
    </script>';
    
    // Ocultar la sección en el perfil con CSS
    add_action('admin_head-profile.php', function() use ($css) {
        echo $css;
    });
    
    add_action('admin_head-user-edit.php', function() use ($css) {
        echo $css;
    });
}
add_action('init', 'starter_hide_application_passwords');

/**
 * Ocultar opciones personales innecesarias del perfil
 * 
 * Oculta: esquema de colores, atajos de teclado, idioma, barra de herramientas
 */
function starter_hide_personal_options() {
    // Ocultar con CSS en profile.php y user-edit.php
    $css = '<style>
        /* Ocultar esquema de colores de administración */
        .user-admin-color-wrap,
        tr.user-admin-color-wrap {
            display: none !important;
        }
        
        /* Ocultar atajos de teclado */
        .user-comment-shortcuts-wrap,
        tr.user-comment-shortcuts-wrap {
            display: none !important;
        }
        
        /* Ocultar barra de herramientas */
        .user-admin-bar-front-wrap,
        tr.user-admin-bar-front-wrap,
        .show-admin-bar {
            display: none !important;
        }
        
        /* Ocultar idioma */
        .user-language-wrap,
        tr.user-language-wrap {
            display: none !important;
        }
        
        /* Ocultar campo Web/URL */
        .user-url-wrap,
        tr.user-url-wrap {
            display: none !important;
        }
        
        /* Ocultar sección "Acerca del usuario" e Información biográfica */
        .user-description-wrap,
        tr.user-description-wrap {
            display: none !important;
        }
        
        /* Ocultar Imagen de perfil / Gravatar */
        .user-profile-picture,
        tr.user-profile-picture {
            display: none !important;
        }
        
        /* Ocultar sección "Opciones personales" si queda vacía */
        h2:contains("Opciones personales"):empty {
            display: none !important;
        }
    </style>';
    
    add_action('admin_head-profile.php', function() use ($css) {
        echo $css;
    });
    
    add_action('admin_head-user-edit.php', function() use ($css) {
        echo $css;
    });
}
add_action('init', 'starter_hide_personal_options');

/**
 * Remover opciones personales del formulario de perfil
 */
function starter_remove_personal_options() {
    // Remover la acción que muestra las opciones personales
    remove_action('admin_color_scheme_picker', 'admin_color_scheme_picker');
}
add_action('admin_init', 'starter_remove_personal_options');

/**
 * Ocultar campos de dirección de WooCommerce en el perfil
 * 
 * Las direcciones se manejan completamente desde el frontend React
 * a través de la API REST personalizada.
 */
function starter_hide_woocommerce_address_fields() {
    add_action('admin_head-profile.php', function() {
        ?>
        <style>
            /* Ocultar secciones de dirección de WooCommerce */
            .woocommerce-address-fields,
            .woocommerce-customer-billing-fields,
            .woocommerce-customer-shipping-fields {
                display: none !important;
            }
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Ocultar encabezados que contengan texto de direcciones
                $('h2, h3').each(function() {
                    var text = $(this).text().toLowerCase();
                    if (text.includes('dirección de pedido') || 
                        text.includes('dirección de facturación') || 
                        text.includes('dirección de envío') ||
                        text.includes('dirección del pedido') ||
                        text.includes('customer billing address') ||
                        text.includes('customer shipping address')) {
                        $(this).hide();
                        // Ocultar también la tabla siguiente
                        $(this).next('table.form-table').hide();
                    }
                });
            });
        </script>
        <?php
    });
    
    add_action('admin_head-user-edit.php', function() {
        ?>
        <style>
            /* Ocultar secciones de dirección de WooCommerce */
            .woocommerce-address-fields,
            .woocommerce-customer-billing-fields,
            .woocommerce-customer-shipping-fields {
                display: none !important;
            }
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Ocultar encabezados que contengan texto de direcciones
                $('h2, h3').each(function() {
                    var text = $(this).text().toLowerCase();
                    if (text.includes('dirección de pedido') || 
                        text.includes('dirección de facturación') || 
                        text.includes('dirección de envío') ||
                        text.includes('dirección del pedido') ||
                        text.includes('customer billing address') ||
                        text.includes('customer shipping address')) {
                        $(this).hide();
                        // Ocultar también la tabla siguiente
                        $(this).next('table.form-table').hide();
                    }
                });
            });
        </script>
        <?php
    });
}
add_action('init', 'starter_hide_woocommerce_address_fields');

/**
 * Remover campos de dirección de WooCommerce del perfil
 * 
 * Método más agresivo: desregistrar los hooks de WooCommerce
 */
function starter_remove_woocommerce_profile_fields() {
    // Verificar que WooCommerce esté activo
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Remover los hooks de WooCommerce que agregan campos de dirección
    // Usar prioridad 10 que es la que usa WooCommerce por defecto
    remove_action('show_user_profile', array('WC_Admin_Profile', 'add_customer_meta_fields'), 10);
    remove_action('edit_user_profile', array('WC_Admin_Profile', 'add_customer_meta_fields'), 10);
    
    // También remover los hooks de guardado
    remove_action('personal_options_update', array('WC_Admin_Profile', 'save_customer_meta_fields'));
    remove_action('edit_user_profile_update', array('WC_Admin_Profile', 'save_customer_meta_fields'));
}
// Ejecutar después de que WooCommerce registre sus hooks
add_action('admin_init', 'starter_remove_woocommerce_profile_fields', 20);

/**
 * Agregar nota informativa sobre las direcciones
 * 
 * Muestra un mensaje indicando que las direcciones se gestionan desde el frontend
 */
function starter_add_address_info_notice($user) {
    // Solo mostrar para usuarios que no son administradores
    if (!current_user_can('manage_options')) {
        ?>
        <h2><?php _e('Direcciones de Envío', 'starter'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <td colspan="2">
                    <div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 12px; margin: 10px 0;">
                        <p style="margin: 0; font-size: 14px;">
                            <strong>ℹ️ Gestión de Direcciones</strong><br>
                            Las direcciones de envío se gestionan desde tu perfil en la tienda online.
                            <br><br>
                            <a href="<?php echo home_url('/perfil'); ?>" target="_blank" class="button button-primary">
                                Ir a Mi Perfil en la Tienda
                            </a>
                        </p>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }
}
add_action('show_user_profile', 'starter_add_address_info_notice', 30);
add_action('edit_user_profile', 'starter_add_address_info_notice', 30);

/**
 * Prevenir guardado de campos de dirección de WooCommerce
 * 
 * Como las direcciones se manejan desde React, no queremos que se guarden
 * desde el panel de WordPress para evitar conflictos.
 */
function starter_prevent_woocommerce_address_save($user_id) {
    // Solo prevenir para usuarios no administradores
    if (!current_user_can('manage_options')) {
        // Lista de campos de WooCommerce que no queremos guardar desde aquí
        $wc_address_fields = array(
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_postcode', 'billing_country', 'billing_state',
            'billing_phone', 'billing_email',
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_postcode', 'shipping_country', 'shipping_state'
        );
        
        // Remover estos campos del POST para que no se guarden
        foreach ($wc_address_fields as $field) {
            if (isset($_POST[$field])) {
                unset($_POST[$field]);
            }
        }
    }
}
add_action('personal_options_update', 'starter_prevent_woocommerce_address_save', 5);
add_action('edit_user_profile_update', 'starter_prevent_woocommerce_address_save', 5);
