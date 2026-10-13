<?php
namespace HWE\ProductLanding;

final class MetaBox {
    public static function register(): void {
        add_action('add_meta_boxes_product', [self::class, 'add']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function add(): void {
        add_meta_box(
            'hwe_product_landing',
            'Landing del producto',
            [self::class, 'render'],
            'product',
            'normal',
            'default'
        );
    }

    public static function assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_editor();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('hwe-product-landing-admin', HWE_PRODUCT_LANDING_URL . 'assets/admin.css', ['wp-color-picker'], HWE_PRODUCT_LANDING_VERSION);
        wp_enqueue_script('hwe-product-landing-admin', HWE_PRODUCT_LANDING_URL . 'assets/admin.js', ['jquery', 'wp-color-picker'], HWE_PRODUCT_LANDING_VERSION, true);
    }

    public static function render(\WP_Post $post): void {
        wp_nonce_field('hwe_product_landing_save', 'hwe_product_landing_nonce');
        echo Render::metabox($post->ID);
    }
}
