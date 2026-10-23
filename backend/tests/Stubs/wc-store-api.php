<?php
/**
 * Stub mínimo de los esquemas de la Store API de WooCommerce: solo el identificador del
 * endpoint del carrito, que es lo que el código propio lee al registrar su extensión.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Schemas\V1;

if (!class_exists(CartSchema::class)) {
    class CartSchema
    {
        public const IDENTIFIER = 'cart';
    }
}
