<?php
/**
 * Stubs mínimos de las clases REST de WordPress, para probar handlers sin WP.
 *
 * Solo implementan lo que el código propio usa (get_param, get_header,
 * acceso como array, y los getters de la respuesta). Si un test necesita más,
 * se amplía aquí en lugar de duplicarlo en cada test.
 */

declare(strict_types=1);

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        /** @var array<string, mixed> */
        private array $params;
        /** @var array<string, string> */
        private array $headers = [];

        private string $route;

        /**
         * @param array<string, mixed>  $params  Parámetros (cuerpo, query y ruta).
         * @param array<string, string> $headers Cabeceras (cualquier capitalización).
         * @param string                $route   Ruta ya resuelta (p. ej. `/wc/store/v1/cart`).
         */
        public function __construct(array $params = [], array $headers = [], string $route = '')
        {
            $this->params = $params;
            $this->route = $route;
            foreach ($headers as $name => $value) {
                $this->headers[self::normalizeHeader($name)] = $value;
            }
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_header($key)
        {
            return $this->headers[self::normalizeHeader($key)] ?? null;
        }

        private static function normalizeHeader(string $name): string
        {
            return strtolower(str_replace('-', '_', $name));
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[$offset]);
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
