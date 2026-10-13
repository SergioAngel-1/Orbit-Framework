<?php
/**
 * Stub mínimo de `WC_Order`: solo la meta (lectura, escritura y guardado), que es lo que el código
 * propio toca de un pedido en los tests.
 */

declare(strict_types=1);

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        /** @var array<string, string> */
        public array $meta = [];
        public int $saved = 0;

        public function update_meta_data(string $key, $value): void
        {
            $this->meta[$key] = (string) $value;
        }

        public function get_meta(string $key)
        {
            return $this->meta[$key] ?? '';
        }

        public function save_meta_data(): void
        {
            $this->saved++;
        }
    }
}
