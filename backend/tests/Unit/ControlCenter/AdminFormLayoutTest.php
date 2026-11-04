<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\Tests\TestCase;

/**
 * El HTML de los campos y los atributos de maquetación que no tocan el dato: `slots` (huecos
 * opcionales, como las sedes). Más su uso en el esquema del núcleo: sedes opcionales hasta 5 y
 * pagos que solo enseñan la pasarela activa. Dónde cae cada campo lo prueba `PanelLayoutTest`.
 */
final class AdminFormLayoutTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            'esc_textarea' => static fn ($v) => (string) $v,
            'esc_url' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $default);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    public function testEveryNodeGetsAFragmentKeyedByItsDottedPath(): void {
        $walker = new AdminFormWalker();
        $walker->walk([
            'seo' => [
                'type' => 'group',
                'label' => 'SEO',
                'children' => [
                    'knows' => ['type' => 'text', 'label' => 'Temas'],
                    'og' => ['type' => 'group', 'label' => 'Compartir', 'children' => ['image' => ['type' => 'text', 'label' => 'Imagen']]],
                ],
            ],
        ], ['seo' => ['knows' => 'café']]);

        $fragments = $walker->fragments();
        $this->assertSame(['seo.knows', 'seo.og.image', 'seo.og'], array_keys($fragments));
        $this->assertStringContainsString('name="hwe_config[seo][knows]"', $fragments['seo.knows']['html']);
        $this->assertStringContainsString('value="café"', $fragments['seo.knows']['html']);
        $this->assertFalse($fragments['seo.knows']['group']);
        $this->assertTrue($fragments['seo.og']['group']);
        $this->assertStringContainsString('Compartir', $fragments['seo.og']['html']);
        $this->assertStringContainsString('hwe_config[seo][og][image]', $fragments['seo.og']['bare']);
    }

    public function testANestedGroupIsAClosedDetailsWithItsTitleInTheSummary(): void {
        $walker = new AdminFormWalker();
        $walker->walk([
            'seo' => [
                'type' => 'group',
                'label' => 'SEO',
                'children' => [
                    'og' => ['type' => 'group', 'label' => 'Compartir', 'children' => ['image' => ['type' => 'text', 'label' => 'Imagen']]],
                ],
            ],
        ], []);

        $html = $walker->fragments()['seo.og']['html'];
        $this->assertStringStartsWith('<details class="hwe-subgroup" data-hwe-fold>', $html, 'Plegado de serie: sin `open`.');
        $this->assertMatchesRegularExpression('#<summary class="hwe-subgroup__head">\s*<h4 class="hwe-subgroup-title">Compartir</h4>#', $html);
        $this->assertStringContainsString('data-hwe-fold-dirty hidden', $html, 'Lleva su punto de «cambios sin guardar», oculto de serie.');
        $this->assertStringContainsString('hwe_config[seo][og][image]', $html, 'Los campos siguen dentro, plegados pero en el formulario.');
    }

    public function testTranslatableRowsAndTheirVariantsCarryTheLanguageAttributes(): void {
        $schema = [
            'brand' => [
                'type' => 'group',
                'label' => 'Marca',
                'children' => [
                    'tagline' => ['type' => 'text', 'label' => 'Lema', 'translatable' => true],
                    'tagline_en' => ['type' => 'text', 'label' => 'Lema (EN)', 'locale' => 'en', 'variant_of' => 'tagline'],
                    'name' => ['type' => 'text', 'label' => 'Nombre'],
                ],
            ],
        ];
        $walker = new AdminFormWalker();
        $walker->walk($schema, []);
        $fragments = $walker->fragments();

        $this->assertStringContainsString('<div class="hwe-field" data-hwe-translatable>', $fragments['brand.tagline']['html']);
        $this->assertStringContainsString(
            '<div class="hwe-field" data-hwe-locale="en" data-hwe-base="hwe_config_brand_tagline">',
            $fragments['brand.tagline_en']['html'],
            'La variante apunta al id del campo original, de donde el panel saca la referencia.'
        );
        $this->assertStringContainsString('<div class="hwe-field">', $fragments['brand.name']['html'], 'Un campo que no se traduce no lleva marcas.');
        $this->assertStringContainsString('name="hwe_config[brand][tagline_en]"', $fragments['brand.tagline_en']['html'], 'La variante sigue siendo un campo del formulario.');
    }

    public function testAToggleRowKeepsItsLabelOnlyForScreenReaders(): void {
        $walker = new AdminFormWalker();
        $walker->walk(['x' => ['type' => 'group', 'label' => 'X', 'children' => ['on' => ['type' => 'boolean', 'label' => 'Activar']]]], []);

        $this->assertStringContainsString('class="hwe-field hwe-field--toggle"', $walker->fragments()['x.on']['html']);
    }

    public function testSlotsWrapEachChildAndOfferAddAndRemove(): void {
        $sections = (new AdminFormWalker())->walk([
            'legal' => [
                'type' => 'group',
                'label' => 'Legal',
                'children' => [
                    'locations' => [
                        'type' => 'group',
                        'label' => 'Sedes',
                        'slots' => ['add_label' => 'Añadir sede', 'remove_label' => 'Quitar sede', 'empty' => 'Sin sedes.'],
                        'children' => [
                            'a' => ['type' => 'group', 'label' => 'Sede 1', 'children' => ['label' => ['type' => 'text', 'label' => 'Nombre']]],
                            'b' => ['type' => 'group', 'label' => 'Sede 2', 'children' => ['label' => ['type' => 'text', 'label' => 'Nombre']]],
                        ],
                    ],
                ],
            ],
        ], []);

        $html = $sections['legal'];
        $this->assertSame(2, substr_count($html, 'data-hwe-slot>'));
        $this->assertSame(2, substr_count($html, 'Quitar sede'));
        $this->assertStringContainsString('Añadir sede', $html);
        $this->assertStringContainsString('Sin sedes.', $html);
        // Sin JS se ven todos los huecos: solo los botones nacen ocultos.
        $this->assertStringNotContainsString('data-hwe-slot hidden', $html);
    }

    public function testTheCoreSchemaUsesThem(): void {
        $core = Schema::get();

        $locations = $core['legal']['children']['locations'];
        $this->assertNotEmpty($locations['slots']);
        $this->assertSame(
            ['location_1', 'location_2', 'location_3', 'location_4', 'location_5'],
            array_keys($locations['children'])
        );

        $payments = array_keys($core['payments']['children']);
        $this->assertSame(['gateway_enabled', 'provider'], array_slice($payments, 0, 2), 'El interruptor gobierna la pasarela: va antes.');

        $this->assertSame('SEO', $core['seo']['label']);
        foreach (['organization_logo', 'founding_date', 'knows_about', 'alternate_names', 'founder_name', 'founder_role', 'founder_url'] as $key) {
            $this->assertArrayNotHasKey('panel', $core['seo']['children'][$key], "seo.{$key} se coloca desde PanelMap, no desde el esquema.");
        }

        $js = (string) file_get_contents(HWE_WP_CONTENT_DIR . '/mu-plugins/hwe-control-center/assets/admin.js');
        $this->assertStringContainsString('initSlots', $js);
        $this->assertStringContainsString('#hwe_config_payments_gateway_enabled', $js);
    }
}
