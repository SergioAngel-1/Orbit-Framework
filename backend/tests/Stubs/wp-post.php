<?php
/**
 * Stubs mínimos de `WP_Post` y `WP_Term`: solo las propiedades que lee el código propio
 * (cajas de edición, formularios de término).
 */

declare(strict_types=1);

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_name = '';
        public string $post_content = '';
    }
}

if (!class_exists('WP_Term')) {
    class WP_Term
    {
        public int $term_id = 0;
        public string $taxonomy = '';
        public string $name = '';
        public string $slug = '';
    }
}
