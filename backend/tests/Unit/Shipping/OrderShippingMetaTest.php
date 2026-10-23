<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\Admin\OrderShippingMeta;
use HWE\Tests\TestCase;

require_once __DIR__ . '/../../Stubs/wc-shipping.php';

/**
 * Metas de la línea de envío en la ficha del pedido de wp-admin: las del contrato con el frontend se
 * ocultan como claves en crudo y todo se pinta legible, escapado y con claves estables (la etiqueta
 * se traduce al pintar, no al guardar).
 */
final class OrderShippingMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            '__'         => static fn (string $text): string => $text,
            'esc_html'   => static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES),
            'esc_html__' => static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES),
            'esc_url'    => static fn (string $url): string => htmlspecialchars($url, ENT_QUOTES),
        ]);
        require_once dirname(__DIR__, 3) . '/wp-content/plugins/hwe-shipping/includes/Admin/OrderShippingMeta.php';
    }

    private function render(array $meta): string
    {
        ob_start();
        OrderShippingMeta::render(1, new \WC_Order_Item_Shipping($meta));
        return (string) ob_get_clean();
    }

    public function testOcultaLasMetasDelContratoSinPisarLasAjenas(): void
    {
        $hidden = OrderShippingMeta::hide(['_reduced_stock', 'hwe_option_id']);

        $this->assertSame(['_reduced_stock', 'hwe_option_id', 'hwe_city_type', 'hwe_distance_km', 'hwe_fallback'], $hidden);
        $this->assertSame(OrderShippingMeta::PUBLIC_KEYS, OrderShippingMeta::hide('roto'));
    }

    public function testLasMetasDeOperacionSonProtegidasYLasDelContratoNo(): void
    {
        foreach ([OrderShippingMeta::PROVIDER, OrderShippingMeta::FALLBACK_REASON, OrderShippingMeta::DURATION_MIN, OrderShippingMeta::DELIVERY_POINT, OrderShippingMeta::FREE_REASON] as $key) {
            $this->assertStringStartsWith('_', $key, 'la Store API omite las metas con `_`');
        }
        foreach (OrderShippingMeta::PUBLIC_KEYS as $key) {
            $this->assertStringStartsNotWith('_', $key);
        }
    }

    public function testPintaUnaLineaPorDistanciaConRespaldoPuntoYEnlace(): void
    {
        $html = $this->render([
            'hwe_city_type'          => 'distance',
            'hwe_distance_km'        => '1.6',
            'hwe_fallback'           => 'yes',
            '_hwe_fallback_reason'   => 'provider_unauthorized',
            '_hwe_distance_provider' => 'straight_line',
            '_hwe_duration_min'      => '3',
            '_hwe_delivery_point'    => '37.399000,-5.990000',
        ]);

        $this->assertStringContainsString('Por distancia', $html);
        $this->assertStringContainsString('1.6 km', $html);
        $this->assertStringContainsString('provider_unauthorized', $html);
        $this->assertStringContainsString('3 min', $html);
        $this->assertStringContainsString('37.399000, -5.990000', $html);
        $this->assertStringContainsString('href="https://www.openstreetmap.org/?mlat=37.399000&amp;mlon=-5.990000', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testEscapaLosValoresYDescartaUnPuntoInvalido(): void
    {
        $html = $this->render([
            'hwe_city_type'             => '<script>x</script>',
            '_hwe_delivery_point'       => '999,abc',
            '_hwe_free_shipping_reason' => 'Mínimo <b>alcanzado</b>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('openstreetmap', $html);
        $this->assertStringContainsString('Envío gratis', $html);
    }

    public function testNoPintaNadaEnLineasAjenasOSinMetas(): void
    {
        $this->assertSame('', $this->render([]));

        ob_start();
        OrderShippingMeta::render(1, new \stdClass());
        $this->assertSame('', (string) ob_get_clean());
    }
}
