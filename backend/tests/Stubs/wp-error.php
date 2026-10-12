<?php
/**
 * Stub mínimo de `WP_Error`, para probar código que devuelve errores de WordPress sin WP.
 * Solo implementa los getters que usan los tests.
 */

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string|int $code = '',
            private string $message = '',
            private mixed $data = ''
        ) {
        }

        public function get_error_code(): string|int
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}
