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
            public function __construct(private string $error_code, string $message, int $http_status_code = 400)
            {
                parent::__construct($message, $http_status_code);
            }

            public function getErrorCode(): string
            {
                return $this->error_code;
            }
        }
    }
}

namespace {
    if (!class_exists('WC_Cart')) {
        class WC_Cart
        {
            /** @param array<string,array<string,mixed>> $items */
            public function __construct(private array $items = [])
            {
            }

            /** @return array<string,array<string,mixed>> */
            public function get_cart(): array
            {
                return $this->items;
            }
        }
    }

    if (!class_exists('WC_Order_Item_Product')) {
        class WC_Order_Item_Product
        {
            /** @var array<string,mixed> */
            public array $meta = [];

            public function add_meta_data(string $key, $value, bool $unique = false): void
            {
                $this->meta[$key] = $value;
            }
        }
    }
}
