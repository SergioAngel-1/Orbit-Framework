<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\PanelLayout;
use HWE\ControlCenter\PanelMap;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\Tests\TestCase;

/**
 * El mapa del panel (`PanelMap`) y su aplicación al esquema efectivo (`PanelLayout`).
 *
 * Lo que importa: que reorganizar el panel nunca pierda ni duplique un campo. Cada hoja del
 * esquema efectivo (núcleo + lo que añadan módulos e instancia + las variantes traducibles)
 * se pinta exactamente una vez, y lo que el mapa no nombra cae en «Otros ajustes» de su raíz.
 */
final class PanelLayoutTest extends TestCase
{
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
        Functions\when('error_log')->justReturn(true);
    }

    /**
     * Esquema efectivo con lo que sumarían los módulos y la instancia: campos y grupos en sus
     * raíces reservadas, un campo traducible de la instancia y un campo nuevo del núcleo que el
     * mapa no conoce.
     *
     * @return array<string,array>
     */
    private function effectiveSchema(): array
    {
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(static function (array $schema): array {
            $schema['shipping']['children']['reserve_cost'] = ['type' => 'number', 'label' => 'Coste de reserva'];
            $schema['loyalty']['children']['enabled'] = ['type' => 'boolean', 'label' => 'Activar'];
            $schema['loyalty']['children']['levels'] = [
                'type' => 'group',
                'label' => 'Niveles',
                'children' => ['gold' => ['type' => 'text', 'label' => 'Oro']],
            ];
            $schema['ecommerce']['children']['brand_new_flag'] = ['type' => 'boolean', 'label' => 'Campo nuevo'];
            $schema['instance']['children']['example'] = [
                'type' => 'group',
                'label' => 'Ejemplo',
                'children' => [
                    'greeting' => ['type' => 'text', 'label' => 'Saludo', 'translatable' => true],
                ],
            ];
            return $schema;
        });
        return Schema::get();
    }

    /** @return list<string> rutas con puntos de las hojas del esquema */
    private function leaves(array $nodes, string $prefix = ''): array
    {
        $paths = [];
        foreach ($nodes as $key => $node) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (($node['type'] ?? 'text') === 'group') {
                array_push($paths, ...$this->leaves($node['children'] ?? [], $path));
            } else {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /** @return array{tabs:array<string,array<string,mixed>>,placed:list<string>,html:string} */
    private function compose(array $schema, array $extras = []): array
    {
        $walker = new AdminFormWalker();
        $walker->walk($schema, []);
        $result = PanelLayout::compose($schema, $walker->fragments(), $extras);
        $html = '';
        foreach ($result['tabs'] as $tab) {
            foreach ($tab['sections'] as $section) {
                $html .= $section['html'];
            }
        }
        return $result + ['html' => $html];
    }

    private function sectionTitles(array $tab): array
    {
        return array_map(static fn (array $s): string => $s['title'], $tab['sections']);
    }

    public function testEveryLeafOfTheEffectiveSchemaIsPlacedExactlyOnce(): void
    {
        $schema = $this->effectiveSchema();
        $result = $this->compose($schema);
        $leaves = $this->leaves($schema);

        $this->assertGreaterThan(150, count($leaves), 'El núcleo tiene ~150 campos: el test recorre el esquema entero.');
        $placed = $result['placed'];
        sort($placed);
        $expected = $leaves;
        sort($expected);
        $this->assertSame($expected, $placed, 'Ninguna hoja se pierde ni se repite.');

        foreach ($leaves as $leaf) {
            $id = 'hwe_config_' . str_replace('.', '_', $leaf);
            $this->assertSame(
                1,
                substr_count($result['html'], '<label for="' . $id . '">'),
                "«{$leaf}» sale una sola vez en el HTML del panel."
            );
        }
    }

    public function testTheMapNamesOnlyRealPathsAndEachOneOnce(): void
    {
        $core = Schema::core();
        $seen = [];
        foreach (PanelMap::tabs() as $tabKey => $tab) {
            $this->assertArrayHasKey($tab['layer'], PanelMap::layers(), "La capa de «{$tabKey}» existe.");
            $this->assertStringStartsWith('dashicons-', $tab['icon'], "«{$tabKey}» usa un dashicon.");
            foreach ($tab['sections'] as $section) {
                $this->assertNotSame('', $section['title'], "Las secciones de «{$tabKey}» llevan título.");
                $this->assertNotSame('', $section['intro'], "«{$section['title']}» dice para qué sirve.");
                foreach ($section['paths'] as $entry) {
                    $path = preg_replace('/\.\*$/', '', $entry);
                    $node = $core;
                    $found = true;
                    foreach (explode('.', $path) as $i => $key) {
                        $node = $i === 0 ? ($core[$key] ?? null) : ($node['children'][$key] ?? null);
                        if ($node === null) {
                            $found = false;
                            break;
                        }
                    }
                    $this->assertTrue($found, "«{$entry}» (en «{$tabKey}») existe en el esquema del núcleo.");
                    $this->assertArrayNotHasKey($entry, $seen, "«{$entry}» aparece en el mapa una sola vez.");
                    $seen[$entry] = true;
                }
            }
        }
        foreach (PanelMap::rootTabs() as $root => $tab) {
            $this->assertArrayHasKey($root, $core, "«{$root}» es una raíz del esquema.");
            $this->assertArrayHasKey($tab, PanelMap::tabs(), "La pestaña por defecto de «{$root}» existe.");
        }
        foreach (array_keys($core) as $root) {
            $this->assertArrayHasKey($root, PanelMap::rootTabs(), "La raíz «{$root}» tiene pestaña por defecto.");
        }
    }

    public function testTheCoreSchemaIsFullyMappedSoNothingFallsIntoOtherSettings(): void
    {
        $result = $this->compose(Schema::get());

        foreach ($result['tabs'] as $key => $tab) {
            $this->assertNotContains('Otros ajustes', $this->sectionTitles($tab), "Con el esquema del núcleo, «{$key}» no necesita «Otros ajustes».");
        }
    }

    public function testTabsFollowTheNavigationOrderOfTheBrief(): void
    {
        $result = $this->compose($this->effectiveSchema());

        $this->assertSame(
            ['store', 'payments', 'shipping', 'support', 'loyalty', 'banners', 'brand', 'search', 'site', 'email', 'marketing', 'services', 'backups', 'instance'],
            array_keys($result['tabs']),
            'Memberships y Landings no salen: sus módulos no han añadido campos.'
        );
        $this->assertSame(
            ['business', 'business', 'business', 'business', 'business', 'business', 'visibility', 'visibility', 'setup', 'setup', 'setup', 'setup', 'setup', 'setup'],
            array_column($result['tabs'], 'layer')
        );
    }

    public function testUnmappedFieldsFallIntoOtherSettingsOfTheirRootsDefaultTab(): void
    {
        $result = $this->compose($this->effectiveSchema());

        $store = $result['tabs']['store'];
        $this->assertSame('Otros ajustes', end($store['sections'])['title']);
        $this->assertStringContainsString('hwe_config[ecommerce][brand_new_flag]', end($store['sections'])['html']);

        $shipping = $result['tabs']['shipping'];
        $this->assertSame(['Envío gratis', 'Tarifas y distancia'], $this->sectionTitles($shipping));
        $this->assertStringContainsString('hwe_config[shipping][reserve_cost]', $shipping['sections'][1]['html']);

        // Un módulo cuya pestaña es solo suya no necesita título para lo suyo, y su subgrupo conserva el suyo.
        $loyalty = $result['tabs']['loyalty'];
        $this->assertSame([''], $this->sectionTitles($loyalty));
        $this->assertStringContainsString('Niveles', $loyalty['sections'][0]['html']);
        $this->assertStringContainsString('hwe_config[loyalty][levels][gold]', $loyalty['sections'][0]['html']);

        $instance = $result['tabs']['instance'];
        $this->assertStringContainsString('hwe_config[instance][example][greeting]', $instance['sections'][0]['html']);
    }

    public function testTranslatableVariantsStayGluedToTheirField(): void
    {
        $result = $this->compose($this->effectiveSchema());

        $identity = $result['tabs']['brand']['sections'][0]['html'];
        $tagline = strpos($identity, 'hwe_config[brand][tagline]');
        $variant = strpos($identity, 'hwe_config[brand][tagline_en]');
        $description = strpos($identity, 'hwe_config[brand][description]');
        $this->assertNotFalse($tagline);
        $this->assertGreaterThan($tagline, $variant);
        $this->assertLessThan($description, $variant, 'La variante va justo tras su campo, no al final.');

        $faq = null;
        foreach ($result['tabs']['support']['sections'] as $section) {
            if ($section['title'] === 'Preguntas frecuentes') {
                $faq = $section['html'];
            }
        }
        $this->assertNotNull($faq);
        $this->assertStringContainsString('hwe_config[geo][faq_en]', $faq);
        $this->assertStringContainsString('hwe_config[geo][faq_payments_en]', $faq);

        // Un campo traducible de la instancia que el mapa no nombra arrastra su variante a «Instancia».
        $this->assertStringContainsString('hwe_config[instance][example][greeting_en]', $result['tabs']['instance']['sections'][0]['html']);
    }

    public function testTheSectionsOfTheBriefHoldTheirFields(): void
    {
        $tabs = $this->compose($this->effectiveSchema())['tabs'];
        $byTitle = static function (array $tab): array {
            $map = [];
            foreach ($tab['sections'] as $section) {
                $map[$section['title']] = $section['html'];
            }
            return $map;
        };

        $payments = $byTitle($tabs['payments']);
        $this->assertSame(['Métodos de cobro', 'Credenciales de la pasarela'], array_keys($payments));
        $this->assertStringContainsString('hwe_config[payments][gateway_enabled]', $payments['Métodos de cobro']);
        $this->assertStringNotContainsString('hwe_config[payments][wompi]', $payments['Métodos de cobro']);
        foreach (['avalpay', 'wompi', 'payu', 'bold'] as $provider) {
            $this->assertStringContainsString('data-provider="' . $provider . '"', $payments['Credenciales de la pasarela']);
        }
        $this->assertSame('hwe-section--provider', $tabs['payments']['sections'][1]['class']);

        $support = $byTitle($tabs['support']);
        $this->assertStringContainsString('data-hwe-slots', $support['Sedes'], 'Las sedes siguen siendo huecos opcionales.');
        $this->assertStringContainsString('hwe_config[legal][locations][location_5][label]', $support['Sedes']);
        $this->assertStringNotContainsString('hwe-subgroup-title', explode('<div class="hwe-slot"', $support['Sedes'])[0], 'La sección ya lleva el título «Sedes»: el bloque no lo repite.');
        $this->assertStringNotContainsString('hwe_config[legal][locations]', $support['Datos de contacto']);

        $search = $byTitle($tabs['search']);
        foreach (['founder_name', 'knows_about', 'organization_logo'] as $key) {
            $this->assertStringContainsString("hwe_config[seo][{$key}]", $search['Entidad de marca']);
        }
        $this->assertStringContainsString('hwe_config[geo][llms_txt_enabled]', $search['Asistentes de IA']);

        $site = $byTitle($tabs['site']);
        $this->assertStringContainsString('hwe_config[brand][url]', $site['Dirección e idiomas']);
        $this->assertStringContainsString('hwe_config[i18n][locales]', $site['Dirección e idiomas']);
        $this->assertStringContainsString('hwe_config[auth][phone_login_enabled]', $site['Acceso con teléfono']);

        $this->assertStringContainsString('hwe_config[integrations][smtp_password]', $byTitle($tabs['email'])['Servidor de correo (SMTP)']);
        $this->assertStringContainsString('hwe_config[integrations][maps_api_key]', $byTitle($tabs['services'])['Mapas']);
        $this->assertStringContainsString('hwe_config[backups][enabled]', $byTitle($tabs['backups'])['Copia automática']);
    }

    public function testThePanelAttributeStillSendsAFieldToAnotherTab(): void
    {
        $schema = [
            'seo' => [
                'type' => 'group',
                'label' => 'SEO',
                'children' => [
                    'title' => ['type' => 'text', 'label' => 'Título'],
                    'knows' => ['type' => 'text', 'label' => 'Temas', 'panel' => ['tab' => 'geo', 'section' => 'Entidad extra']],
                    'lost' => ['type' => 'text', 'label' => 'Perdido', 'panel' => ['tab' => 'nope', 'section' => 'X']],
                ],
            ],
            'geo' => ['type' => 'group', 'label' => 'GEO', 'children' => ['faq' => ['type' => 'textarea', 'label' => 'FAQ']]],
            'instance' => ['type' => 'group', 'label' => 'Instancia', 'children' => []],
        ];
        $result = $this->compose($schema);

        // `geo` es una raíz cuya pestaña por defecto es «Buscadores e IA» (`search`): ahí cae `knows`.
        $titles = $this->sectionTitles($result['tabs']['search']);
        $this->assertSame(['Entidad extra', 'Otros ajustes'], $titles, 'La sección de `panel` va antes que «Otros ajustes».');
        $sections = array_column($result['tabs']['search']['sections'], 'html', 'title');
        $this->assertStringContainsString('hwe_config[seo][knows]', $sections['Entidad extra']);
        $this->assertStringNotContainsString('hwe_config[seo][knows]', $sections['Otros ajustes']);
        $this->assertStringContainsString('hwe_config[seo][title]', $sections['Otros ajustes']);
        // Una pestaña destino inexistente no pierde el campo: se queda con su raíz.
        $this->assertStringContainsString('hwe_config[seo][lost]', $sections['Otros ajustes']);
        $this->assertContains('seo.knows', $result['placed']);
    }

    public function testEmptyTabsAreHiddenExceptShipping(): void
    {
        $schema = Schema::get();
        $result = $this->compose($schema);

        $this->assertArrayNotHasKey('instance', $result['tabs'], 'Sin campos de la instancia no hay pestaña.');
        foreach (['loyalty', 'memberships', 'landings'] as $module) {
            $this->assertArrayNotHasKey($module, $result['tabs'], "«{$module}» sin módulo no tiene pestaña.");
        }
        $this->assertArrayHasKey('shipping', $result['tabs'], 'Envíos existe siempre: lleva el envío gratis.');
        $this->assertArrayHasKey('banners', $result['tabs']);

        $bare = PanelLayout::compose(['brand' => ['type' => 'group', 'children' => []]], [], [], ['shipping' => PanelMap::tabs()['shipping']]);
        $this->assertArrayHasKey('shipping', $bare['tabs'], '`always` mantiene la pestaña aunque no haya campos.');
    }

    public function testExtraSectionsAreAppendedToTheirTab(): void
    {
        $result = $this->compose(Schema::get(), [
            'email' => [['title' => 'Probar entrega', 'intro' => 'x', 'html' => '<p>prueba</p>']],
        ]);

        $titles = $this->sectionTitles($result['tabs']['email']);
        $this->assertSame('Probar entrega', end($titles));
    }
}
