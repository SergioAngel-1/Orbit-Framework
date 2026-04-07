<?php
/**
 * Campos personalizados del perfil de usuario
 * 
 * Muestra los campos adicionales del registro en la página de edición de usuario
 * (teléfono, cédula, aceptaciones legales, etc.)
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mostrar campos de registro en el perfil de usuario
 * 
 * @param WP_User $user El objeto del usuario que se está editando
 */
function starter_show_registration_fields($user) {
    // Solo administradores pueden ver estos campos
    if (!current_user_can('edit_users')) {
        return;
    }
    
    // Obtener los datos del usuario
    $phone = get_user_meta($user->ID, 'phone', true);
    $cedula = get_user_meta($user->ID, 'cedula', true);
    $birth_date = get_user_meta($user->ID, 'birth_date', true);
    $gender = get_user_meta($user->ID, 'gender', true);
    $accepted_terms = get_user_meta($user->ID, 'accepted_terms', true);
    $accepted_terms_date = get_user_meta($user->ID, 'accepted_terms_date', true);
    $accepted_data_veracity = get_user_meta($user->ID, 'accepted_data_veracity', true);
    $accepted_data_veracity_date = get_user_meta($user->ID, 'accepted_data_veracity_date', true);
    
    ?>
    <h2><?php _e('Datos de Registro', 'starter'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="phone"><?php _e('Teléfono', 'starter'); ?></label></th>
            <td>
                <input type="text" name="phone" id="phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" />
                <p class="description"><?php _e('Número de teléfono del usuario.', 'starter'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="cedula"><?php _e('Cédula / Documento', 'starter'); ?></label></th>
            <td>
                <input type="text" name="cedula" id="cedula" value="<?php echo esc_attr($cedula); ?>" class="regular-text" />
                <p class="description"><?php _e('Cédula o documento de identidad del usuario.', 'starter'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="birth_date"><?php _e('Fecha de Nacimiento', 'starter'); ?></label></th>
            <td>
                <input type="date" name="birth_date" id="birth_date" value="<?php echo esc_attr($birth_date); ?>" class="regular-text" />
                <p class="description"><?php _e('Fecha de nacimiento del usuario.', 'starter'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="gender"><?php _e('Género', 'starter'); ?></label></th>
            <td>
                <select name="gender" id="gender" class="regular-text">
                    <option value=""><?php _e('Seleccionar', 'starter'); ?></option>
                    <option value="male" <?php selected($gender, 'male'); ?>><?php _e('Masculino', 'starter'); ?></option>
                    <option value="female" <?php selected($gender, 'female'); ?>><?php _e('Femenino', 'starter'); ?></option>
                    <option value="other" <?php selected($gender, 'other'); ?>><?php _e('Otro', 'starter'); ?></option>
                    <option value="prefer_not_to_say" <?php selected($gender, 'prefer_not_to_say'); ?>><?php _e('Prefiero no decir', 'starter'); ?></option>
                </select>
                <p class="description"><?php _e('Género del usuario.', 'starter'); ?></p>
            </td>
        </tr>
    </table>
    
    <h2><?php _e('Aceptaciones Legales', 'starter'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="accepted_terms"><?php _e('Términos y Condiciones', 'starter'); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" name="accepted_terms" id="accepted_terms" value="1" <?php checked($accepted_terms); ?> />
                    <?php _e('Aceptado', 'starter'); ?>
                </label>
                <?php if ($accepted_terms_date): ?>
                    <p class="description"><?php echo sprintf(__('Fecha de aceptación: %s', 'starter'), $accepted_terms_date); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="accepted_data_veracity"><?php _e('Veracidad de Datos', 'starter'); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" name="accepted_data_veracity" id="accepted_data_veracity" value="1" <?php checked($accepted_data_veracity); ?> />
                    <?php _e('Aceptado', 'starter'); ?>
                </label>
                <?php if ($accepted_data_veracity_date): ?>
                    <p class="description"><?php echo sprintf(__('Fecha de aceptación: %s', 'starter'), $accepted_data_veracity_date); ?></p>
                <?php endif; ?>
                <p class="description" style="margin-top: 8px;">
                    <?php _e('Declaración de veracidad según Ley 1581 de 2012 (Habeas Data).', 'starter'); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'starter_show_registration_fields', 15);
add_action('edit_user_profile', 'starter_show_registration_fields', 15);

/**
 * Guardar campos de registro cuando se actualiza el perfil
 * 
 * @param int $user_id ID del usuario
 */
function starter_save_registration_fields($user_id) {
    // Verificar permisos
    if (!current_user_can('edit_users')) {
        return;
    }
    
    // Guardar teléfono
    if (isset($_POST['phone'])) {
        $phone = sanitize_text_field($_POST['phone']);
        update_user_meta($user_id, 'phone', $phone);
        update_user_meta($user_id, 'billing_phone', $phone);
    }
    
    // Guardar cédula
    if (isset($_POST['cedula'])) {
        $cedula = sanitize_text_field($_POST['cedula']);
        update_user_meta($user_id, 'cedula', $cedula);
        update_user_meta($user_id, 'billing_cedula', $cedula);
    }
    
    // Guardar fecha de nacimiento
    if (isset($_POST['birth_date'])) {
        $birth_date = sanitize_text_field($_POST['birth_date']);
        update_user_meta($user_id, 'birth_date', $birth_date);
    }
    
    // Guardar género
    if (isset($_POST['gender'])) {
        $gender = sanitize_text_field($_POST['gender']);
        update_user_meta($user_id, 'gender', $gender);
    }
    
    // Guardar aceptación de términos y condiciones
    $prev_terms = get_user_meta($user_id, 'accepted_terms', true);
    $new_terms = !empty($_POST['accepted_terms']) ? 1 : 0;
    update_user_meta($user_id, 'accepted_terms', $new_terms);
    if ($new_terms && !$prev_terms) {
        update_user_meta($user_id, 'accepted_terms_date', current_time('mysql'));
    } elseif (!$new_terms) {
        delete_user_meta($user_id, 'accepted_terms_date');
    }
    
    // Guardar aceptación de veracidad de datos
    $prev_veracity = get_user_meta($user_id, 'accepted_data_veracity', true);
    $new_veracity = !empty($_POST['accepted_data_veracity']) ? 1 : 0;
    update_user_meta($user_id, 'accepted_data_veracity', $new_veracity);
    if ($new_veracity && !$prev_veracity) {
        update_user_meta($user_id, 'accepted_data_veracity_date', current_time('mysql'));
    } elseif (!$new_veracity) {
        delete_user_meta($user_id, 'accepted_data_veracity_date');
    }
}
add_action('personal_options_update', 'starter_save_registration_fields', 15);
add_action('edit_user_profile_update', 'starter_save_registration_fields', 15);
