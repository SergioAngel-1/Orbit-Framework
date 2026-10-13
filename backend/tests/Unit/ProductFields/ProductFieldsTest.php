<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ProductFields;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `hwe-product-fields` (tarea 60): registro declarativo validado, traducibles en el registro de
 * `hwe-content-i18n`, cajas por título, saneado por tipo, guardado con nonce y permisos, y
 * `Product.hweFields(locale)` con caída al idioma por defecto.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductFieldsTest extends TestCase
{
    /** @var array<string,mixed> lo que declara la instancia en este test */
    private array $declared = [];
    /** @var array<int,array<string,string>> */
    private array $meta = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var list<string> */
    private array $locales = ['en'];
    /** @var callable|null */
    private $saveCallback = null;
    /** @var callable|null */
    private $graphqlCallback = null;
    /** @var list<callable> */
    private array $boxCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->declared = [];
        $this->meta = [];
        $this->logged = [];
        $this->locales = ['en'];
        $_POST = [];

        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_textarea_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)), '-'),
            'wp_kses_post' => static fn ($v) => preg_replace('#<script.*?</script>#is', '', (string) $v),
            'esc_url_raw' => static fn ($v) => preg_match('#^(https?://|/)#', (string) $v) ? (string) $v : '',
            'wp_unslash' => static fn ($v) => $v,
            'current_user_can' => static fn () => true,
            'wp_verify_nonce' => static fn ($nonce) => 'bueno' === $nonce ? 1 : false,
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key = '', bool $single = true) => $this->meta[$id][$key] ?? ''
        );
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$id][$key] = (string) $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta[$id][$key]);
            return true;
        });
        // Funciones de `hwe-content-i18n` que usa el módulo (se simulan: aquí no se carga).
        Functions\when('hwe_i18n_locales')->alias(fn (): array => $this->locales);
        Functions\when('hwe_i18n_locale_suffix')->alias(
            static fn (string $l): string => str_replace('-', '_', strtolower(trim($l)))
        );
        Functions\when('hwe_i18n_meta_key')->alias(static fn (string $f, string $s): string => '_hwe_' . $f . '_' . $s);

        Filters\expectApplied('hwe_product_fields')->andReturnUsing(fn ($default) => $this->declared);
        Actions\expectAdded('save_post_product')->once()->whenHappen(function (callable $cb): void {
            $this->saveCallback = $cb;
        });
        Actions\expectAdded('graphql_register_types')->once()->whenHappen(function (callable $cb): void {
            $this->graphqlCallback = $cb;
        });
        Actions\expectAdded('add_meta_boxes_product')->once()->whenHappen(function (callable $cb): void {
            $this->boxCallbacks[] = $cb;
        });

        $this->loadOwnFile('mu-plugins/hwe-product-fields.php');
    }

    // ── Registro ────────────────────────────────────────────────────────────────

    public function testElNucleoNoDeclaraNingunCampo(): void
    {
        $this->assertSame([], hwe_product_fields());
        $this->assertSame([], hwe_product_fields_boxes());
    }

    public function testUnaDeclaracionValidaSeNormaliza(): void
    {
        $this->declared = [
            'dosis' => ['label' => 'Modo de uso', 'type' => 'textarea', 'translatable' => true, 'box' => 'Uso', 'max' => 255],
            'tamano' => ['label' => 'Tamaño', 'type' => 'select', 'options' => ['s' => 'Pequeño', 'l' => 'Grande']],
        ];

        $fields = hwe_product_fields();

        $this->assertSame('textarea', $fields['dosis']['type']);
        $this->assertTrue($fields['dosis']['translatable']);
        $this->assertSame('Uso', $fields['dosis']['box']);
        $this->assertSame(255, $fields['dosis']['max']);
        // Por defecto: tipo `text`, caja «Datos del producto», sin traducción.
        $this->assertSame('Datos del producto', $fields['tamano']['box']);
        $this->assertFalse($fields['tamano']['translatable']);
        $this->assertSame(['s' => 'Pequeño', 'l' => 'Grande'], $fields['tamano']['options']);
    }

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function declaracionesInvalidas(): iterable
    {
        yield 'clave con mayúsculas' => ['Dosis', ['label' => 'X']];
        yield 'clave reservada' => ['description', ['label' => 'X']];
        yield 'espacio de la landing de producto' => ['landing_extra', ['label' => 'X']];
        yield 'sin etiqueta' => ['dosis', ['type' => 'text']];
        yield 'tipo inexistente' => ['dosis', ['label' => 'X', 'type' => 'gigante']];
        yield 'select sin opciones' => ['dosis', ['label' => 'X', 'type' => 'select']];
        yield 'datos que no son una lista' => ['dosis', 'texto'];
    }

    /** @param mixed $def */
    #[\PHPUnit\Framework\Attributes\DataProvider('declaracionesInvalidas')]
    public function testUnaDeclaracionInvalidaSeDescartaYQuedaEnElLog(string $key, mixed $def): void
    {
        $this->declared = [$key => $def, 'valida' => ['label' => 'Válida']];

        $fields = hwe_product_fields();

        $this->assertSame(['valida'], array_keys($fields));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('[hwe] product-fields:', $this->logged[0]);
    }

    public function testSoloLosTextosSonTraducibles(): void
    {
        $this->declared = ['peso' => ['label' => 'Peso', 'type' => 'number', 'translatable' => true]];

        $this->assertFalse(hwe_product_fields()['peso']['translatable']);
        $this->assertCount(1, $this->logged);
    }

    public function testUnaClaveQueChocaConLaTraduccionDeOtraSeDescarta(): void
    {
        $this->declared = [
            'nota' => ['label' => 'Nota', 'type' => 'text', 'translatable' => true],
            'nota_en' => ['label' => 'Nota EN', 'type' => 'text'],
        ];

        $this->assertSame(['nota'], array_keys(hwe_product_fields()));
    }

    public function testLosTraduciblesSeRegistranEnContentI18nConSuEntradaYSinTocarLosDelNucleo(): void
    {
        $this->declared = [
            'dosis' => ['label' => 'Modo de uso', 'type' => 'textarea', 'translatable' => true, 'max' => 255],
            'ficha' => ['label' => 'Ficha', 'type' => 'richtext', 'translatable' => true],
            'marca' => ['label' => 'Marca', 'type' => 'text', 'translatable' => true],
            'peso' => ['label' => 'Peso', 'type' => 'number'],
        ];
        $core = ['product' => ['fields' => ['name' => ['label' => 'Nombre', 'input' => 'text']]]];

        $registry = hwe_product_fields_register_translatable($core);

        $fields = $registry['product']['fields'];
        $this->assertSame(['name', 'dosis', 'ficha', 'marca'], array_keys($fields));
        $this->assertSame('textarea', $fields['dosis']['input']);
        $this->assertSame('html', $fields['ficha']['input']);
        $this->assertSame('text', $fields['marca']['input']);
        $this->assertSame(255, $fields['dosis']['max']);
        $this->assertSame('roto', hwe_product_fields_register_translatable('roto'));
    }

    // ── Saneado ─────────────────────────────────────────────────────────────────

    /** @return iterable<string,array{array<string,mixed>,mixed,string}> */
    public static function saneados(): iterable
    {
        $base = ['options' => [], 'max' => 0];
        yield 'texto sin etiquetas' => [$base + ['type' => 'text'], ' <b>Hola</b> ', 'Hola'];
        yield 'texto con máximo' => [['type' => 'text', 'options' => [], 'max' => 3], 'abcdef', 'abc'];
        yield 'richtext sin script' => [$base + ['type' => 'richtext'], '<p>Hola</p><script>x()</script>', '<p>Hola</p>'];
        yield 'url válida' => [$base + ['type' => 'url'], 'https://example.com/a', 'https://example.com/a'];
        yield 'url javascript' => [$base + ['type' => 'url'], 'javascript:alert(1)', ''];
        yield 'número' => [$base + ['type' => 'number'], ' 12.5 ', '12.5'];
        yield 'número inválido' => [$base + ['type' => 'number'], '12 abc', ''];
        yield 'booleano activo' => [$base + ['type' => 'boolean'], '1', '1'];
        yield 'booleano apagado' => [$base + ['type' => 'boolean'], '0', ''];
        yield 'select válido' => [['type' => 'select', 'options' => ['s' => 'S'], 'max' => 0], 's', 's'];
        yield 'select fuera de la lista' => [['type' => 'select', 'options' => ['s' => 'S'], 'max' => 0], 'x', ''];
        yield 'color' => [$base + ['type' => 'color'], '#AABBCC', '#aabbcc'];
        yield 'color inválido' => [$base + ['type' => 'color'], 'rojo', ''];
        yield 'valor que no es texto' => [$base + ['type' => 'text'], ['a'], ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('saneados')]
    public function testSaneaSegunElTipo(array $field, mixed $raw, string $expected): void
    {
        $this->assertSame($expected, hwe_product_field_sanitize($field, $raw));
    }

    // ── Cajas y guardado ────────────────────────────────────────────────────────

    public function testUnaCajaPorTituloConSusCampos(): void
    {
        $this->declared = [
            'dosis' => ['label' => 'Modo de uso', 'box' => 'Uso'],
            'composicion' => ['label' => 'Composición', 'box' => 'Uso'],
            'peso' => ['label' => 'Peso', 'type' => 'number'],
        ];

        $boxes = hwe_product_fields_boxes();

        $this->assertSame(['hwe_pf_uso', 'hwe_pf_datos-del-producto'], array_keys($boxes));
        $this->assertSame(['dosis', 'composicion'], array_keys($boxes['hwe_pf_uso']['fields']));
        $this->assertSame('Uso', $boxes['hwe_pf_uso']['title']);
    }

    private function post(array $values, string $boxId = 'hwe_pf_uso', string $nonce = 'bueno'): void
    {
        $_POST = ['hwe_pf' => $values, $boxId . '_nonce' => $nonce];
    }

    public function testGuardaLosValoresSaneadosYBorraLosVacios(): void
    {
        $this->declared = [
            'dosis' => ['label' => 'Modo de uso', 'box' => 'Uso', 'type' => 'textarea'],
            'tamano' => ['label' => 'Tamaño', 'box' => 'Uso', 'type' => 'select', 'options' => ['s' => 'S']],
        ];
        $this->meta[7] = ['_hwe_tamano' => 's'];
        $this->post(['dosis' => ' <b>8 gotas</b> ', 'tamano' => '']);

        ($this->saveCallback)(7);

        $this->assertSame('8 gotas', $this->meta[7]['_hwe_dosis']);
        $this->assertArrayNotHasKey('_hwe_tamano', $this->meta[7], 'vaciar un campo borra la meta');
    }

    public function testSinNonceONoTocaNada(): void
    {
        $this->declared = ['dosis' => ['label' => 'Modo de uso', 'box' => 'Uso']];
        $this->meta[7] = ['_hwe_dosis' => 'previo'];

        $this->post(['dosis' => 'nuevo'], 'hwe_pf_uso', 'malo');
        ($this->saveCallback)(7);
        $this->assertSame('previo', $this->meta[7]['_hwe_dosis']);

        $_POST = ['hwe_pf' => ['dosis' => 'nuevo']];
        ($this->saveCallback)(7);
        $this->assertSame('previo', $this->meta[7]['_hwe_dosis']);
    }

    public function testSinPermisoNoGuarda(): void
    {
        $this->declared = ['dosis' => ['label' => 'Modo de uso', 'box' => 'Uso']];
        Functions\when('current_user_can')->justReturn(false);
        $this->post(['dosis' => 'nuevo']);

        ($this->saveCallback)(7);

        $this->assertArrayNotHasKey(7, $this->meta);
    }

    public function testUnaPeticionParcialNoBorraLosCamposDeOtraCaja(): void
    {
        $this->declared = [
            'dosis' => ['label' => 'Modo de uso', 'box' => 'Uso'],
            'peso' => ['label' => 'Peso', 'type' => 'number'],
        ];
        $this->meta[7] = ['_hwe_peso' => '40'];
        // Solo llega la caja «Uso»: «Datos del producto» (con `peso`) no se toca.
        $this->post(['dosis' => 'nuevo']);

        ($this->saveCallback)(7);

        $this->assertSame('nuevo', $this->meta[7]['_hwe_dosis']);
        $this->assertSame('40', $this->meta[7]['_hwe_peso']);
    }

    // ── GraphQL ─────────────────────────────────────────────────────────────────

    public function testHweFieldsResuelveElIdiomaYCaeAlPorDefecto(): void
    {
        $this->declared = [
            'dosis' => ['label' => 'Modo de uso', 'type' => 'textarea', 'translatable' => true],
            'marca' => ['label' => 'Marca', 'translatable' => true],
            'peso' => ['label' => 'Peso', 'type' => 'number'],
            'vacio' => ['label' => 'Vacío'],
        ];
        $this->meta[7] = [
            '_hwe_dosis' => '8 gotas',
            '_hwe_dosis_en' => '8 drops',
            '_hwe_marca' => 'Marca',
            '_hwe_peso' => '40',
            // Un campo no traducible nunca lee una traducción.
            '_hwe_peso_en' => '999',
            // Un idioma que el sitio no declara no abre su meta aunque exista.
            '_hwe_dosis_fr' => '8 gouttes',
        ];

        $es = hwe_product_fields_values(7);
        $en = hwe_product_fields_values(7, 'en');
        $fr = hwe_product_fields_values(7, 'fr');

        $this->assertSame([
            ['key' => 'dosis', 'value' => '8 gotas'],
            ['key' => 'marca', 'value' => 'Marca'],
            ['key' => 'peso', 'value' => '40'],
        ], $es);
        // En inglés: la traducción donde existe y el valor por defecto donde no.
        $this->assertSame(
            [['key' => 'dosis', 'value' => '8 drops'], ['key' => 'marca', 'value' => 'Marca'], ['key' => 'peso', 'value' => '40']],
            $en
        );
        // Un idioma que el sitio no declara no abre la meta del francés.
        $this->assertSame($es, $fr);
    }

    public function testElResolvedorRegistraElCampoEnProduct(): void
    {
        $this->declared = ['dosis' => ['label' => 'Modo de uso']];
        $this->meta[7] = ['_hwe_dosis' => '8 gotas'];
        $types = [];
        $fields = [];
        Functions\when('register_graphql_object_type')->alias(function (string $n, array $c) use (&$types): void {
            $types[$n] = $c;
        });
        Functions\when('register_graphql_field')->alias(function (string $t, string $f, array $c) use (&$fields): void {
            $fields[$t][$f] = $c;
        });

        ($this->graphqlCallback)();

        $this->assertArrayHasKey('HweProductField', $types);
        $resolve = $fields['Product']['hweFields']['resolve'];
        $product = new \stdClass();
        $product->databaseId = 7;
        $this->assertSame([['key' => 'dosis', 'value' => '8 gotas']], $resolve($product, []));
        $this->assertSame([], $resolve(new \stdClass(), []));
    }
}
