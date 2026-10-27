<?php
/**
 * Contraseña olvidada, restablecimiento y verificación de email.
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_Account {
    /** Intentos fallidos de contraseña actual antes de bloquear, y cuánto dura el bloqueo. */
    private const PASSWORD_CHANGE_MAX_FAILURES = 5;
    private const PASSWORD_CHANGE_LOCK_SECONDS = 900;

    /**
     * Cambia la contraseña de quien llama, verificando la ACTUAL.
     *
     * El BFF ya limita por IP, pero este endpoint adivina una contraseña con una sesión
     * robada: se acota también por usuario (5 fallos → 429 durante 15 minutos, aunque la
     * contraseña enviada sea la correcta).
     */
    public static function handleChangePassword(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $user    = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            return new WP_REST_Response(['error' => 'Usuario no autenticado.'], 401);
        }

        $current = (string) $request->get_param('current_password');
        $new     = (string) $request->get_param('new_password');
        if (strlen($new) < 8) {
            return new WP_REST_Response(['error' => 'La contraseña nueva debe tener al menos 8 caracteres.'], 422);
        }

        $lock_key = 'hwe_pwchange_' . $user_id;
        $failures = (int) get_transient($lock_key);
        if ($failures >= self::PASSWORD_CHANGE_MAX_FAILURES) {
            return new WP_REST_Response(['error' => 'Demasiados intentos. Inténtalo de nuevo más tarde.'], 429);
        }

        if (!wp_check_password($current, (string) $user->user_pass, $user_id)) {
            set_transient($lock_key, $failures + 1, self::PASSWORD_CHANGE_LOCK_SECONDS);
            return new WP_REST_Response(['error' => 'La contraseña actual no es correcta.'], 403);
        }

        $result = wp_update_user(['ID' => $user_id, 'user_pass' => $new]);
        if (is_wp_error($result)) {
            error_log('[hwe] auth: no se pudo cambiar la contraseña del usuario ' . $user_id . ': ' . $result->get_error_message());
            return new WP_REST_Response(['error' => 'No se pudo cambiar la contraseña.'], 500);
        }

        delete_transient($lock_key);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleForgotPassword(WP_REST_Request $request): WP_REST_Response {
        $user_login  = $request->get_param('user_login');
        $frontend_url = untrailingslashit($request->get_param('frontend_url'));

        $user = get_user_by('login', $user_login);
        if (!$user) {
            $user = get_user_by('email', $user_login);
        }
        if (!$user) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $reset_url  = (string) $request->get_param('reset_url');
        $reset_link = add_query_arg(
            ['key' => rawurlencode($key), 'login' => rawurlencode($user->user_login)],
            $reset_url !== '' ? $reset_url : $frontend_url . '/reset-password'
        );

        $mail = self::buildEmail('reset', (string) $user->display_name, $reset_link, (string) $request->get_param('locale'));

        // Se responde 200 igualmente (no se revela si la cuenta existe), pero el
        // fallo queda en el log: sin esto un SMTP caído no se veía en ningún sitio.
        if (!wp_mail($user->user_email, $mail['subject'], $mail['body'], $mail['headers'])) {
            error_log('[hwe] auth: forgot-password: wp_mail falló al enviar el enlace de reseteo (revisa el log del SMTP).');
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleResetPassword(WP_REST_Request $request): WP_REST_Response {
        $key         = $request->get_param('key');
        $login       = $request->get_param('login');
        $new_password = $request->get_param('new_password');

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            $code = $user->get_error_code();
            return new WP_REST_Response(
                ['error' => $code === 'expired_key' ? 'El enlace ha expirado.' : 'El enlace no es válido.'],
                400
            );
        }

        reset_password($user, $new_password);

        // Un reset es una recuperación de cuenta: invalida los refresh tokens emitidos
        // antes (misma rotación que `logout-all`) y las sesiones nativas de WordPress.
        // La acción `wp_set_password` ya lo intenta, pero se repite aquí para poder
        // responder 500 si no se pudo cerrar ninguna: reportar éxito dejaría a la
        // víctima creyendo que recuperó la cuenta mientras la sesión robada sigue viva.
        if (!self::revokeRefreshTokens((int) $user->ID)) {
            error_log('[hwe] auth: reset-password: no se pudo rotar el secreto JWT del usuario ' . $user->ID);
            return new WP_REST_Response(['error' => 'No se pudieron cerrar las sesiones anteriores.'], 500);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleSendVerification(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(['error' => 'Usuario no autenticado.'], 401);
        }

        $already_verified = get_user_meta($user_id, 'hwe_email_verified', true);
        if ($already_verified) {
            return new WP_REST_Response(['error' => 'El email ya está verificado.'], 400);
        }

        $verification_url = $request->get_param('verification_url');
        $user = get_userdata($user_id);

        // Token aleatorio + marca temporal (para poder expirarlo).
        $token = bin2hex(random_bytes(32));
        update_user_meta($user_id, 'hwe_email_verification_token', $token);
        update_user_meta($user_id, 'hwe_email_verification_token_time', time());

        $verify_link = add_query_arg('token', rawurlencode($token), $verification_url);
        $mail        = self::buildEmail('verify', (string) $user->display_name, $verify_link, (string) $request->get_param('locale'));

        // Un envío fallido (SMTP caído o mal configurado) se DICE: antes se
        // respondía 200 y el usuario esperaba un correo que nunca existió.
        if (!wp_mail($user->user_email, $mail['subject'], $mail['body'], $mail['headers'])) {
            error_log('[hwe] auth: send-verification: wp_mail falló al enviar el correo de verificación (revisa el log del SMTP).');
            return new WP_REST_Response(['error' => 'No se pudo enviar el email.'], 502);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleVerifyEmail(WP_REST_Request $request): WP_REST_Response {
        $token = (string) $request->get_param('token');

        // El dueño: el usuario de la sesión si el token es suyo; si no, el que tenga
        // ese token guardado. Comparación en tiempo constante en ambos casos.
        $user_id = 0;
        $current = get_current_user_id();
        if ($current) {
            $stored = (string) get_user_meta($current, 'hwe_email_verification_token', true);
            if ($stored !== '' && hash_equals($stored, $token)) {
                $user_id = $current;
            }
        }
        if (!$user_id) {
            $users = get_users([
                'meta_key'   => 'hwe_email_verification_token',
                'meta_value' => $token,
                'number'     => 1,
                'fields'     => 'ID',
            ]);
            $candidate = (int) ($users[0] ?? 0);
            $stored    = $candidate ? (string) get_user_meta($candidate, 'hwe_email_verification_token', true) : '';
            if ($stored === '' || !hash_equals($stored, $token)) {
                return new WP_REST_Response(['error' => 'Token inválido.'], 400);
            }
            $user_id = $candidate;
        }

        // Enlace ya usado: el token se CONSERVA tras verificar (ver abajo), así que
        // volver a abrirlo (otro dispositivo, doble clic, el doble efecto de React en
        // desarrollo) responde OK en vez de «enlace no válido» sobre un email que sí
        // quedó verificado.
        if (get_user_meta($user_id, 'hwe_email_verified', true)) {
            return new WP_REST_Response(['ok' => true, 'already_verified' => true], 200);
        }

        $issued = (int) get_user_meta($user_id, 'hwe_email_verification_token_time', true);
        if ($issued > 0 && (time() - $issued) > self::EMAIL_TOKEN_TTL) {
            delete_user_meta($user_id, 'hwe_email_verification_token');
            delete_user_meta($user_id, 'hwe_email_verification_token_time');
            return new WP_REST_Response(['error' => 'El enlace ha expirado. Solicita uno nuevo.'], 400);
        }

        // El token NO se borra: ya solo sirve para reconocer el enlace usado (arriba).
        // No da acceso a nada, y con el email verificado /send-verification no emite otro.
        update_user_meta($user_id, 'hwe_email_verified', '1');

        // Acción estable para quien dependa de la verificación (p. ej. los puntos de alta de
        // referidos). Solo se dispara en la transición real: la rama «ya verificado» sale antes.
        do_action('hwe_email_verified', $user_id);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
