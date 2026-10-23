<?php
/**
 * Stub mínimo de `WC_Order`: la meta (lectura, escritura y guardado) que ya usaban otros tests y,
 * desde la tarea de ganancias del módulo `loyalty`, lo justo para leer un pedido real: id, estado,
 * si está pagado, medio de pago, cliente, líneas, reembolsos y notas privadas.
 *
 * Los métodos nuevos son aditivos: `update_meta_data()` acepta cualquier valor (antes convertía a
 * texto) para que una meta estructurada, como el estado de las ganancias, se pueda probar tal cual.
 */

declare(strict_types=1);

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        /** @var array<string, mixed> */
        public array $meta = [];
        public int $saved = 0;
        public int $id = 0;
        public int $customer_id = 0;
        public string $status = 'pending';
        public bool $paid = false;
        public string $payment_method = '';
        /** @var list<object> */
        public array $line_items = [];
        /** @var list<object> */
        public array $fee_items = [];
        public float $total = 0.0;
        public float $total_refunded = 0.0;
        public float $shipping_total = 0.0;
        public float $shipping_tax = 0.0;
        /** @var list<array{note:string,is_customer_note:bool}> */
        public array $notes = [];
        private int $nextFeeId = 1;

        public function update_meta_data(string $key, $value): void
        {
            $this->meta[$key] = $value;
        }

        public function delete_meta_data(string $key): void
        {
            unset($this->meta[$key]);
        }

        public function get_meta(string $key)
        {
            return $this->meta[$key] ?? '';
        }

        public function save_meta_data(): void
        {
            $this->saved++;
        }

        public function save(): int
        {
            $this->saved++;
            return $this->id;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_customer_id(): int
        {
            return $this->customer_id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function is_paid(): bool
        {
            return $this->paid;
        }

        public function get_payment_method(): string
        {
            return $this->payment_method;
        }

        public function get_total(): float
        {
            return $this->total;
        }

        public function get_total_refunded(): float
        {
            return $this->total_refunded;
        }

        public function get_shipping_total(): float
        {
            return $this->shipping_total;
        }

        public function get_shipping_tax(): float
        {
            return $this->shipping_tax;
        }

        /** @return list<object> */
        public function get_fees(): array
        {
            return $this->fee_items;
        }

        public function add_item($item): void
        {
            if (is_object($item) && method_exists($item, 'get_type') && 'fee' === $item->get_type()) {
                if (method_exists($item, 'get_id') && 0 === (int) $item->get_id()) {
                    $item->id = $this->nextFeeId++;
                }
                $this->fee_items[] = $item;
            }
        }

        public function remove_item($itemId): void
        {
            $this->fee_items = array_values(array_filter(
                $this->fee_items,
                static fn (object $item): bool => (int) $item->get_id() !== (int) $itemId
            ));
        }

        /**
         * Cálculo mínimo de totales: líneas (con sus impuestos) + fees + envío (con impuesto),
         * sin bajar de 0. El fee negativo del canje entra por `total`, como en WooCommerce.
         */
        public function calculate_totals(bool $andTaxes = true): float
        {
            $items = 0.0;
            foreach ($this->line_items as $item) {
                $items += (float) $item->get_total() + (float) $item->get_total_tax();
            }
            $fees = 0.0;
            foreach ($this->fee_items as $item) {
                $fees += (float) $item->get_total();
            }

            $total = max(0.0, $items + $fees + $this->shipping_total + $this->shipping_tax);
            $this->total = round($total, 2);
            $this->save();

            return $this->total;
        }

        /**
         * @param string|list<string> $types
         * @return list<object>
         */
        public function get_items($types = 'line_item'): array
        {
            $types = is_array($types) ? $types : [$types];
            $items = [];
            if (in_array('line_item', $types, true)) {
                $items = array_merge($items, $this->line_items);
            }
            if (in_array('fee', $types, true)) {
                $items = array_merge($items, $this->fee_items);
            }
            return $items;
        }

        public function add_order_note($note, $is_customer_note = 0, $added_by_user = false): int
        {
            $this->notes[] = [
                'note'             => (string) $note,
                'is_customer_note' => (bool) $is_customer_note,
            ];
            return count($this->notes);
        }
    }
}
