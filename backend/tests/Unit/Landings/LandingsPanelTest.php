<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Landings;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\FieldTypes\Registry as FieldTypesRegistry;
use HWE\ControlCenter\PanelLayout;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\ControlCenter\Walkers\PublicConfigWalker;
use HWE\Landings\ContentSchema;
use HWE\Landings\Options;
use HWE\Landings\Plugin;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Cómo pinta el Control Center el molde de una landing: en ORDEN DE PÁGINA, plegable, con un
 * selector de landing, resúmenes de cabecera, condiciones y huecos.
 *
 * Todo es presentación: el orden de las claves del esquema (y con él el de `/config`), los
 * `name` de los inputs y lo guardado no cambian, y ningún campo se pierde ni se repite.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LandingsPanelTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = [];
    /** @var array<string,array<string,string>> Landings con diseño propio que declara la instancia. */
    private array $static = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = [
            'brand' => ['locale' => 'es'],
            'i18n' => ['locales' => 'en'],
            'landings' => ['registry' => 'guia | Guía de uso | article'],
        ];
        $this->static = ['perfil' => ['label' => 'Perfil', 'jsonld' => 'person']];

        Functions\stubs([
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags((string) $v))), '-'),
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'esc_attr' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_html' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_textarea' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_url' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
            'admin_url' => static fn ($path = '') => 'https://wp.test/wp-admin/' . $path,
            'taxonomy_exists' => static fn () => false,
            'plugin_dir_url' => static fn () => 'https://wp.test/wp-content/plugins/hwe-landings/',
            'is_admin' => static fn () => false,
        ]);
        Functions\when('wp_editor')->alias(static function (string $value, string $id, array $settings): void {
            echo '<textarea id="' . $id . '" name="' . $settings['textarea_name'] . '">' . htmlspecialchars($value) . '</textarea>';
        });
        Functions\when('get_option')->alias(
            fn (string $key, $default = false) => 'hwe_config' === $key ? $this->config : $default
        );
        Functions\when('error_log')->justReturn(true);

        $this->loadOwnFile('plugins/hwe-landings/hwe-landings.php');
        Options::reset();
        // Una landing del panel (con tipo) y otra con diseño propio, declarada por la instancia.
        Filters\expectApplied('hwe_landings_static_entries')->andReturnUsing(fn () => $this->static);
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(
            static fn ($schema) => ContentSchema::inject($schema)
        );
        Filters\expectApplied('hwe_control_center_field_types')->andReturnUsing(
            static fn ($types) => Plugin::fieldTypes($types)
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function schema(): array
    {
        return Schema::get();
    }

    /** @return list<string> rutas con puntos de las hojas */
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

    /** HTML de la pestaña Landings tal como la compone el panel. */
    private function landingsHtml(?array $schema = null): string
    {
        $schema ??= $this->schema();
        $walker = new AdminFormWalker();
        $walker->walk($schema, []);
        $tabs = PanelLayout::compose($schema, $walker->fragments())['tabs'];
        $this->assertArrayHasKey('landings', $tabs);
        return implode("\n", array_column($tabs['landings']['sections'], 'html'));
    }

    /** HTML de una landing (su elemento del selector). */
    private function item(string $html, string $id): string
    {
        $start = strpos($html, 'data-hwe-switch-item="' . $id . '"');
        $this->assertNotFalse($start, "Existe la landing «{$id}».");
        $next = strpos($html, 'data-hwe-switch-item="', $start + 10);
        return substr($html, $start, ($next === false ? strlen($html) : $next) - $start);
    }

    public function testEveryLeafOfTwoLandingsWithASecondaryLanguageIsPaintedExactlyOnce(): void
    {
        $schema = $this->schema();
        $leaves = $this->leaves($schema);
        $this->assertGreaterThan(300, count($leaves), 'Dos landings con un idioma secundario son unos 300 campos propios.');
        $this->assertContains('landings.editorial.guia.sections.s5.body_en', $leaves);
        $this->assertContains('landings.editorial.perfil.faq.q6.answer_en', $leaves);

        $walker = new AdminFormWalker();
        $walker->walk($schema, []);
        $result = PanelLayout::compose($schema, $walker->fragments());
        $html = '';
        foreach ($result['tabs'] as $tab) {
            foreach ($tab['sections'] as $section) {
                $html .= $section['html'];
            }
        }

        $placed = $result['placed'];
        sort($placed);
        $expected = $leaves;
        sort($expected);
        $this->assertSame($expected, $placed, 'Ninguna hoja se pierde ni se repite.');
        foreach ($leaves as $leaf) {
            $this->assertSame(
                1,
                substr_count($html, '<label for="hwe_config_' . str_replace('.', '_', $leaf) . '">'),
                "«{$leaf}» sale una sola vez en el HTML del panel."
            );
        }
    }

    public function testTheSchemaKeepsItsDataOrderAndOnlyGainsAdminAttributes(): void
    {
        $guia = $this->schema()['landings']['children']['editorial']['children']['guia'];

        $this->assertSame(
            ['published', 'jsonld_type', 'url', 'hero_note', 'accent', 'product', 'product_cta_label', 'product_cta_label_en', 'seo', 'lead_enabled', 'lead_body', 'lead_body_en', 'sections', 'quote', 'faq', 'products', 'closing'],
            array_keys($guia['children']),
            'El orden de las claves (el del dato y el de /config) es el de siempre.'
        );
        $this->assertSame(['s1', 's2', 's3', 's4', 's5'], array_keys($guia['children']['sections']['children']));
        $this->assertNotEmpty($guia['blocks'], 'El orden de página vive en `blocks`.');

        // Ninguno de los atributos de presentación llega a la API pública.
        $values = ['landings' => ['editorial' => ['guia' => ['published' => true]]]];
        $public = (new PublicConfigWalker())->walk(['landings' => $this->schema()['landings']], $values);
        $json = json_encode($public, JSON_THROW_ON_ERROR);
        foreach (['blocks', 'summary', 'master', 'show_if', 'switcher', 'slots'] as $attribute) {
            $this->assertStringNotContainsString('"' . $attribute . '"', $json);
        }
        $this->assertSame(
            array_keys($guia['children']['sections']['children']['s1']['children']),
            array_keys($public['landings']['editorial']['guia']['sections']['s1'])
        );
    }

    public function testBlocksFollowPageOrderAndEachOneIsAFoldableBlock(): void
    {
        $guia = $this->item($this->landingsHtml(), 'guia');

        $order = [];
        foreach (['Ajustes de la landing', 'Hero', 'Entradilla', 'Secciones de contenido', 'Cita destacada', 'Preguntas frecuentes', 'Productos', 'Cierre'] as $title) {
            $pos = strpos($guia, '<h4 class="hwe-subgroup-title">' . $title . '</h4>');
            $this->assertNotFalse($pos, "Existe el bloque «{$title}».");
            $order[] = $pos;
        }
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Los bloques salen en orden de página.');

        // Dentro de Ajustes: Publicar, Tipo, URL pública, SEO, producto y su botón, color de apoyo.
        $settings = substr($guia, $order[0], $order[1] - $order[0]);
        $positions = [];
        foreach (['[published]', '[registry][0][jsonld]', '[url][base]', '[seo][title]', '[product]', '[product_cta_label]', '[product_cta_label_en]', '[accent]'] as $needle) {
            $pos = strpos($settings, $needle);
            $this->assertNotFalse($pos, "Ajustes lleva «{$needle}».");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringNotContainsString('hero_note', $settings, 'El color de apoyo va tras el botón del producto; el hero tiene su bloque.');
        $this->assertStringContainsString('hero_note', substr($guia, $order[1], $order[2] - $order[1]));

        // Todos plegados de serie.
        $this->assertStringNotContainsString('data-hwe-fold open', $guia);
    }

    public function testASingleBlockKeepsTranslatableVariantsGluedToTheirField(): void
    {
        $guia = $this->item($this->landingsHtml(), 'guia');

        $this->assertLessThan(strpos($guia, '[lead_body_en]'), strpos($guia, '[lead_body]'));
        $this->assertLessThan(strpos($guia, '[accent]'), strpos($guia, '[product_cta_label_en]'));
        $lead = substr($guia, strpos($guia, '<h4 class="hwe-subgroup-title">Entradilla</h4>'), 1800);
        $this->assertLessThan(strpos($lead, '[lead_body_en]'), strpos($lead, '[lead_body]'));
        $this->assertSame(1, substr_count($guia, 'hwe_config[landings][editorial][guia][lead_body_en]'));
    }

    public function testOnlyOneLandingAtATimeThroughASelectorThatKeepsEveryLandingInTheForm(): void
    {
        $html = $this->landingsHtml();

        $this->assertSame(1, substr_count($html, 'data-hwe-switcher>'));
        $this->assertSame(2, substr_count($html, 'data-hwe-switch-item="'));
        $this->assertMatchesRegularExpression('#<select id="hwe_config_landings_editorial__switch" data-hwe-switcher-select><option value="perfil">Perfil \(diseño propio\)</option><option value="guia">Guía de uso</option></select>#', str_replace("\n", '', $html) ?: '');
        $this->assertStringContainsString('data-hwe-switcher-bar hidden>', $html, 'Sin JS no hay selector: se ven todas.');
        $this->assertStringNotContainsString('name="hwe_config[landings][editorial][__', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*data-hwe-switcher-select[^>]* name=/', $html, 'El selector no es un campo: no viaja en el POST.');
        // El registro va arriba y las dos landings siguen en el formulario.
        $this->assertLessThan(strpos($html, 'data-hwe-switcher'), strpos($html, 'hwe_config[landings][registry]'));
        $this->assertStringContainsString('hwe_config[landings][editorial][guia][published]', $html);
        $this->assertStringContainsString('hwe_config[landings][editorial][perfil][published]', $html);
    }

    public function testASingleLandingStillRendersItsItemSoTheScriptDecidesNotToShowASelector(): void
    {
        $this->static = [];

        $html = $this->landingsHtml();

        $this->assertSame(1, substr_count($html, 'data-hwe-switch-item="'));
        $this->assertStringContainsString('hwe_config[landings][editorial][guia][published]', $html);
    }

    public function testASectionLayoutHidesTheControlsItDoesNotUseWithoutRemovingThem(): void
    {
        $guia = $this->item($this->landingsHtml(), 'guia');
        $row = static function (string $html, string $field): string {
            preg_match('#<div class="hwe-field[^"]*"[^>]*>\s*<label for="hwe_config_landings_editorial_guia_sections_s1_' . $field . '">.*?</div>\s*</div>#s', $html, $m);
            return $m[0] ?? '';
        };

        foreach (['image', 'image_alt', 'image_alt_en'] as $field) {
            $this->assertStringContainsString(
                'data-hwe-show-if="{&quot;f&quot;:&quot;hwe_config_landings_editorial_guia_sections_s1_layout&quot;,&quot;in&quot;:[&quot;image-left&quot;,&quot;image-right&quot;,&quot;full&quot;,&quot;mirror-pair&quot;]}"',
                $row($guia, $field),
                "«{$field}» solo se ve con una disposición que pinta la imagen."
            );
        }
        foreach (['image_2', 'image_2_alt', 'image_2_alt_en'] as $field) {
            $this->assertStringContainsString('&quot;in&quot;:[&quot;mirror-pair&quot;]', $row($guia, $field));
        }
        // El control está en el HTML (y en el formulario): esconderlo es cosa del script.
        $this->assertStringContainsString('name="hwe_config[landings][editorial][guia][sections][s1][image]"', $guia);
        $this->assertStringContainsString('name="hwe_config[landings][editorial][guia][sections][s1][image_2]"', $guia);
        $this->assertStringNotContainsString('data-hwe-show-if', $row($guia, 'title'), 'El título lo usan todas las disposiciones.');
        $this->assertStringNotContainsString('data-hwe-show-if', $row($guia, 'layout'));
    }

    public function testProductsShowOnlyTheControlOfTheChosenSource(): void
    {
        $guia = $this->item($this->landingsHtml(), 'guia');

        preg_match('#<div class="hwe-field[^"]*"([^>]*)>\s*<label for="hwe_config_landings_editorial_guia_products_category">#', $guia, $category);
        preg_match('#<div class="hwe-field[^"]*"([^>]*)>\s*<label for="hwe_config_landings_editorial_guia_products_manual">#', $guia, $manual);
        $this->assertStringContainsString('hwe_config_landings_editorial_guia_products_mode&quot;,&quot;in&quot;:[&quot;category&quot;]', $category[1]);
        $this->assertStringContainsString('hwe_config_landings_editorial_guia_products_mode&quot;,&quot;in&quot;:[&quot;manual&quot;]', $manual[1]);
        $this->assertStringContainsString('name="hwe_config[landings][editorial][guia][products][category]"', $guia);
        $this->assertStringContainsString('<label for="hwe_config_landings_editorial_guia_products_manual">', $guia, 'La selección manual sigue en el formulario, escondida o no.');
    }

    public function testEveryBlockSaysItsStateInItsHeaderAndSwitchedOffBlocksKeepOnlyTheirToggle(): void
    {
        $guia = $this->item($this->landingsHtml(), 'guia');
        $summaryOf = static function (string $html, string $needle): array {
            preg_match('#<details class="hwe-subgroup" data-hwe-fold[^>]*data-hwe-summary="([^"]*)"[^>]*>\s*<summary[^>]*>\s*<h4 class="hwe-subgroup-title">' . preg_quote($needle, '#') . '</h4>#', $html, $m);
            return json_decode(html_entity_decode($m[1] ?? '{}'), true) ?: [];
        };

        $section = $summaryOf($guia, 'Sección 1');
        $this->assertSame('hwe_config_landings_editorial_guia_sections_s1_enabled', $section['t']);
        $this->assertSame(['Visible', 'Oculta'], [$section['on'], $section['off']]);
        $this->assertSame(['layout', 'title'], array_map(static fn (array $p): string => substr($p['f'], strrpos($p['f'], '_') + 1), $section['p']));
        $this->assertSame(1, $section['p'][1]['q'], 'El título va entre comillas.');
        $this->assertStringContainsString('data-hwe-master="hwe_config_landings_editorial_guia_sections_s1_enabled"', $guia);

        $settings = $summaryOf($guia, 'Ajustes de la landing');
        $this->assertSame(['Publicada', 'Borrador'], [$settings['on'], $settings['off']]);
        $this->assertSame('Se edita en Banners', $summaryOf($guia, 'Hero')['empty']);
        $this->assertSame('slots', $summaryOf($guia, 'Secciones de contenido')['p'][0]['as']);
        $this->assertSame('preguntas', $summaryOf($guia, 'Preguntas frecuentes')['p'][0]['unit']);
        foreach (['Cita destacada', 'Productos', 'Cierre', 'Entradilla'] as $block) {
            $this->assertArrayHasKey('t', $summaryOf($guia, $block), "«{$block}» resume su «Mostrar».");
        }
        foreach (['quote', 'faq', 'products', 'closing'] as $group) {
            $this->assertStringContainsString('data-hwe-master="hwe_config_landings_editorial_guia_' . $group . '_enabled"', $guia);
        }
        $this->assertStringContainsString('data-hwe-master="hwe_config_landings_editorial_guia_lead_enabled"', $guia);
    }

    public function testSectionsAndQuestionsAreOptionalSlotsWhoseOtherFieldsStayOutsideThem(): void
    {
        $guia = $this->item($this->landingsHtml(), 'guia');

        $this->assertSame(5 + 6, substr_count($guia, 'data-hwe-slot>'), 'Cinco secciones y seis preguntas son huecos.');
        $this->assertSame(2, substr_count($guia, 'data-hwe-slots-add'));
        $this->assertStringContainsString('Añadir sección', $guia);
        $this->assertStringContainsString('Añadir pregunta', $guia);
        $this->assertSame(5, substr_count($guia, 'Quitar sección'));
        $this->assertSame(6, substr_count($guia, 'Quitar pregunta'));

        // «Mostrar» y el título de las preguntas no son huecos: salen antes del contenedor.
        $faq = substr($guia, strpos($guia, '<h4 class="hwe-subgroup-title">Preguntas frecuentes</h4>'));
        $this->assertLessThan(strpos($faq, 'data-hwe-slots>'), strpos($faq, '[faq][enabled]'));
        $this->assertLessThan(strpos($faq, 'data-hwe-slots>'), strpos($faq, '[faq][title]'));
        $this->assertGreaterThan(strpos($faq, 'data-hwe-slots>'), strpos($faq, '[faq][q1][question]'));
    }

    public function testALandingWithItsOwnDesignHasNoTypeFieldInItsSettings(): void
    {
        $guia = $this->item($this->landingsHtml(), 'perfil');

        $this->assertStringNotContainsString('[registry][', $guia, 'Una landing con diseño propio no tiene tipo que elegir.');
        $this->assertStringContainsString('[published]', $guia);
        $this->assertStringContainsString('[product_cta_label_en]', $guia);
    }

    public function testASchemaWithoutBlocksStillPaintsEveryChild(): void
    {
        $walker = new AdminFormWalker();
        $walker->walk([
            'landings' => ['type' => 'group', 'label' => 'L', 'children' => [
                'editorial' => ['type' => 'group', 'label' => 'E', 'switcher' => ['label' => 'Editando'], 'children' => [
                    'a' => ['type' => 'group', 'label' => 'A', 'blocks' => [['label' => 'Uno', 'keys' => ['x', 'ghost']], 'z'], 'children' => [
                        'x' => ['type' => 'text', 'label' => 'X'],
                        'y' => ['type' => 'text', 'label' => 'Y'],
                        'z' => ['type' => 'text', 'label' => 'Z'],
                    ]],
                ]],
            ]],
        ], []);
        $html = $walker->fragments()['landings.editorial']['html'];

        foreach (['x', 'y', 'z'] as $key) {
            $this->assertSame(1, substr_count($html, 'name="hwe_config[landings][editorial][a][' . $key . ']"'), "«{$key}» sale una vez, nombrado o no.");
        }
        $this->assertLessThan(strpos($html, '[y]'), strpos($html, '[z]'), 'Lo no nombrado va al final.');
    }
}
