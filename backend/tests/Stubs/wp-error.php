<?php
/**
 * Stub mínimo de `WP_Error`, para probar código que devuelve errores de WordPress sin WP.
 * Implementa los getters y `add()`/`get_error_codes()` que usan los tests (un error puede
 * acumular varios códigos).
 */

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var list<string|int> */
        private array $codes = [];
        /** @var list<string> */
        private array $messages = [];
        private mixed $data = '';

        public function __construct(string|int $code = '', string $message = '', mixed $data = '')
        {
            if ($code !== '') {
                $this->codes[] = $code;
                $this->messages[] = $message;
                $this->data = $data;
            }
        }

        public function add(string|int $code, string $message, mixed $data = ''): void
        {
            $this->codes[] = $code;
            $this->messages[] = $message;
            if ($data !== '') {
                $this->data = $data;
            }
        }

        public function get_error_code(): string|int
        {
            return $this->codes[0] ?? '';
        }

        /** @return list<string|int> */
        public function get_error_codes(): array
        {
            return $this->codes;
        }

        public function get_error_message(): string
        {
            return $this->messages[0] ?? '';
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}
