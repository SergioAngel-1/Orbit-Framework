<?php
/**
 * Dobles mínimos del carrito y de la línea de pedido de WooCommerce, y de la excepción de la
 * Store API que corta un `cart/add-item`. Solo lo que el código propio usa.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions {
    if (!class_exists(RouteException::class)) {
        class RouteException extends \Exception
        {
            /** @var array<string,mixed> */
            private array $data;

            /** @param array<string,mixed> $data */
            public function __construct(
                private string $error_code,
                string $message,
                private int $http_status_code = 400,
                array $data = []
            ) {
                parent::__construct($message, $http_status_code);
                $this->data = $data;
            }

            public function getErrorCode(): string
            {
                return $this->error_code;
            }

            public function getHTTPStatusCode(): int
            {
                return $this->http_status_code;
            }

            /** @return array<string,mixed> */
            public function getAdditionalData(): array
            {
                return $this->data;
            }
        }
    }
}

namespace {
    if (!class_exists('WC_Cart')) {
        /**
         * Doble del carrito. Los totales son propiedades públicas para que cada test fije el
         * escenario (subtotal, envío, impuestos) sin simular el motor de cálculo de WooCommerce;
         * `add_fee()` imita la API real (nombre único, importe y datos fiscales) para poder
         * comprobar el fee negativo del canje.
         */
        class WC_Cart
        {
            public float $cart_contents_total = 0.0;
            public float $cart_contents_tax = 0.0;
            public float $shipping_total = 0.0;
            public float $shipping_tax = 0.0;
            /** @var array<string,object> */
            public array $fees = [];

            /** @param array<string,array<string,mixed>> $items */
            public function __construct(private array $items = [])
            {
            }

            /** @return array<string,array<string,mixed>> */
            public function get_cart(): array
            {
                return $this->items;
            }

            public function add_fee(string $name, float $amount, bool $taxable = false, string $tax_class = ''): void
            {
                $this->fees[$name] = (object) [
                    'name'      => $name,
                    'amount'    => $amount,
                    'taxable'   => $taxable,
                    'tax_class' => $tax_class,
                    'total'     => null,
                    'tax'       => null,
                ];
            }

            /** @return array<string,object> */
            public function get_fees(): array
            {
                return $this->fees;
            }

            public function reset_fees(): void
            {
                $this->fees = [];
            }

            public function get_cart_contents_total(): float
            {
                return $this->cart_contents_total;
            }

            public function get_cart_contents_tax(): float
            {
                return $this->cart_contents_tax;
            }

            public function get_shipping_total(): float
            {
                return $this->shipping_total;
            }

            public function get_shipping_tax(): float
            {
                return $this->shipping_tax;
            }

            public function get_fee_total(): float
            {
                $total = 0.0;
                foreach ($this->fees as $fee) {
                    $total += (float) ($fee->total ?? $fee->amount ?? 0.0);
                }
                return $total;
            }

            public function get_fee_tax(): float
            {
                $total = 0.0;
                foreach ($this->fees as $fee) {
                    $total += (float) ($fee->tax ?? 0.0);
                }
                return $total;
            }

            /**
             * Como `WC_Cart::get_total()`: en contexto `view` (el de por defecto) devuelve el
             * precio formateado en HTML (`wc_price`), no un número; el importe es `'edit'`.
             */
            public function get_total(string $context = 'view'): float|string
            {
                $total = max(
                    0.0,
                    $this->cart_contents_total + $this->cart_contents_tax
                    + $this->shipping_total + $this->shipping_tax
                    + $this->get_fee_total() + $this->get_fee_tax()
                );

                return 'view' === $context
                    ? '<span class="woocommerce-Price-amount amount"><bdi>' . number_format($total, 2, ',', '.') . '&nbsp;&euro;</bdi></span>'
                    : $total;
            }
        }
    }

    if (!class_exists('WC_Order_Item_Product')) {
        class WC_Order_Item_Product
        {
            /** @var array<string,mixed> */
            public array $meta = [];
            public int $id = 0;
            public float $total = 0.0;
            public float $total_tax = 0.0;
            public int $product_id = 0;
            public int $quantity = 1;
            public int $saved = 0;

            public function __construct(
                int $id = 0,
                float $total = 0.0,
                float $total_tax = 0.0,
                int $product_id = 0,
                int $quantity = 1
            ) {
                $this->id         = $id;
                $this->total      = $total;
                $this->total_tax  = $total_tax;
                $this->product_id = $product_id;
                $this->quantity   = $quantity;
            }

            public function add_meta_data(string $key, $value, bool $unique = false): void
            {
                $this->meta[$key] = $value;
            }

            public function update_meta_data(string $key, $value): void
            {
                $this->meta[$key] = $value;
            }

            public function get_meta(string $key, bool $single = true)
            {
                return $this->meta[$key] ?? '';
            }

            public function delete_meta_data(string $key): void
            {
                unset($this->meta[$key]);
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

            public function get_product_id(): int
            {
                return $this->product_id;
            }

            public function get_quantity(): int
            {
                return $this->quantity;
            }

            public function get_total(): float
            {
                return $this->total;
            }

            public function get_total_tax(): float
            {
                return $this->total_tax;
            }
        }
    }

    if (!class_exists('WC_Order_Item_Fee')) {
        /** Doble mínimo de una línea de fee de pedido (la que crea el canje). */
        class WC_Order_Item_Fee
        {
            public int $id = 0;
            public string $name = '';
            public float $total = 0.0;
            public string $tax_status = 'none';

            public function set_name(string $name): void
            {
                $this->name = $name;
            }

            public function get_name(): string
            {
                return $this->name;
            }

            public function set_total(float $total): void
            {
                $this->total = $total;
            }

            public function get_total(): float
            {
                return $this->total;
            }

            public function set_tax_status(string $tax_status): void
            {
                $this->tax_status = $tax_status;
            }

            public function get_tax_status(): string
            {
                return $this->tax_status;
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_type(): string
            {
                return 'fee';
            }
        }
    }
}
