<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\Admin\CityErrors;
use HWE\Shipping\Admin\LegacyNotice;
use HWE\Shipping\AdminColumns;
use HWE\Shipping\MetaBoxes;
use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Save;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Editor de ciudad (`MetaBoxes::renderRate`), columnas del listado y avisos de admin. Se comprueba
 * el HTML que sale: que no hay JS inline, que las opciones guardadas llevan su id y que el texto de
 * la base de datos sale escapado.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CityAdminTest extends TestCase
{
    /** @var array<int,array<string,string>> */
    private array $meta = [];
    /** @var list<string> */
    private array $logged = [];
    private ?object $screen = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            '__' => static fn ($v) => $v,
            'esc_html__' => static fn ($v) => htmlspecialchars((string) $v),
            'esc_html_e' => static function ($v): void {
                echo htmlspecialchars((string) $v);
            },
            'esc_html' => static fn ($v) => htmlspecialchars((string) $v),
            'esc_attr' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_attr__' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_url' => static fn ($v) => (string) $v,
            'absint' => static fn ($v) => abs((int) $v),
            'wp_nonce_field' => static fn () => '',
            'plugin_dir_url' => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin' => static fn () => false,
            'remove_accents' => static fn (string $s) => $s,
        ]);
        Functions\when('selected')->alias(static function ($current, $expected): void {
            if ((string) $current === (string) $expected) {
                echo " selected='selected'";
            }
        });
        Functions\when('checked')->alias(static function ($current, $expected = true): void {
            if ($current === $expected || ($current && $expected === true)) {
                echo " checked='checked'";
            }
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta[$key] ?? '');
        Functions\when('get_current_screen')->alias(fn () => $this->screen);
        Functions\when('get_the_title')->alias(static fn ($id): string => 'Título ' . $id);
        Functions\when('get_edit_post_link')->alias(static fn (int $id): string => '/wp-admin/post.php?post=' . $id . '&action=edit');
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    private function render(int $postId = 11): string
    {
        ob_start();
        MetaBoxes::renderRate($this->post($postId));
        return (string) ob_get_clean();
    }

    private function post(int $id): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        return $post;
    }

    private function fixedOptionsJson(string $id, string $name): string
    {
        return json_encode([[
            'id' => $id,
            'name' => $name,
            'free_eligible' => true,
            'amount' => 8.0,
            'price_per_km' => null,
            'min_cost' => 0.0,
            'max_cost' => 0.0,
        ]]);
    }

    // --- editor ----------------------------------------------------------------------------------

    public function testElEditorNoLlevaJavascriptInline(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString(' onclick=', $html);
        $this->assertStringNotContainsString(' onchange=', $html);
    }

    public function testElTipoGuardadoQuedaSeleccionadoYLaOpcionLlevaSuId(): void
    {
        $this->meta = [
            Save::ERRORS_META => '',
            '_hwe_shipping_city_type' => 'fixed',
            '_hwe_shipping_city_options' => $this->fixedOptionsJson('abcd1234', 'Estándar'),
        ];

        $html = $this->render();

        $this->assertMatchesRegularExpression('/value="fixed"\s+selected=\'selected\'/', $html);
        $this->assertStringContainsString('name="hwe_shipping_city_options[0][id]" value="abcd1234"', $html);
        $this->assertStringContainsString('name="hwe_shipping_city_options[0][name]" value="Estándar"', $html);
    }

    public function testLaPlantillaDelRepetidorUsaUnIndiceQueJSSustituye(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<template id="hwe-shipping-option-template">', $html);
        $this->assertStringContainsString('hwe_shipping_city_options[__INDEX__][name]', $html);
        $this->assertStringContainsString('data-next-index="1"', $html, 'una ciudad nueva empieza con una fila vacía');
    }

    public function testLaReferenciaSoloSeMuestraParaLasCiudadesPorDistancia(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('class="hwe-shipping-origin" data-hwe-for="distance"', $html);
        $this->assertStringContainsString('data-hwe-for="fixed"', $html);
        $this->assertStringContainsString('name="hwe_shipping_city_options[0][price_per_km]"', $html);
    }

    public function testUnTextoDeLaBaseDeDatosSaleEscapado(): void
    {
        $this->meta = [
            Cities::META_ORIGIN_ADDRESS => '<img src=x onerror=alert(1)>',
        ];

        $html = $this->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function testUnaListaDeOpcionesRotaSeRegistraYDejaUnaFilaVacia(): void
    {
        $this->meta = ['_hwe_shipping_city_options' => '{no es json'];

        $html = $this->render();

        $this->assertStringContainsString('name="hwe_shipping_city_options[0][name]" value=""', $html);
        $this->assertStringContainsString('opciones ilegibles', $this->logged[0] ?? '');
    }

    // --- columnas del listado --------------------------------------------------------------------

    public function testLaColumnaMuestraElTipoOSinConfigurarYElNumeroDeOpciones(): void
    {
        $this->meta = [
            '_hwe_shipping_city_type' => 'distance',
            '_hwe_shipping_city_options' => json_encode([['id' => 'a'], ['id' => 'b']]),
        ];
        ob_start();
        AdminColumns::renderRate('hwe_type', 11);
        AdminColumns::renderRate('hwe_options', 11);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Por distancia', $html);
        $this->assertStringContainsString('2', $html);

        $this->meta = [];
        ob_start();
        AdminColumns::renderRate('hwe_type', 12);
        $legacy = (string) ob_get_clean();
        $this->assertStringContainsString('Sin configurar', $legacy);
    }

    // --- avisos ----------------------------------------------------------------------------------

    public function testElMotivoDelRechazoSeMuestraEscapadoEnElEditor(): void
    {
        $this->screen = (object) ['post_type' => 'hwe_shipping_rate', 'base' => 'post'];
        $_GET['post'] = '11';
        $this->meta = [Save::ERRORS_META => json_encode(['El tipo no es válido.', '<b>x</b>'])];

        ob_start();
        CityErrors::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('no se ha publicado', $html);
        $this->assertStringContainsString('El tipo no es válido.', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        unset($_GET['post']);
    }

    public function testSinErroresNoHayAvisoDeRechazo(): void
    {
        $this->screen = (object) ['post_type' => 'hwe_shipping_rate', 'base' => 'post'];
        $_GET['post'] = '11';
        $this->meta = [];

        ob_start();
        CityErrors::render();
        $this->assertSame('', (string) ob_get_clean());
        unset($_GET['post']);
    }

    public function testElAvisoDelModeloAnteriorEnlazaCadaCiudadSinTipo(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('get_posts')->justReturn([$this->legacyPost(21)]);

        ob_start();
        LegacyNotice::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('modelo de tarifa anterior', $html);
        $this->assertStringContainsString('href="/wp-admin/post.php?post=21&action=edit"', $html);
    }

    public function testElAvisoDelModeloAnteriorNoSeVeSinPermisoDeWooCommerce(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        LegacyNotice::render();
        $this->assertSame('', (string) ob_get_clean());
    }

    private function legacyPost(int $id): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_title = 'Pamplona';
        return $post;
    }
}
