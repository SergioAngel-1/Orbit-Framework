<?php
/**
 * Correos de auth (restablecer contraseña y verificar email) en el idioma del visitante.
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_Mail {
    /**
     * Argumento `locale` de los correos de auth: el idioma del visitante. Cualquier
     * texto corto con forma de código de idioma; el que no sea del sitio cae al de la
     * tienda al redactar (`hwe_email_resolve_locale`).
     */
    private static function localeArg(): array {
        return [
            'required'          => false,
            'type'              => 'string',
            'default'           => '',
            'validate_callback' => static fn ($v) => $v === '' || (is_string($v) && (bool) preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $v)),
        ];
    }

    // ------------------------------------------------------------------------
    //  Correos de auth
    // ------------------------------------------------------------------------

    /**
     * Asunto + HTML (+ texto plano de reserva) de un correo de auth, en el idioma del
     * visitante. Único punto de armado: lo usan los handlers REST y la prueba de
     * plantillas del Control Center (hwe-email-test.php), para que la prueba sea el
     * correo REAL. Sin hwe-email-chrome.php, texto plano.
     *
     * @param 'reset'|'verify' $type
     * @return array{subject:string,body:string,headers:list<string>}
     */
    public static function buildEmail(string $type, string $display_name, string $link, string $locale): array {
        $prefix   = 'verify' === $type ? 'auth_verify' : 'auth_reset';
        $blogname = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);

        if (!function_exists('hwe_email_copy') || !function_exists('hwe_render_auth_email')) {
            $plain = 'verify' === $type
                ? "Hola {$display_name},\n\nConfirma tu email con este enlace:\n{$link}\n\nVence en 24 horas."
                : "Hola {$display_name},\n\nCrea tu contraseña nueva con este enlace:\n{$link}\n\nVence en 24 horas. Si no lo pediste, ignora este correo.";
            return [
                'subject' => $blogname . ' — ' . ('verify' === $type ? 'Verifica tu email' : 'Restablecer contraseña'),
                'body'    => $plain,
                'headers' => [],
            ];
        }

        $locale = hwe_email_resolve_locale($locale);
        $body   = '<p>' . esc_html(hwe_email_copy('greeting_named', $locale, $display_name)) . '</p>'
            . '<p>' . esc_html(hwe_email_copy($prefix . '_intro', $locale)) . '</p>'
            . hwe_email_button(hwe_email_copy($prefix . '_button', $locale), $link)
            . '<p style="font-size:12.5px;color:#6b7280;text-align:center;">' . esc_html(hwe_email_copy($prefix . '_ignore', $locale)) . '</p>';

        return [
            'subject' => $blogname . ' — ' . hwe_email_copy($prefix . '_subject', $locale),
            'body'    => hwe_render_auth_email(hwe_email_copy($prefix . '_heading', $locale), $body, $locale),
            'headers' => ['Content-Type: text/html; charset=UTF-8'],
        ];
    }
}
