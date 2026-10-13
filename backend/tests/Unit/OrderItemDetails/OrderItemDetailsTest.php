<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\OrderItemDetails;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-cart.php';

/**
 * `hwe-order-item-details` (tarea 61): registro declarativo validado, validación en el servidor al
 * añadir al carrito (falla cerrado), línea sin dato obligatorio que no se paga, y meta VISIBLE de
 * la línea de pedido (la que llega a los correos).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderItemDetailsTest extends TestCase
{
    /** @var array<string,mixed> lo que declara la instancia en este test */
    private array $declared = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,callable> filtros y acciones que el archivo registra */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->declared = [];
        $this->logged = [];
        $this->hooks = [];

        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'esc_html' => static fn ($v) => (string) $v,
            'wp_json_encode' => static fn ($v) => json_encode($v),
            'get_post_field' => static fn (string $f, int $id) => [10 => 'vela-de-cera', 20 => 'camiseta'][$id] ?? '',
        ]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, callable $cb): bool {
            $this->hooks[$hook] = $cb;
            return true;
        });
        Functions\when('add_action')->alias(function (string $hook, callable $cb): bool {
            $this->hooks[$hook] = $cb;
            return true;
        });
        Filters\expectApplied('hwe_order_item_details')->andReturnUsing(fn ($default) => $this->declared);

        $this->loadOwnFile('mu-plugins/hwe-order-item-details.php');
    }

    /** Detalle válido de ejemplo: aplica al producto 10 (slug `vela-de-cera`). */
    private function intencion(array $override = []): array
    {
        return $override + [
            'label' => 'Intención',
            'validate' => static fn ($raw) => trim((string) $raw),
            'applies_to' => ['vela-de-cera'],
        ];
    }

    private function addToCart(int $productId, mixed $extensions): array
    {
        $request = new \WP_REST_Request(['extensions' => $extensions]);
        return ($this->hooks['woocommerce_store_api_add_to_cart_data'])(['id' => $productId], $request);
    }

    // ── Registro ────────────────────────────────────────────────────────────────

    public function testElNucleoNoDeclaraNingunDetalle(): void
    {
        $this->assertSame([], hwe_order_item_details());
        $this->assertSame([], $this->addToCart(10, ['intencion' => 'paz']) ['cart_item_data'] ?? []);
    }

    public function testUnaDeclaracionValidaSeNormaliza(): void
    {
        $this->declared = ['intencion' => $this->intencion(['meta_key' => '_intencion', 'required' => false])];

        $d = hwe_order_item_details()['intencion'];

        $this->assertSame('Intención', $d['label']);
        $this->assertFalse($d['required']);
        // Una meta que empieza por `_` es oculta: la visible se normaliza sin él.
        $this->assertSame('intencion', $d['meta_key']);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function declaracionesInvalidas(): iterable
    {
        yield 'clave con mayúsculas' => ['Intencion', ['label' => 'X', 'validate' => 'trim', 'applies_to' => ['a']]];
        yield 'sin etiqueta' => ['intencion', ['validate' => 'trim', 'applies_to' => ['a']]];
        yield 'sin validador' => ['intencion', ['label' => 'X', 'applies_to' => ['a']]];
        yield 'validador no callable' => ['intencion', ['label' => 'X', 'validate' => 'no_existe_esta_funcion', 'applies_to' => ['a']]];
        yield 'sin applies_to' => ['intencion', ['label' => 'X', 'validate' => 'trim']];
        yield 'datos que no son una lista' => ['intencion', 'texto'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('declaracionesInvalidas')]
    public function testUnaDeclaracionInvalidaSeDescartaYQuedaEnElLog(string $key, mixed $def): void
    {
        $this->declared = [$key => $def, 'valida' => $this->intencion()];

        $this->assertSame(['valida'], array_keys(hwe_order_item_details()));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('[hwe] order-item-details:', $this->logged[0]);
    }

    // ── Alta al carrito ─────────────────────────────────────────────────────────

    public function testElDetalleValidadoViajaEnElDatoDelItem(): void
    {
        $this->declared = ['intencion' => $this->intencion()];

        $data = $this->addToCart(10, ['intencion' => '  Paz interior ']);

        $this->assertSame(
            ['value' => 'Paz interior', 'data' => null],
            $data['cart_item_data']['hwe_item_details']['intencion']
        );
    }

    public function testUnProductoAjenoNoRecibeNiExigeElDetalle(): void
    {
        $this->declared = ['intencion' => $this->intencion()];

        $data = $this->addToCart(20, []);

        $this->assertArrayNotHasKey('cart_item_data', $data);
    }

    public function testElDetalleObligatorioAusenteCortaElAlta(): void
    {
        $this->declared = ['intencion' => $this->intencion()];

        try {
            $this->addToCart(10, []);
            $this->fail('Debía lanzar RouteException.');
        } catch (RouteException $e) {
            $this->assertSame('hwe_item_detail_missing', $e->getErrorCode());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function testElDetalleOpcionalAusenteNoCortaElAlta(): void
    {
        $this->declared = ['intencion' => $this->intencion(['required' => false])];

        $data = $this->addToCart(10, []);

        $this->assertArrayNotHasKey('cart_item_data', $data);
    }

    public function testUnValorQueElValidadorRechazaCortaElAltaConSuMensaje(): void
    {
        $this->declared = ['intencion' => $this->intencion([
            'validate' => static fn () => new \WP_Error('intencion_invalida', 'Intención no válida.'),
        ])];

        try {
            $this->addToCart(10, ['intencion' => 'x']);
            $this->fail('Debía lanzar RouteException.');
        } catch (RouteException $e) {
            $this->assertSame('intencion_invalida', $e->getErrorCode());
            $this->assertSame('Intención no válida.', $e->getMessage());
        }
    }

    public function testUnValidadorQueLanzaExcepcionFallaCerrado(): void
    {
        $this->declared = ['intencion' => $this->intencion([
            'validate' => static function () {
                throw new \RuntimeException('boom');
            },
        ])];

        $this->expectException(RouteException::class);
        try {
            $this->addToCart(10, ['intencion' => 'x']);
        } finally {
            $this->assertStringContainsString('boom', implode("\n", $this->logged));
        }
    }

    public function testUnValidadorQueDevuelveVacioCortaElAlta(): void
    {
        $this->declared = ['intencion' => $this->intencion(['validate' => static fn () => '  '])];

        $this->expectException(RouteException::class);
        $this->addToCart(10, ['intencion' => 'x']);
    }

    public function testElValidadorRecibeElProductoYPuedeDevolverDatoMaquina(): void
    {
        $seen = null;
        $this->declared = ['intencion' => $this->intencion([
            'validate' => static function ($raw, $productId) use (&$seen) {
                $seen = $productId;
                return ['value' => 'Paz', 'data' => ['id' => 7]];
            },
        ])];

        $data = $this->addToCart(10, ['intencion' => 'paz']);

        $this->assertSame(10, $seen);
        $this->assertSame(['id' => 7], $data['cart_item_data']['hwe_item_details']['intencion']['data']);
    }

    public function testElValorSeAcotaAlTope(): void
    {
        $this->declared = ['intencion' => $this->intencion()];

        $data = $this->addToCart(10, ['intencion' => str_repeat('a', 5000)]);

        $this->assertSame(1000, mb_strlen($data['cart_item_data']['hwe_item_details']['intencion']['value']));
    }

    public function testApplyToPuedeSerUnCallable(): void
    {
        $this->declared = ['intencion' => $this->intencion(['applies_to' => static fn (int $id) => 20 === $id])];

        $this->assertArrayHasKey('cart_item_data', $this->addToCart(20, ['intencion' => 'x']));
        $this->assertArrayNotHasKey('cart_item_data', $this->addToCart(10, []));
    }

    public function testUnProductoSinSlugNuncaCoincideConUnaListaConCadenaVacia(): void
    {
        $this->declared = ['intencion' => $this->intencion(['applies_to' => ['', 'vela-de-cera']])];

        // El 99 no existe: `get_post_field` devuelve ''.
        $this->assertArrayNotHasKey('cart_item_data', $this->addToCart(99, []));
        $this->assertArrayNotHasKey('cart_item_data', $this->addToCart(0, []));
    }

    // ── Carrito, pago y pedido ──────────────────────────────────────────────────

    public function testItemDataLlevaLaEtiquetaVisibleYElDatoMaquinaOculto(): void
    {
        $this->declared = ['intencion' => $this->intencion()];
        $cartItem = ['hwe_item_details' => ['intencion' => ['value' => 'Paz', 'data' => ['id' => 7]]]];

        $out = ($this->hooks['woocommerce_get_item_data'])([], $cartItem);

        $this->assertSame(['name' => 'Intención', 'value' => 'Paz'], $out[0]);
        $this->assertSame(
            ['name' => 'hwe_item_intencion', 'value' => '{"id":7}', 'hidden' => true],
            $out[1]
        );
    }

    public function testUnaLineaAplicableSinElDatoNoSePaga(): void
    {
        $this->declared = ['intencion' => $this->intencion()];
        $errors = new \WP_Error();
        $cart = new \WC_Cart(['k' => ['product_id' => 10]]);

        ($this->hooks['woocommerce_store_api_cart_errors'])($errors, $cart);

        $this->assertSame(['hwe_item_detail_missing'], $errors->get_error_codes());
    }

    public function testUnaLineaConElDatoOUnProductoAjenoNoDaError(): void
    {
        $this->declared = ['intencion' => $this->intencion()];
        $errors = new \WP_Error();
        $cart = new \WC_Cart([
            'a' => ['product_id' => 10, 'hwe_item_details' => ['intencion' => ['value' => 'Paz']]],
            'b' => ['product_id' => 20],
        ]);

        ($this->hooks['woocommerce_store_api_cart_errors'])($errors, $cart);

        $this->assertSame([], $errors->get_error_codes());
    }

    public function testLaLineaDelPedidoRecibeLaMetaVisibleYLaOculta(): void
    {
        $this->declared = ['intencion' => $this->intencion(['meta_key' => 'Intención de uso'])];
        $item = new \WC_Order_Item_Product();
        $values = ['hwe_item_details' => ['intencion' => ['value' => 'Paz', 'data' => ['id' => 7]]]];

        ($this->hooks['woocommerce_checkout_create_order_line_item'])($item, 'k', $values);

        $this->assertSame('Paz', $item->meta['Intención de uso']);
        $this->assertSame(['id' => 7], $item->meta['_hwe_item_intencion']);
    }

    public function testSinDatoMaquinaNoSeEscribeLaMetaOculta(): void
    {
        $this->declared = ['intencion' => $this->intencion()];
        $item = new \WC_Order_Item_Product();

        ($this->hooks['woocommerce_checkout_create_order_line_item'])(
            $item,
            'k',
            ['hwe_item_details' => ['intencion' => ['value' => 'Paz', 'data' => null]]]
        );

        $this->assertSame(['intencion' => 'Paz'], $item->meta);
    }

    public function testUnaLineaSinDetallesNoSeTocaNiSeInventaMeta(): void
    {
        $this->declared = ['intencion' => $this->intencion()];
        $item = new \WC_Order_Item_Product();

        ($this->hooks['woocommerce_checkout_create_order_line_item'])($item, 'k', ['product_id' => 20]);

        $this->assertSame([], $item->meta);
    }

    public function testLaEtiquetaDeLaMetaVisibleSeDeclaraYLaAjenaNoSeTocan(): void
    {
        $this->declared = ['intencion' => $this->intencion()];
        $filter = $this->hooks['woocommerce_order_item_display_meta_key'];

        $this->assertSame('Intención', $filter('intencion', (object) ['key' => 'intencion']));
        $this->assertSame('Otra', $filter('Otra', (object) ['key' => 'otra']));
    }
}
