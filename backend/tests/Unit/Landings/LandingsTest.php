<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Landings;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminSave;
use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\ControlCenter\Schema;
use HWE\Landings\BannerLocations;
use HWE\Landings\ContentSchema;
use HWE\Landings\FieldTypes\LandingRegistryType;
use HWE\Landings\FieldTypes\RegistryTypeType;
use HWE\Landings\Options;
use HWE\Landings\Plugin;
use HWE\Landings\Registry;
use HWE\Landings\Revalidation;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Módulo `hwe-landings` (tarea 57): registro de landings (estáticas y del panel), esquema de
 * contenido por slots fijos, tipos de campo `landing_registry` y `registry_type`, locaciones de
 * banner por landing y revalidación del tag `landings`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LandingsTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var list<array{url:string,body:string}> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ['brand' => ['locale' => 'es'], 'i18n' => ['locales' => 'en']];
        $this->logged = [];
        $this->posts  = [];

        Functions\stubs([
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags((string) $v))), '-'),
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_textarea_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_key' => static fn ($v) => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)),
            'wp_kses_post' => static fn ($v) => (string) $v,
            'wp_kses' => static fn ($v) => (string) $v,
            'wp_kses_allowed_html' => static fn () => [],
            'esc_url_raw' => static fn ($v) => (string) $v,
            'esc_attr' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_html' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'admin_url' => static fn ($path = '') => 'https://wp.test/wp-admin/' . $path,
            'taxonomy_exists' => static fn () => false,
            'plugin_dir_url' => static fn () => 'https://wp.test/wp-content/plugins/hwe-landings/',
            'is_admin' => static fn () => false,
        ]);
        Functions\when('get_option')->alias(
            fn (string $key, $default = false) => 'hwe_config' === $key ? $this->config : $default
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->posts[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];
            return [];
        });

        $this->loadOwnFile('plugins/hwe-landings/hwe-landings.php');
        Options::reset();
    }

    /** Las landings estáticas que declararía una instancia. */
    private function staticEntries(array $entries): void
    {
        Filters\expectApplied('hwe_landings_static_entries')->andReturn($entries);
    }

    // ── Registro ────────────────────────────────────────────────────────────────

    public function testElRegistroSeParseaYSeVuelveACanonico(): void
    {
        $rows = Registry::parse("  guia-uso | Guía de uso | article \n\nquien-soy | Quién soy | person\n   \n");

        $this->assertSame([
            ['slug' => 'guia-uso', 'label' => 'Guía de uso', 'jsonld' => 'article'],
            ['slug' => 'quien-soy', 'label' => 'Quién soy', 'jsonld' => 'person'],
        ], $rows);
        $this->assertSame("guia-uso | Guía de uso | article\nquien-soy | Quién soy | person", Registry::serialize($rows));
    }

    public function testUnIdentificadorRepetidoOVacioSeDescartaYElTipoInvalidoEsArticulo(): void
    {
        $rows = Registry::parse("a | Primera | person\na | Repetida | article\n | Sin id | article\nb | Sin tipo\nc | Mal tipo | robot");

        $this->assertSame(['a', 'b', 'c'], array_column($rows, 'slug'));
        $this->assertSame('Primera', $rows[0]['label']);
        $this->assertSame('article', $rows[1]['jsonld']);
        $this->assertSame('article', $rows[2]['jsonld']);
    }

    public function testElNombreSinNombreUsaElIdentificadorYNoRompeElFormato(): void
    {
        $text = Registry::serialize([
            ['slug' => 'x', 'label' => '', 'jsonld' => 'article'],
            ['slug' => 'y', 'label' => "Con | barra\ny salto", 'jsonld' => 'person'],
        ]);

        $this->assertSame("x | x | article\ny | Con barra y salto | person", $text);
        $this->assertCount(2, Registry::parse($text));
    }

    public function testLasEstaticasMandanSobreLasDelPanelYLasDelPanelLlevanSuIndice(): void
    {
        $this->staticEntries([
            'dr-perfil' => ['label' => 'Perfil', 'jsonld' => 'person', 'base' => 'root'],
        ]);
        $this->config['landings']['registry'] = "dr-perfil | Intrusa | article\nguia | Guía | article";

        $all = Registry::all();

        $this->assertSame(['dr-perfil', 'guia'], array_keys($all));
        $this->assertFalse($all['dr-perfil']['dynamic']);
        $this->assertSame('Perfil', $all['dr-perfil']['label']);
        $this->assertSame('root', $all['dr-perfil']['base']);
        $this->assertTrue($all['guia']['dynamic']);
        $this->assertSame('area', $all['guia']['base']);
        // El índice es la posición en el registro guardado (la fila que su bloque edita).
        $this->assertSame(1, $all['guia']['registry_index']);
    }

    public function testUnaEntradaEstaticaMalFormadaSeDescartaYQuedaEnElLog(): void
    {
        $this->staticEntries([
            'ok' => ['label' => 'Bien'],
            'mal' => 'no es una lista',
            5 => ['label' => 'id numérico'],
        ]);

        $this->assertSame(['ok'], array_keys(Registry::staticEntries()));
        $this->assertCount(2, $this->logged);
        $this->assertStringContainsString('hwe_landings_static_entries', $this->logged[0]);
    }

    public function testUnFiltroEstaticoQueNoDevuelveUnaListaSeIgnora(): void
    {
        Filters\expectApplied('hwe_landings_static_entries')->andReturn('roto');

        $this->assertSame([], Registry::staticEntries());
        $this->assertCount(1, $this->logged);
    }

    // ── Esquema de contenido ────────────────────────────────────────────────────

    private function coreWithLandings(): array
    {
        return ['landings' => ['type' => 'group', 'label' => 'Landings', 'children' => []]];
    }

    public function testSinElGrupoReservadoElEsquemaNoSeToca(): void
    {
        $schema = ['seo' => ['type' => 'group', 'children' => []]];

        $this->assertSame($schema, ContentSchema::inject($schema));
        $this->assertSame('roto', ContentSchema::inject('roto'));
    }

    public function testSinLandingsSoloSeOfreceElRegistro(): void
    {
        $children = ContentSchema::inject($this->coreWithLandings())['landings']['children'];

        $this->assertSame(['registry'], array_keys($children));
        $this->assertSame('landing_registry', $children['registry']['type']);
        $this->assertTrue($children['registry']['public']);
    }

    public function testCadaLandingTieneSusSlotsFijosYSoloLasDelPanelTienenTipo(): void
    {
        $this->staticEntries(['perfil' => ['label' => 'Perfil', 'jsonld' => 'person']]);
        $this->config['landings']['registry'] = 'guia | Guía | article';

        $editorial = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children'];

        $this->assertSame(['perfil', 'guia'], array_keys($editorial));
        $guia = $editorial['guia']['children'];
        $this->assertSame('registry_type', $guia['jsonld_type']['type']);
        $this->assertSame(0, $guia['jsonld_type']['registry_index']);
        $this->assertArrayNotHasKey('jsonld_type', $editorial['perfil']['children']);
        $this->assertStringContainsString('(diseño propio)', $editorial['perfil']['label']);

        foreach (['published', 'url', 'hero_note', 'accent', 'product', 'seo', 'lead_body', 'sections', 'quote', 'faq', 'products', 'closing'] as $slot) {
            $this->assertArrayHasKey($slot, $guia, $slot);
        }
        $this->assertSame(['s1', 's2', 's3', 's4', 's5'], array_keys($guia['sections']['children']));
        $this->assertSame(['enabled', 'title', 'q1', 'q2', 'q3', 'q4', 'q5', 'q6'], array_keys($guia['faq']['children']));
    }

    public function testLaIdentidadDeUnaSeccionEsSuSlotYElOrdenEsSoloLaPosicionPorDefecto(): void
    {
        $this->config['landings']['registry'] = 'guia | Guía | article';

        $sections = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children']['guia']['children']['sections']['children'];

        foreach (range(1, 5) as $i) {
            $this->assertSame((string) $i, $sections["s$i"]['children']['order']['default']);
            $this->assertSame('image-right', $sections["s$i"]['children']['layout']['default']);
        }
        $this->assertSame(
            ['image-left', 'image-right', 'full', 'text-only', 'prose-blocks', 'mirror-pair', 'divider'],
            array_keys($sections['s1']['children']['layout']['options'])
        );
    }

    public function testElIdentificadorEsInmutableYLaUrlVaEnCamposAparte(): void
    {
        $this->config['i18n']['locales'] = 'en, pt-BR';
        $this->config['landings']['registry'] = 'guia | Guía | article';
        $this->config['landings']['editorial']['guia']['url']['path'] = 'guia-de-uso';

        $url = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children']['guia']['children']['url']['children'];

        $this->assertSame(['base', 'path', 'path_en', 'path_pt_br'], array_keys($url));
        $this->assertSame('slug', $url['path']['type']);
        $this->assertStringContainsString('/learn/guia-de-uso', $url['base']['options']['area']);
        $this->assertStringContainsString('/guia-de-uso', $url['base']['options']['root']);
        $this->assertSame(['area', 'root'], array_keys($url['base']['options']));
    }

    public function testLaUbicacionPorDefectoEsLaDeLaEntradaEstaticaYElAreaParaLasDelPanel(): void
    {
        $this->staticEntries(['perfil' => ['label' => 'Perfil', 'jsonld' => 'person', 'base' => 'root']]);
        $this->config['landings']['registry'] = 'guia | Guía | article';

        $editorial = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children'];

        // Una instancia sin `hwe_config` disponible debe servir la landing en la ubicación que
        // declaró, no en una por defecto distinta: el frontend asume el mismo valor.
        $this->assertSame('root', $editorial['perfil']['children']['url']['children']['base']['default']);
        $this->assertSame('area', $editorial['guia']['children']['url']['children']['base']['default']);
        $this->assertSame('', $editorial['guia']['children']['url']['children']['path']['default']);
    }

    public function testLaPublicacionEsUnaCasillaPublicaYApagadaPorDefecto(): void
    {
        $this->config['landings']['registry'] = 'guia | Guía | article';

        $published = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children']['guia']['children']['published'];

        $this->assertSame('boolean', $published['type']);
        $this->assertTrue($published['public']);
        $this->assertArrayNotHasKey('default', $published);
    }

    public function testElBannerDelHeroApuntaALaLocacionDeLaLanding(): void
    {
        $this->config['landings']['registry'] = 'guia | Guía | article';

        $note = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children']['guia']['children']['hero_note'];

        $this->assertSame('link', $note['type']);
        $this->assertStringContainsString('hwe_banner', $note['url']);
        $this->assertStringContainsString('landing-guia', $note['description']);
    }

    public function testUnProductoOCategoriaYaGuardadosNoSeBorranPorFaltarEnLasOpciones(): void
    {
        $this->config['landings']['registry'] = 'guia | Guía | article';
        $this->config['landings']['editorial']['guia'] = [
            'product' => 'producto-retirado',
            'products' => ['category' => 'cat-vieja', 'manual' => 'a | b'],
        ];

        $guia = ContentSchema::inject($this->coreWithLandings())['landings']['children']['editorial']['children']['guia']['children'];

        $this->assertArrayHasKey('producto-retirado', $guia['product']['options']);
        $this->assertArrayHasKey('cat-vieja', $guia['products']['children']['category']['options']);
        $this->assertSame(['a', 'b'], array_keys($guia['products']['children']['manual']['options']));
    }

    public function testElSchemaRealAceptaLaClaveRaizYGeneraUnaVarianteDeCadaTextoPorIdioma(): void
    {
        $this->config['i18n']['locales'] = 'en, pt-BR';
        $this->config['landings']['registry'] = 'guia | Guía | article';
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(
            static fn ($schema) => ContentSchema::inject($schema)
        );

        $schema = Schema::get();

        $this->assertArrayHasKey('landings', $schema);
        $this->assertSame([], array_filter($this->logged, static fn (string $m): bool => str_contains($m, 'descartada')));
        $s1 = $schema['landings']['children']['editorial']['children']['guia']['children']['sections']['children']['s1']['children'];
        $this->assertArrayHasKey('title', $s1);
        $this->assertArrayHasKey('title_en', $s1);
        $this->assertArrayHasKey('title_pt_br', $s1);
        $this->assertArrayHasKey('body_en', $s1);
        // Lo que no es texto traducible no genera variantes.
        $this->assertArrayNotHasKey('image_en', $s1);
        $this->assertArrayNotHasKey('layout_en', $s1);
        $this->assertSame('richtext', $s1['body_pt_br']['type']);
    }

    // ── Tipos de campo ──────────────────────────────────────────────────────────

    public function testElPluginRegistraSusDosTiposYNoPisaLosDelNucleo(): void
    {
        $types = Plugin::fieldTypes(['text' => 'x']);

        $this->assertInstanceOf(LandingRegistryType::class, $types['landing_registry']);
        $this->assertInstanceOf(RegistryTypeType::class, $types['registry_type']);
        $this->assertSame('x', $types['text']);
        $this->assertSame('roto', Plugin::fieldTypes('roto'));
        $this->assertSame('landing_registry', $types['landing_registry']->id());
        $this->assertSame('registry_type', $types['registry_type']->id());
    }

    public function testElRegistroPintaLasFilasGuardadasBloqueadasYUnaPlantillaNueva(): void
    {
        $html = (new LandingRegistryType())->render([], 'hwe_config_landings_registry', 'hwe_config[landings][registry]', "guia | Guía | article\nperfil | Perfil | person", ['landings', 'registry']);

        $this->assertSame(2, substr_count($html, 'hwe-landing-registry-row--locked'));
        $this->assertStringContainsString('name="hwe_config[landings][registry][0][slug]" value="guia"', $html);
        $this->assertStringContainsString('name="hwe_config[landings][registry][1][label]" value="Perfil"', $html);
        $this->assertStringContainsString('data-next-index="2"', $html);
        $this->assertStringContainsString('__INDEX__', $html);
        // El tipo de una fila guardada no se repite aquí: lo edita su bloque.
        $this->assertSame(1, substr_count($html, 'name="hwe_config[landings][registry][__INDEX__][jsonld]"'));
        $this->assertStringNotContainsString('registry][0][jsonld]', $html);
        // Las guardadas son `readonly` (viajan en el POST), no `disabled`.
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringNotContainsString('disabled', $html);
    }

    public function testElRegistroSaneaFilasDelFormulario(): void
    {
        $type = new LandingRegistryType();

        $clean = $type->sanitize([], [
            ['slug' => 'Guia Nueva', 'label' => '<b>Guía</b> nueva', 'jsonld' => 'person'],
            ['slug' => 'guia-nueva', 'label' => 'Repetida', 'jsonld' => 'article'],
            ['slug' => '', 'label' => 'Sin id'],
            'no-es-fila',
            ['slug' => 'otra', 'label' => '', 'jsonld' => 'robot'],
        ]);

        $this->assertSame("guia-nueva | Guía nueva | person\notra | otra | article", $clean);
    }

    public function testUnaFilaSinTipoConservaElGuardadoEnVezDeVolverAArticulo(): void
    {
        $this->config['landings']['registry'] = 'perfil | Perfil | person';

        $clean = (new LandingRegistryType())->sanitize([], [
            ['slug' => 'perfil', 'label' => 'Perfil'],
            ['slug' => 'nueva', 'label' => 'Nueva'],
        ]);

        $this->assertSame("perfil | Perfil | person\nnueva | Nueva | article", $clean);
    }

    public function testElRegistroAceptaElTextoCanonicoDeWpHweSetup(): void
    {
        $type = new LandingRegistryType();

        $this->assertSame("a | A | article\nb | b | person", $type->sanitize([], "a | A | article\n\nb|  | person\na | dup | article"));
        $this->assertSame('', $type->sanitize([], null));
        $this->assertSame(FieldType::CONFIG, $type->persistence());
    }

    public function testElTipoDeLandingEscribeEnLaFilaDelRegistroYNoGuardaValorPropio(): void
    {
        $type = new RegistryTypeType();

        $html = $type->render(
            ['registry_index' => 3, 'current_value' => 'person'],
            'hwe_config_landings_editorial_perfil_jsonld_type',
            'hwe_config[landings][editorial][perfil][jsonld_type]',
            null,
            ['landings', 'editorial', 'perfil', 'jsonld_type']
        );

        $this->assertStringContainsString('name="hwe_config[landings][registry][3][jsonld]"', $html);
        $this->assertStringContainsString('<option value="person" selected>', $html);
        $this->assertSame(FieldType::SKIP, $type->sanitize([], 'person'));
    }

    public function testElGuardadoDelPanelConservaElTipoElegidoEnElBloqueDeLaLanding(): void
    {
        $this->config['landings']['registry'] = "guia | Guía | article\nperfil | Perfil | person";
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(
            static fn ($schema) => ContentSchema::inject($schema)
        );
        Filters\expectApplied('hwe_control_center_field_types')->andReturnUsing(
            static fn ($types) => Plugin::fieldTypes($types)
        );
        $schema = Schema::get();

        // El formulario envía el registro como filas (con el tipo que puso el select de cada
        // bloque) y el bloque de contenido con el mismo nombre de campo `registry_type`.
        [$public] = AdminSave::sanitizeAndSplit(
            ['landings' => $schema['landings']],
            ['landings' => [
                'registry' => [
                    ['slug' => 'guia', 'label' => 'Guía', 'jsonld' => 'person'],
                    ['slug' => 'perfil', 'label' => 'Perfil', 'jsonld' => 'person'],
                ],
                'editorial' => ['guia' => ['published' => '1', 'jsonld_type' => 'person']],
            ]]
        );

        $this->assertSame("guia | Guía | person\nperfil | Perfil | person", $public['landings']['registry']);
        // Ningún valor fantasma del campo `registry_type` en el contenido.
        $this->assertArrayNotHasKey('jsonld_type', $public['landings']['editorial']['guia']);
        $this->assertTrue($public['landings']['editorial']['guia']['published']);
    }

    // ── Banners y revalidación ──────────────────────────────────────────────────

    public function testCadaLandingTieneSuLocacionDeBanner(): void
    {
        $this->staticEntries(['perfil' => ['label' => 'Perfil']]);
        $this->config['landings']['registry'] = 'guia | Guía | article';

        $locations = BannerLocations::add(['home' => 'Inicio']);

        $this->assertSame(
            ['home' => 'Inicio', 'landing-perfil' => 'Landing · Perfil', 'landing-guia' => 'Landing · Guía'],
            $locations
        );
        $this->assertSame('roto', BannerLocations::add('roto'));
    }

    public function testRegistraElTagLandingsSinDuplicarlo(): void
    {
        $this->assertSame(['site-config', 'landings'], Revalidation::tags(['site-config']));
        $this->assertSame(['landings'], Revalidation::tags(['landings']));
        $this->assertSame(['landings'], Revalidation::tags('roto'));
    }

    public function testSoloUnGuardadoQueTocaLasLandingsRevalidaElTag(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(
            static fn ($tags) => Revalidation::tags($tags)
        );

        Revalidation::onSaved(['brand' => ['name' => 'X']]);
        Revalidation::onSaved('roto');
        $this->assertSame([], $this->posts);

        Revalidation::onSaved(['landings' => ['registry' => 'a | A | article']]);
        $this->assertCount(1, $this->posts);
        $this->assertSame('landings', json_decode($this->posts[0]['body'], true)['tag']);
    }
}
