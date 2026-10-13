<?php
namespace HWE\ProductLanding;

/**
 * Revalida el tag ISR `products` del frontend cuando cambia una landing de producto, y lo declara
 * en `hwe_revalidation_tags` (sin esto el núcleo de WordPress no envía la petición: `products` no
 * es un tag de núcleo en WordPress).
 *
 * `Save` ya provoca el webhook nativo `product.updated` de WooCommerce cuando cambia el contenido
 * en el idioma por defecto, pero ese webhook es opcional (depende de que el sitio lo tenga dado de
 * alta) y no cubre el cambio de una sola traducción. Por eso el módulo se revalida a sí mismo:
 *
 * - `Save` lo pide en cuanto algo cambia;
 * - al guardar un producto con la landing publicada también se pide (la traducción la guarda
 *   `hwe-content-i18n` y el producto en sí puede no haber cambiado).
 *
 * Se envía una sola vez por petición.
 */
final class Revalidation {

    public const TAG = 'products';

    private static bool $sent = false;

    public static function register(): void {
        add_filter('hwe_revalidation_tags', [self::class, 'tags']);
        // Después de `Save` (10) y de las cajas de `hwe-content-i18n`.
        add_action('save_post_product', [self::class, 'onProductSaved'], 30, 1);
    }

    /**
     * @param mixed $tags
     * @return list<string>
     */
    public static function tags($tags): array {
        $tags = is_array($tags) ? array_values($tags) : [];
        if (!in_array(self::TAG, $tags, true)) {
            $tags[] = self::TAG;
        }
        return $tags;
    }

    public static function onProductSaved(int $postId): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return;
        }
        if (get_post_meta($postId, '_hwe_landing_published', true) === '1') {
            self::trigger();
        }
    }

    /** Pide la revalidación (una vez por petición). */
    public static function trigger(): void {
        if (self::$sent) {
            return;
        }
        if (class_exists('HWE\\ControlCenter\\Revalidation')) {
            self::$sent = true;
            \HWE\ControlCenter\Revalidation::trigger(self::TAG);
        }
    }
}
