<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ProductLanding;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ProductLanding\Fields;
use HWE\ProductLanding\Graphql;
use HWE\ProductLanding\Revalidation;
use HWE\ProductLanding\Save;
use HWE\ProductLanding\Translations;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-shipping.php';

/** Producto de WooCommerce con el meta en memoria (lo único que lee y escribe `Save`). */
class FakeLandingProduct extends \WC_Product
{
    /** @var array<string,mixed> */
    public array $meta = [];
    public int $saves = 0;

    public function get_meta(string $key = '', bool $single = true): mixed
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data(string $key): void
    {
        unset($this->meta[$key]);
    }

    public function save(): int
    {
        ++$this->saves;
        return $this->get_id();
    }
}

/**
 * Módulo `hwe-product-landing` (tarea 56): esquema y filtro de íconos, registro de campos
 * traducibles, GraphQL (publicación, traducción por idioma, slugs publicados), guardado (saneado,
 * idiomas del sitio, tope de filas, guarda de cambios) y revalidación del tag `products`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductLandingTest extends TestCase
{
    /** @var list<string> */
    private array $locales = ['en'];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var list<array{url:string,body:string}> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->locales = ['en'];
        $this->postMeta = [];
        $this->posts = [];
        $_POST = [];

        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_textarea_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_key' => static fn ($v) => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)),
            'wp_kses_post' => static fn ($v) => (string) $v,
            'esc_url_raw' => static fn ($v) => (string) $v,
            'wp_unslash' => static fn ($v) => $v,
            'wp_verify_nonce' => static fn () => 1,
            'current_user_can' => static fn () => true,
            'wp_is_post_revision' => static fn () => false,
            'plugin_dir_url' => static fn () => '',
            'register_activation_hook' => static fn () => null,
        ]);
        Functions\when('hwe_i18n_locales')->alias(fn (): array => $this->locales);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key = '', bool $single = true) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->posts[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];
            return [];
        });
        Functions\when('error_log')->justReturn(true);

        $this->loadOwnFile('plugins/hwe-product-landing/hwe-product-landing.php');
    }

    // ── Íconos ──────────────────────────────────────────────────────────────────

    public function testIconsSonElSetNeutroPorDefecto(): void
    {
        $this->assertSame(Fields::DEFAULT_ICONS, Fields::icons());
        $this->assertCount(12, Fields::icons());
    }

    public function testIconsLaInstanciaAmpliaElSetYSeDescartaLoInvalido(): void
    {
        Filters\expectApplied('hwe_product_landing_icons')->once()->andReturnUsing(
            static fn (array $icons): array => $icons + [
                'rocket' => 'Cohete',
                'Bad Key' => 'Clave inválida',
                'vacio' => '',
                0 => 'Numérica',
            ]
        );

        $icons = Fields::icons();

        $this->assertSame('Cohete', $icons['rocket']);
        $this->assertArrayNotHasKey('Bad Key', $icons);
        $this->assertArrayNotHasKey('vacio', $icons);
        $this->assertArrayNotHasKey(0, $icons);
        $this->assertArrayHasKey('leaf', $icons);
    }

    public function testIconsUnFiltroQueDevuelveBasuraVuelveAlSetNeutro(): void
    {
        Filters\expectApplied('hwe_product_landing_icons')->once()->andReturn('no es una lista');
        $this->assertSame(Fields::DEFAULT_ICONS, Fields::icons());
    }

    public function testIconsUnFiltroQueLoDejaVacioVuelveAlSetNeutro(): void
    {
        Filters\expectApplied('hwe_product_landing_icons')->once()->andReturn(['Bad Key' => 'x']);
        $this->assertSame(Fields::DEFAULT_ICONS, Fields::icons());
    }

    // ── Esquema ─────────────────────────────────────────────────────────────────

    public function testElEsquemaDistingueLosCamposTraducibles(): void
    {
        $scalars = Fields::translatableScalars();

        $this->assertContains('hero_subtitle', $scalars);
        $this->assertContains('closing_text', $scalars);
        $this->assertNotContains('published', $scalars);
        $this->assertNotContains('video_url', $scalars);
        $this->assertNotContains('hero_image', $scalars);

        $rows = Fields::translatableRowFields();
        $this->assertSame(['name', 'note'], $rows['components']);
        $this->assertSame(['title', 'text'], $rows['qualities']);
        $this->assertSame(['heading', 'body'], $rows['advice']);
    }

    public function testNingunCampoDeFilaEsRichtext(): void
    {
        foreach (Fields::REPEATERS as $spec) {
            foreach ($spec['row_fields'] as $field) {
                $this->assertNotSame('richtext', $field['type']);
            }
        }
    }

    public function testTodosLosCamposDelFormularioExistenEnElEsquemaYViceversa(): void
    {
        $rendered = [];
        $source = (string) file_get_contents(HWE_WP_CONTENT_DIR . '/plugins/hwe-product-landing/includes/Render.php');
        preg_match_all("/'fields' => \\[([^\\]]*)\\]/", $source, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([a-z0-9_]+)'/", $block, $keys);
            array_push($rendered, ...$keys[1]);
        }

        // Un campo que existe en el esquema pero no en el formulario se borraría en cada guardado
        // (`Save` guarda '' y `stageMeta` lo elimina): `video_poster` ya se perdió así una vez.
        $this->assertEqualsCanonicalizing(array_keys(Fields::SCALAR_FIELDS), $rendered);
    }

    // ── Traducciones ────────────────────────────────────────────────────────────

    public function testLosIdiomasSonLosSecundariosDelSitioSaneados(): void
    {
        $this->locales = ['en', 'pt_br', 'PT BR!'];
        $this->assertSame(['en', 'pt_br', 'ptbr'], Translations::locales());
        $this->assertSame('PT-BR', Translations::label('pt_br'));
    }

    public function testRegistraLosCamposTraduciblesDeLaLandingEnElTipoProduct(): void
    {
        $registry = Translations::registry(['product' => ['fields' => ['name' => ['label' => 'Nombre']]]]);

        $fields = $registry['product']['fields'];
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('landing_hero_subtitle', $fields);
        $this->assertSame('html', $fields['landing_edu_body']['input']);
        $this->assertSame('textarea', $fields['landing_seo_description']['input']);
        $this->assertSame('text', $fields['landing_seo_title']['input']);
        $this->assertArrayNotHasKey('landing_published', $fields);
        $this->assertArrayNotHasKey('landing_hero_image', $fields);
    }

    public function testElRegistroDeOtraFormaPasaIntacto(): void
    {
        $this->assertSame('roto', Translations::registry('roto'));
        $this->assertSame(['post' => []], Translations::registry(['post' => []]));
    }

    // ── GraphQL ─────────────────────────────────────────────────────────────────

    public function testLandingEsNulaSalvoQueEsteExactamentePublicada(): void
    {
        $this->postMeta[7] = ['_hwe_landing_hero_subtitle' => 'Hola'];
        $this->assertNull(Graphql::landing(7));

        $this->postMeta[7]['_hwe_landing_published'] = '0';
        $this->assertNull(Graphql::landing(7));

        $this->postMeta[7]['_hwe_landing_published'] = 'true';
        $this->assertNull(Graphql::landing(7));

        $this->postMeta[7]['_hwe_landing_published'] = '1';
        $landing = Graphql::landing(7);
        $this->assertSame('Hola', $landing['heroSubtitle']);
        $this->assertSame(7, $landing['_id']);
    }

    public function testLosBooleanosSinGuardarValenSuDefectoDelMolde(): void
    {
        $this->postMeta[7] = ['_hwe_landing_published' => '1'];

        $landing = Graphql::landing(7);

        $this->assertTrue($landing['sectionEduEnabled']);
        $this->assertTrue($landing['sectionQualitiesEnabled']);
        $this->assertTrue($landing['sectionAdviceEnabled']);
        $this->assertFalse($landing['sectionVideoEnabled']);
        $this->assertNull($landing['heroImage']);
    }

    public function testUnBooleanoGuardadoManda(): void
    {
        $this->postMeta[7] = [
            '_hwe_landing_published' => '1',
            '_hwe_landing_section_edu_enabled' => '0',
            '_hwe_landing_section_video_enabled' => '1',
        ];

        $landing = Graphql::landing(7);

        $this->assertFalse($landing['sectionEduEnabled']);
        $this->assertTrue($landing['sectionVideoEnabled']);
    }

    public function testLasFilasSalenPlanasConSuTraduccionAparte(): void
    {
        $this->postMeta[7] = [
            '_hwe_landing_published' => '1',
            '_hwe_landing_qualities' => [
                ['icon' => 'leaf', 'image' => '', 'title' => 'Calma', 'text' => 'Texto', 'i18n' => ['en' => ['title' => 'Calm', 'text' => '']]],
                'no-es-fila',
            ],
        ];

        $qualities = Graphql::landing(7)['qualities'];

        $this->assertCount(1, $qualities);
        $this->assertSame('Calma', $qualities[0]['title']);
        $this->assertNull($qualities[0]['image']);
        $this->assertSame(['title' => 'Calm', 'text' => null], Graphql::rowTranslation('qualities', $qualities[0], 'en'));
    }

    public function testLaTraduccionDeUnaFilaDeUnIdiomaAjenoEsNula(): void
    {
        $row = ['_i18n' => ['fr' => ['title' => 'Calme']]];

        $this->assertNull(Graphql::rowTranslation('qualities', $row, 'fr'));
        $this->assertNull(Graphql::rowTranslation('qualities', $row, ''));
        $this->assertSame(['title' => null, 'text' => null], Graphql::rowTranslation('qualities', $row, 'en'));
    }

    public function testLaTraduccionEscalarLeeLaMetaDelIdioma(): void
    {
        $this->locales = ['en', 'pt_br'];
        $this->postMeta[7] = [
            '_hwe_landing_hero_subtitle_en' => 'English subtitle',
            '_hwe_landing_hero_subtitle_pt_br' => '',
            '_hwe_landing_hero_subtitle_fr' => 'Sous-titre',
        ];

        $en = Graphql::scalarTranslation(7, 'en');
        $this->assertSame('English subtitle', $en['heroSubtitle']);
        $this->assertNull($en['seoTitle']);

        // `pt-BR` se normaliza igual que en el resto del sistema; vacío = sin traducción.
        $this->assertNull(Graphql::scalarTranslation(7, 'pt-BR')['heroSubtitle']);
        // Un idioma que el sitio no declara no abre la meta del francés.
        $this->assertNull(Graphql::scalarTranslation(7, 'fr'));
        $this->assertArrayNotHasKey('published', $en);
    }

    public function testRegistraLosTiposYElCampoLandingDeProduct(): void
    {
        $types = [];
        $fieldsRegistered = [];
        Functions\when('register_graphql_object_type')->alias(function (string $name, array $config) use (&$types): void {
            $types[$name] = $config;
        });
        Functions\when('register_graphql_field')->alias(function (string $type, string $field, array $config) use (&$fieldsRegistered): void {
            $fieldsRegistered[$type][$field] = $config;
        });

        Graphql::registerTypes();

        foreach (['ProductLanding', 'ProductLandingTranslation', 'ProductLandingComponent', 'ProductLandingQuality', 'ProductLandingAdvice', 'ProductLandingComponentTranslation', 'ProductLandingQualityTranslation', 'ProductLandingAdviceTranslation'] as $type) {
            $this->assertArrayHasKey($type, $types, $type);
        }
        $this->assertSame('ProductLanding', $fieldsRegistered['Product']['landing']['type']);
        $this->assertArrayHasKey('hweProductLandingsPublished', $fieldsRegistered['RootQuery']);

        // La traducción solo lleva los campos traducibles; la fila, su `translation(locale)`.
        $translated = array_keys($types['ProductLandingTranslation']['fields']);
        $this->assertContains('heroSubtitle', $translated);
        $this->assertNotContains('published', $translated);
        $this->assertArrayHasKey('translation', $types['ProductLanding']['fields']);
        $this->assertArrayHasKey('translation', $types['ProductLandingQuality']['fields']);

        // Los resolvedores de traducción responden al idioma pedido.
        $this->postMeta[7] = ['_hwe_landing_hero_subtitle_en' => 'Hi'];
        $resolver = $types['ProductLanding']['fields']['translation']['resolve'];
        $this->assertSame('Hi', $resolver(['_id' => 7], ['locale' => 'en'])['heroSubtitle']);
        $this->assertNull($resolver([], ['locale' => 'en']));

        $product = new \stdClass();
        $product->databaseId = 7;
        $this->postMeta[7]['_hwe_landing_published'] = '1';
        $this->assertSame(7, $fieldsRegistered['Product']['landing']['resolve']($product)['_id']);
        $this->assertNull($fieldsRegistered['Product']['landing']['resolve'](new \stdClass()));
    }

    public function testLosSlugsPublicadosSoloPidenProductosPublicadosConLaMeta(): void
    {
        $args = null;
        Functions\when('get_posts')->alias(function (array $a) use (&$args): array {
            $args = $a;
            $make = static function (string $slug): \WP_Post {
                $p = new \WP_Post();
                $p->post_name = $slug;
                return $p;
            };
            return [$make('uno'), $make(''), $make('dos')];
        });
        $fieldsRegistered = [];
        Functions\when('register_graphql_object_type')->justReturn(null);
        Functions\when('register_graphql_field')->alias(function (string $type, string $field, array $config) use (&$fieldsRegistered): void {
            $fieldsRegistered[$type][$field] = $config;
        });
        Graphql::registerTypes();

        $slugs = $fieldsRegistered['RootQuery']['hweProductLandingsPublished']['resolve']();

        $this->assertSame(['uno', 'dos'], $slugs);
        $this->assertSame('product', $args['post_type']);
        $this->assertSame('publish', $args['post_status']);
        $this->assertSame('_hwe_landing_published', $args['meta_key']);
        $this->assertSame('1', $args['meta_value']);
    }

    // ── Guardado ────────────────────────────────────────────────────────────────

    /** Prepara una petición de guardado del producto 5 y devuelve el producto simulado. */
    private function request(array $landing, array $rows = []): FakeLandingProduct
    {
        $product = new FakeLandingProduct(5);
        Functions\when('wc_get_product')->justReturn($product);
        $_POST['hwe_product_landing_nonce'] = 'n';
        $_POST['hwe_landing'] = $landing;
        foreach ($rows as $key => $list) {
            $_POST['hwe_landing_' . $key] = $list;
        }
        return $product;
    }

    public function testSinNonceNoSeToca(): void
    {
        $product = $this->request(['hero_subtitle' => 'Hola']);
        unset($_POST['hwe_product_landing_nonce']);
        Functions\when('wp_verify_nonce')->justReturn(false);

        Save::save(5);

        $this->assertSame([], $product->meta);
        $this->assertSame(0, $product->saves);
    }

    public function testSaneaCadaEscalarSegunSuTipo(): void
    {
        $product = $this->request([
            'published' => '1',
            'hero_subtitle' => '  <b>Hola</b> ',
            'accent_1' => '#AABBCC',
            'accent_2' => 'rojo',
            'video_url' => 'https://youtu.be/abc',
            'seo_description' => "Línea<script>x</script>",
        ]);

        Save::save(5);

        $this->assertSame('1', $product->meta['_hwe_landing_published']);
        $this->assertSame('Hola', $product->meta['_hwe_landing_hero_subtitle']);
        $this->assertSame('#aabbcc', $product->meta['_hwe_landing_accent_1']);
        $this->assertArrayNotHasKey('_hwe_landing_accent_2', $product->meta);
        $this->assertSame('https://youtu.be/abc', $product->meta['_hwe_landing_video_url']);
        $this->assertStringNotContainsString('<script>', $product->meta['_hwe_landing_seo_description']);
        $this->assertSame(1, $product->saves);
    }

    public function testUnBooleanoNuncaGuardadoNoCuentaComoCambio(): void
    {
        $product = $this->request(['published' => '0', 'section_video_enabled' => '0']);

        Save::save(5);

        $this->assertSame(0, $product->saves);
        $this->assertSame([], $this->posts);
    }

    public function testSoloSeGuardanLasTraduccionesDeLosIdiomasDelSitio(): void
    {
        $this->locales = ['en'];
        $product = $this->request([], [
            'qualities' => [
                ['icon' => 'leaf', 'title' => 'Calma', 'text' => '', 'i18n' => [
                    'en' => ['title' => 'Calm', 'text' => 'Text'],
                    'fr' => ['title' => 'Calme', 'text' => 'Texte'],
                ]],
            ],
        ]);

        Save::save(5);

        $row = $product->meta['_hwe_landing_qualities'][0];
        $this->assertSame(['en'], array_keys($row['i18n']));
        $this->assertSame('Calm', $row['i18n']['en']['title']);
    }

    public function testUnaFilaVaciaEnElIdiomaPorDefectoSeDescartaAunqueTengaTraduccion(): void
    {
        $product = $this->request([], [
            'advice' => [
                ['heading' => '', 'body' => '', 'i18n' => ['en' => ['heading' => 'Only', 'body' => 'English']]],
                ['heading' => '¿Cómo?', 'body' => 'Así', 'i18n' => []],
            ],
        ]);

        Save::save(5);

        $rows = $product->meta['_hwe_landing_advice'];
        $this->assertCount(1, $rows);
        $this->assertSame('¿Cómo?', $rows[0]['heading']);
    }

    public function testElTopeDeFilasRecortaLasSobrantes(): void
    {
        $rows = [];
        for ($i = 1; $i <= 14; ++$i) {
            $rows[] = ['name' => "Componente $i", 'icon' => 'leaf', 'note' => '', 'image' => ''];
        }
        $product = $this->request([], ['components' => $rows]);

        Save::save(5);

        $this->assertCount(Fields::REPEATERS['components']['max_rows'], $product->meta['_hwe_landing_components']);
        $this->assertSame(11, Fields::REPEATERS['components']['max_rows']);
    }

    public function testElIconoDebeSerDelSetYLaInstanciaLoAmplia(): void
    {
        $product = $this->request([], [
            'qualities' => [
                ['icon' => 'rocket', 'title' => 'Sin ícono válido', 'text' => '', 'image' => ''],
            ],
        ]);
        Save::save(5);
        $this->assertSame('', $product->meta['_hwe_landing_qualities'][0]['icon']);

        Filters\expectApplied('hwe_product_landing_icons')->andReturnUsing(
            static fn (array $icons): array => $icons + ['rocket' => 'Cohete']
        );
        $product = $this->request([], [
            'qualities' => [
                ['icon' => 'rocket', 'title' => 'Con ícono', 'text' => '', 'image' => ''],
            ],
        ]);
        Save::save(5);
        $this->assertSame('rocket', $product->meta['_hwe_landing_qualities'][0]['icon']);
    }

    public function testUnCambioPideLaRevalidacionDelCatalogo(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        $this->registerTag();
        $product = $this->request(['published' => '1']);

        Save::save(5);

        $this->assertSame(1, $product->saves);
        $this->assertCount(1, $this->posts);
        $this->assertStringEndsWith('/api/revalidate', $this->posts[0]['url']);
        $this->assertSame('products', json_decode($this->posts[0]['body'], true)['tag']);
    }

    // ── Revalidación ────────────────────────────────────────────────────────────

    /** El núcleo solo envía los tags del filtro: se simula lo que haría `Revalidation::register()`. */
    private function registerTag(): void
    {
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(
            static fn ($tags) => Revalidation::tags($tags)
        );
    }

    public function testRegistraElTagProductsSinDuplicarlo(): void
    {
        $this->assertSame(['site-config', 'products'], Revalidation::tags(['site-config']));
        $this->assertSame(['products'], Revalidation::tags(['products']));
        $this->assertSame(['products'], Revalidation::tags('roto'));
    }

    public function testGuardarUnProductoConLandingPublicadaRevalida(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        $this->registerTag();
        $this->postMeta[9]['_hwe_landing_published'] = '1';
        $this->postMeta[10]['_hwe_landing_published'] = '0';

        Revalidation::onProductSaved(10);
        $this->assertSame([], $this->posts, 'una landing sin publicar no revalida el catálogo');

        Revalidation::onProductSaved(9);
        Revalidation::onProductSaved(9);
        $this->assertCount(1, $this->posts, 'se envía una sola vez por petición');
    }

    public function testUnAutoguardadoNoRevalida(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        define('DOING_AUTOSAVE', true);
        $this->postMeta[9]['_hwe_landing_published'] = '1';

        Revalidation::onProductSaved(9);

        $this->assertSame([], $this->posts);
    }
}
