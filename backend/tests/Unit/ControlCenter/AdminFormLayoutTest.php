<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\Tests\TestCase;

/**
 * Atributos de maquetación del panel que no tocan el dato: `panel` (pintar un campo en otra
 * pestaña) y `slots` (huecos opcionales, como las sedes). Más su uso en el esquema del núcleo:
 * sedes opcionales hasta 5, pagos que solo enseñan la pasarela activa y lo de GEO en su pestaña.
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

    /** @return array<string,array> */
    private function schema(string $target): array {
        return [
            'seo' => [
                'type' => 'group',
                'label' => 'SEO',
                'children' => [
                    'title' => ['type' => 'text', 'label' => 'Título'],
                    'knows' => ['type' => 'text', 'label' => 'Temas', 'panel' => ['tab' => $target, 'section' => 'Entidad']],
                ],
            ],
            'geo' => [
                'type' => 'group',
                'label' => 'GEO',
                'children' => ['faq' => ['type' => 'textarea', 'label' => 'FAQ']],
            ],
        ];
    }

    public function testAPanelFieldMovesToItsTabWithTheSameName(): void {
        $walker   = new AdminFormWalker();
        $sections = $walker->withRelocated($walker->walk($this->schema('geo'), ['seo' => ['knows' => 'café']]));

        $this->assertStringNotContainsString('hwe_config[seo][knows]', $sections['seo']);
        $this->assertStringContainsString('hwe_config[seo][knows]', $sections['geo'], 'El input conserva su ruta.');
        $this->assertStringContainsString('value="café"', $sections['geo']);
        $this->assertStringContainsString('Entidad', $sections['geo']);
        $this->assertSame([], $this->logged);
    }

    public function testAPanelPointingNowhereStaysHomeAndLogs(): void {
        $walker   = new AdminFormWalker();
        $sections = $walker->withRelocated($walker->walk($this->schema('nope'), []));

        $this->assertStringContainsString('hwe_config[seo][knows]', $sections['seo']);
        $this->assertArrayNotHasKey('nope', $sections);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('«nope»', $this->logged[0]);
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
            $this->assertSame('geo', $core['seo']['children'][$key]['panel']['tab'] ?? null, "seo.{$key} se edita en GEO.");
        }

        $js = (string) file_get_contents(HWE_WP_CONTENT_DIR . '/mu-plugins/hwe-control-center/assets/admin.js');
        $this->assertStringContainsString('initSlots', $js);
        $this->assertStringContainsString('#hwe_config_payments_gateway_enabled', $js);
    }
}
