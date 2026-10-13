<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminSave;
use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\ControlCenter\FieldTypes\ProductFieldsListType;
use HWE\ControlCenter\FieldTypes\Registry;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Producto falso: guarda las metas en memoria y cuenta los `save()`. */
class FakeProduct28 {

    /** @var array<string,string> */
    public array $meta;
    public int $saves = 0;

    /** @param array<string,string> $meta */
    public function __construct(array $meta = []) {
        $this->meta = $meta;
    }

    public function get_meta(string $key, bool $single = true): string {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, string $value): void {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data(string $key): void {
        unset($this->meta[$key]);
    }

    public function save(): void {
        $this->saves++;
    }
}

/**
 * Los tipos de producto (`product_multiselect`, `product_fields_list`).
 *
 * `product_multiselect` elige productos de unas opciones que la instancia resuelve y
 * persiste `"slug-1 | slug-2"`. `product_fields_list` edita metas de producto por ítem: el
 * nodo declara qué claves meta escribe, los inputs viajan dentro del árbol `hwe_config`
 * (nombres derivados del path, sin claves POST con nombre de dominio) y el propio tipo
 * las guarda al recibir `hwe_control_center_saved` con `$product->save()`.
 */
final class ProductTypesTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];
    /** @var array<int,FakeProduct28> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $v)), '-'),
            'esc_url_raw' => static fn ($v) => (string) $v,
            'esc_url' => static fn ($v) => (string) $v,
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            'esc_textarea' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        // `wc_get_product` NO se simula aquí a propósito: no es de WordPress sino de
        // WooCommerce, y Brain Monkey no la define. Cada test que necesita Woo la declara
        // con `withWoo()`; el test sin Woo comprueba que no hacer nada (y registrarlo).
    }

    private function withWoo(): void {
        // Marcador de «WooCommerce activo» para `ProductFieldsListType::saveSubmitted()`, que
        // lo comprueba con `class_exists` (a salvo en tests y en producción).
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        Functions\when('wc_get_product')->alias(fn (int $id): ?FakeProduct28 => $this->products[$id] ?? null);
    }

    private function withProduct(string $slug, int $id, array $meta = []): FakeProduct28 {
        $product = new FakeProduct28($meta);
        $this->products[$id] = $product;
        return $product;
    }

    /** @return callable(string):int */
    private function resolver(array $map): callable {
        return static fn (string $slug): int => $map[$slug] ?? 0;
    }

    private function metaFields(): array {
        return [
            'badge' => ['label' => 'Distintivo', 'type' => 'text', 'meta_key' => '_hwe_badge'],
            'note' => ['label' => 'Nota', 'type' => 'textarea', 'meta_key' => '_hwe_note'],
        ];
    }

    // ── product_multiselect ─────────────────────────────────────────────────

    public function testMultiselectRendersACheckboxPerOption(): void {
        $html = Registry::get('product_multiselect')->render(
            ['options' => ['a' => 'A', 'b' => 'B']],
            'id', 'hwe_config[g][m]', 'a | b', ['g', 'm']
        );

        $this->assertStringContainsString('name="hwe_config[g][m][]"', $html);
        $this->assertSame(2, substr_count($html, 'type="checkbox"'));
        $this->assertSame(2, substr_count($html, 'checked'), 'Los dos slugs guardados van marcados.');
    }

    public function testMultiselectWithoutOptionsExplainsItself(): void {
        $html = Registry::get('product_multiselect')->render([], 'id', 'name', null, []);

        $this->assertStringNotContainsString('checkbox', $html);
        $this->assertStringContainsString('No hay productos', $html);
    }

    public function testMultiselectSanitizeAcceptsArrayAndPipeString(): void {
        $type  = Registry::get('product_multiselect');
        $field = ['options' => ['a' => 'A', 'b' => 'B']];

        $this->assertSame('a | b', $type->sanitize($field, ['a', 'b', 'fantasma']));
        $this->assertSame('a | b', $type->sanitize($field, 'a | b'));
        $this->assertSame('', $type->sanitize($field, null));
        $this->assertSame('config', $type->persistence());
    }

    // ── product_fields_list: render ─────────────────────────────────────────

    public function testFieldsListRendersItemEditorsWithPathDerivedNames(): void {
        $html = Registry::get('product_fields_list')->render([
            'items' => ['camiseta' => 'Camiseta'],
            'meta_fields' => $this->metaFields(),
            'item_meta_values' => ['camiseta' => ['badge' => 'Nuevo']],
            'item_links' => ['camiseta' => 'https://tienda.example/wp-admin/edit/7'],
        ], 'id', 'hwe_config[instance][tienda][badges]', null, ['instance', 'tienda', 'badges']);

        $this->assertStringContainsString('Camiseta', $html);
        $this->assertStringContainsString('name="hwe_config[instance][tienda][badges][camiseta][badge]"', $html);
        $this->assertStringContainsString('value="Nuevo"', $html);
        $this->assertStringContainsString('https://tienda.example/wp-admin/edit/7', $html);
        $this->assertStringNotContainsString('hwe_example', $html, 'Ninguna clave POST con nombre de dominio.');
    }

    public function testFieldsListSanitizeSkipsPersistenceHere(): void {
        $type = Registry::get('product_fields_list');

        $this->assertSame('external', $type->persistence());
        $this->assertSame(FieldType::SKIP, $type->sanitize([], ['camiseta' => ['badge' => 'x']]));
    }

    public function testSanitizeAndSplitDoesNotPersistExternalNodes(): void {
        $schema = [
            'tienda' => [
                'type' => 'group',
                'label' => 'Tienda',
                'children' => [
                    'badges' => [
                        'type' => 'product_fields_list',
                        'label' => 'Distintivos',
                        'items' => ['camiseta' => 'Camiseta'],
                        'meta_fields' => $this->metaFields(),
                    ],
                    'name' => ['type' => 'text', 'label' => 'Nombre', 'default' => ''],
                ],
            ],
        ];

        [$public, $secrets] = AdminSave::sanitizeAndSplit($schema, [
            'tienda' => ['badges' => ['camiseta' => ['badge' => 'x']], 'name' => 'N'],
        ]);

        // `badges` lo guarda su tipo en `hwe_control_center_saved`; aquí no sale en ningún cubo.
        $this->assertSame(['tienda' => ['name' => 'N']], $public);
        $this->assertSame([], $secrets);
    }

    // ── product_fields_list: guardado ───────────────────────────────────────

    public function testSaveWritesOnlyTheDeclaredMetas(): void {
        $this->withWoo();
        $product = $this->withProduct('camiseta', 7, ['_hwe_badge' => 'Viejo', '_otra' => 'intacta']);

        ProductFieldsListType::saveSubmitted(
            ['camiseta' => ['badge' => 'Nuevo', 'note' => 'Hola', 'no_declarada' => 'x']],
            $this->metaFields(),
            $this->resolver(['camiseta' => 7])
        );

        $this->assertSame('Nuevo', $product->meta['_hwe_badge']);
        $this->assertSame('Hola', $product->meta['_hwe_note']);
        $this->assertArrayNotHasKey('no_declarada', $product->meta);
        $this->assertArrayNotHasKey('_hwe_no_declarada', $product->meta);
        $this->assertSame('intacta', $product->meta['_otra']);
        $this->assertSame(1, $product->saves);
    }

    public function testSaveFromTheSchemaOnlyWritesTheProductsTheNodeDeclares(): void {
        $this->withWoo();
        $declared   = $this->withProduct('camiseta', 7);
        $undeclared = $this->withProduct('otro', 8);
        Functions\when('get_option')->justReturn([]);
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(function (array $schema): array {
            $schema['instance']['children']['badges'] = [
                'type' => 'product_fields_list',
                'label' => 'Distintivos',
                'items' => ['camiseta' => 'Camiseta'],
                'meta_fields' => $this->metaFields(),
            ];
            return $schema;
        });

        ProductFieldsListType::saveAllFromSchema(
            [],
            ['instance' => ['badges' => [
                'camiseta' => ['badge' => 'Nuevo'],
                'otro' => ['badge' => 'Colado'],
            ]]],
            $this->resolver(['camiseta' => 7, 'otro' => 8])
        );

        $this->assertSame('Nuevo', $declared->meta['_hwe_badge'] ?? null);
        $this->assertArrayNotHasKey('_hwe_badge', $undeclared->meta);
        $this->assertSame(0, $undeclared->saves);
        $this->assertStringContainsString('otro', implode("\n", $this->logged));
    }

    public function testSaveDeletesEmptyMetasAndSkipsUnchangedProducts(): void {
        $this->withWoo();
        $changed   = $this->withProduct('a', 1, ['_hwe_badge' => 'Viejo']);
        $unchanged = $this->withProduct('b', 2, ['_hwe_badge' => 'Igual']);

        ProductFieldsListType::saveSubmitted(
            ['a' => ['badge' => ''], 'b' => ['badge' => 'Igual']],
            $this->metaFields(),
            $this->resolver(['a' => 1, 'b' => 2])
        );

        $this->assertArrayNotHasKey('_hwe_badge', $changed->meta, 'Vacío = borrar la meta.');
        $this->assertSame(1, $changed->saves);
        $this->assertSame(0, $unchanged->saves, 'Sin cambios no se guarda ni se dispara el webhook.');
    }

    public function testSaveIgnoresUnknownProductsAndLogsThem(): void {
        $this->withWoo();
        ProductFieldsListType::saveSubmitted(
            ['fantasma' => ['badge' => 'x']],
            $this->metaFields(),
            $this->resolver([])
        );

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('fantasma', $this->logged[0]);
    }

    // La clase marcadora que define `withWoo()` viviría en este proceso: este test corre
    // en el suyo para comprobar la rama «sin WooCommerce» de verdad.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveWithoutWooDoesNothingButLogs(): void {
        // `wc_get_product` no existe sin WooCommerce (Brain Monkey tampoco la define):
        // el guardado no hace nada y lo registra en vez de romper.
        ProductFieldsListType::saveSubmitted(
            ['camiseta' => ['badge' => 'x']],
            $this->metaFields(),
            $this->resolver(['camiseta' => 7])
        );

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('WooCommerce', $this->logged[0]);
    }
}
