<?php

namespace HWE\ControlCenter;

/**
 * Avisos del panel (éxito/error) con el markup de admin notices de WordPress.
 *
 * Vive aparte para que el render (`AdminPage`) y el guardado (`AdminSave`) compartan la
 * misma presentación sin duplicarla.
 */
final class AdminNotices {

    public static function notice(string $type, string $message): string {
        return sprintf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }
}
