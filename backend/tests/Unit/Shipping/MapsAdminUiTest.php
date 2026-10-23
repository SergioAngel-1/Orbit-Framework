<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Admin\MapsNotices;
use HWE\Shipping\Admin\MapsTestType;
use HWE\Tests\TestCase;
use HWE\Tests\Unit\Shipping\Support\WpShippingFake;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Interfaz de wp-admin de mapas: el tipo de campo «Probar conexión» del Control Center (que no
 * guarda nada y no lleva `name`, así que su texto nunca viaja con el formulario) y los avisos de
 * admin (método «Mapas» sin proveedor o clave, clave guardada que no se puede descifrar), solo en
 * las pantallas de WooCommerce y del Control Center y solo para quien gestiona la tienda.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MapsAdminUiTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-ui-mapas-0123456789';

    private WpShippingFake $wp;

    private bool $canManage = true;
    private ?object $screen = null;

    protected function setUp(): void
    {
        parent::setUp();

        define('HWE_SECRETS_KEY', 'clave-de-cifrado-de-pruebas-ui-mapas');

        $this->wp = new WpShippingFake();
        $this->wp->install();

        Functions\stubs([
            '__'                       => static fn (string $texto): string => $texto,
            'esc_html'                 => static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES),
            'esc_attr'                 => static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES),
            'esc_html__'               => static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES),
            'plugin_dir_url'           => static fn (): string => '',
            'register_activation_hook' => static fn (): null => null,
            'is_admin'                 => static fn (): bool => false,
        ]);
        Functions\when('current_user_can')->alias(fn (string $capability): bool => $this->canManage && 'manage_woocommerce' === $capability);
        Functions\when('get_current_screen')->alias(fn (): ?object => $this->screen);

        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');

        $this->wp->options['hwe_config'] = [
            'ecommerce'    => ['country' => 'CO'],
            'integrations' => ['maps_provider' => 'google_maps'],
            'shipping'     => ['distance_method' => 'maps'],
        ];
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => self::KEY]]);

        $this->screen = (object) ['id' => 'woocommerce_page_wc-settings', 'post_type' => ''];
    }

    private function pantalla(string $id, string $postType = ''): void
    {
        $this->screen = (object) ['id' => $id, 'post_type' => $postType];
    }

    private function avisos(): string
    {
        ob_start();
        MapsNotices::render();
        return (string) ob_get_clean();
    }

    /* ----------------------------------------------------- «Probar conexión» */

    public function testElTipoDeCampoSeAnadeAlRegistroYNoPersisteNada(): void
    {
        $tipos = MapsTestType::addType(['text' => 'otro']);

        $this->assertArrayHasKey(MapsTestType::ID, $tipos);
        $tipo = $tipos[MapsTestType::ID];
        $this->assertInstanceOf(FieldType::class, $tipo);
        $this->assertSame(FieldType::EXTERNAL, $tipo->persistence());
        $this->assertSame(FieldType::SKIP, $tipo->sanitize([], 'lo que sea'));
        $this->assertSame('otro', $tipos['text'], 'No pisa los tipos ajenos.');
    }

    public function testElControlPintaUnBotonSinNombreNiScripts(): void
    {
        $html = (new MapsTestType())->render([], 'hwe_config_shipping_distance_connection_test', 'hwe_config[shipping][x]', null, ['shipping', 'x']);

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-hwe-maps-test-button', $html);
        $this->assertStringContainsString('data-hwe-maps-test-address', $html);
        $this->assertStringContainsString('aria-live="polite"', $html, 'El resultado se anuncia a lectores de pantalla.');
        $this->assertDoesNotMatchRegularExpression('/\sname=/', $html, 'Ningún control viaja con el formulario de configuración.');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testElControlEscapaElIdQueLeLlega(): void
    {
        $html = (new MapsTestType())->render([], 'id"><script>', 'n', null, []);

        $this->assertStringNotContainsString('"><script>', $html);
    }

    /* --------------------------------------------------------------- avisos */

    public function testAvisaSiElMetodoEsMapasSinProveedor(): void
    {
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'none';

        $html = $this->avisos();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('línea recta', $html);
    }

    public function testAvisaSiElMetodoEsMapasSinClave(): void
    {
        $this->wp->options['hwe_config_secrets'] = [];

        $this->assertStringContainsString('línea recta', $this->avisos());
    }

    public function testNoAvisaConMetodoEnLineaRectaOConProveedorYClaveValidos(): void
    {
        $this->assertSame('', $this->avisos());

        $this->wp->options['hwe_config']['shipping']['distance_method'] = 'straight_line';
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'none';
        $this->assertSame('', $this->avisos());
    }

    public function testAvisaDeLaClaveGuardadaQueNoSeDescifra(): void
    {
        $this->wp->options['hwe_config']['shipping']['distance_method'] = 'straight_line';
        $this->wp->options['hwe_config_secrets'] = [
            'integrations' => ['maps_api_key' => 'v2:' . base64_encode(str_repeat('x', 40))],
        ];

        $this->assertTrue(MapsNotices::keyUndecryptable());
        $this->assertStringContainsString('no se puede descifrar', $this->avisos());
    }

    public function testUnaClaveQueSiDescifraNoProvocaAviso(): void
    {
        $this->assertFalse(MapsNotices::keyUndecryptable());
    }

    public function testElAvisoNoSaleDeLaClaveNiDeSuValor(): void
    {
        $this->wp->options['hwe_config_secrets'] = [
            'integrations' => ['maps_api_key' => 'v2:' . base64_encode(str_repeat('x', 40))],
        ];

        $this->assertStringNotContainsString(self::KEY, $this->avisos());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pantallasDondeAvisa')]
    public function testSoloAvisaEnLasPantallasDeWooCommerceYDelControlCenter(string $id, string $postType, bool $esperado): void
    {
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'none';
        $this->pantalla($id, $postType);

        $this->assertSame($esperado, '' !== $this->avisos());
    }

    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function pantallasDondeAvisa(): array
    {
        return [
            'ajustes de WooCommerce'          => ['woocommerce_page_wc-settings', '', true],
            'pedidos de WooCommerce'          => ['woocommerce_page_wc-orders', '', true],
            'Control Center'                  => ['toplevel_page_hwe-config', '', true],
            'editor de ciudades'              => ['hwe_shipping_rate', 'hwe_shipping_rate', true],
            'entradas del blog'               => ['post', 'post', false],
            'otras pantallas de WordPress'    => ['plugins', '', false],
        ];
    }

    public function testNoAvisaAQuienNoGestionaLaTienda(): void
    {
        $this->canManage = false;
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'none';

        $this->assertSame('', $this->avisos());
    }
}
