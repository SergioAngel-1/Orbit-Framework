<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Instance;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';

/**
 * El detalle de línea del ejemplo llega al registro de `hwe-order-item-details` por el filtro
 * `hwe_order_item_details` y se valida al añadir al carrito.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExampleOrderItemDetailTest extends TestCase
{
    /** @var array<string,callable> */
    private array $hooks = [];

    public function testElDetalleDelEjemploSeDeclaraYSeValidaAlAnadirAlCarrito(): void
    {
        Functions\stubs([
            'error_log' => static fn () => true,
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'esc_html' => static fn ($v) => (string) $v,
            'get_post_field' => static fn () => 'example-product',
        ]);
        Functions\when('add_action')->alias(function (string $hook, callable $cb): bool {
            $this->hooks[$hook] = $cb;
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, callable $cb): bool {
            $this->hooks[$hook] = $cb;
            return true;
        });
        $this->loadOwnFile('mu-plugins/instance/60-example-order-item-detail.php');
        $declare = $this->hooks['hwe_order_item_details'];
        Filters\expectApplied('hwe_order_item_details')->andReturnUsing(
            static fn ($details) => $declare($details)
        );
        $this->loadOwnFile('mu-plugins/hwe-order-item-details.php');

        $this->assertSame(['example_note'], array_keys(hwe_order_item_details()));

        $add = $this->hooks['woocommerce_store_api_add_to_cart_data'];
        $ok = $add(['id' => 5], new \WP_REST_Request(['extensions' => ['example_note' => ' Para regalo ']]));
        $this->assertSame('Para regalo', $ok['cart_item_data']['hwe_item_details']['example_note']['value']);

        $this->expectException(RouteException::class);
        $add(['id' => 5], new \WP_REST_Request(['extensions' => ['example_note' => str_repeat('a', 121)]]));
    }
}
