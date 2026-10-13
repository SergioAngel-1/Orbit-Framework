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

if (!class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;
        /** @var list<string> */
        public array $roles = [];
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

if (!class_exists('WP_Query')) {
    /**
     * `WP_Query` mínima: solo lo que leen los filtros propios sobre la búsqueda
     * (`is_search()` y `get()` con las variables de consulta que se le dan).
     */
    class WP_Query
    {
        /** @var array<string,mixed> */
        public array $query_vars = [];

        public bool $search = false;

        /** @param array<string,mixed> $vars */
        public function __construct(array $vars = [], bool $search = false)
        {
            $this->query_vars = $vars;
            $this->search = $search;
        }

        public function is_search(): bool
        {
            return $this->search;
        }

        public function get(string $var, mixed $default = ''): mixed
        {
            return $this->query_vars[$var] ?? $default;
        }
    }
}
