<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Banners;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Banners\BannerTypes;
use HWE\Banners\DecorPresets;
use HWE\Banners\FieldHints;
use HWE\Banners\LegacySlide;
use HWE\Banners\Locations;
use HWE\Banners\Plugin;
use HWE\Banners\RestApi;
use HWE\Banners\Revalidation;
use HWE\Banners\Save;
use HWE\Banners\Serializer;
use HWE\Banners\SizeGuide;
use HWE\Banners\SlideTypes;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Módulo `banners` v2 (tarea 55): tipos + locaciones, unicidad por celda, serialización con
 * traducciones a N idiomas, guardado, REST, visual decorativo y compatibilidad de lectura.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BannersTest extends TestCase
{
    /** @var array<int,array<string,mixed>> post_id => metas */
    private array $meta = [];
    /** @var list<\WP_Post> posts publicados que devuelve get_posts */
    private array $posts = [];
    /** @var array<string,int> transients escritos */
    private array $transients = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            '__' => static fn ($v) => $v,
            'esc_html__' => static fn ($v) => $v,
            'esc_html' => static fn ($v) => (string) $v,
            'sanitize_key' => static fn ($v) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)),
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'esc_url_raw' => static fn ($v) => preg_match('#^(https?:)?//|^/#', (string) $v) ? (string) $v : '',
            'wp_unslash' => static fn ($v) => $v,
            'absint' => static fn ($v) => abs((int) $v),
            'wp_verify_nonce' => static fn () => 1,
            'current_user_can' => static fn () => true,
            'wp_parse_url' => static fn ($url, $c = -1) => parse_url((string) $url, $c),
            'wp_cache_get' => static fn () => false,
            'wp_cache_set' => static fn () => true,
            'wp_is_post_revision' => static fn () => false,
            'is_admin' => static fn () => false,
        ]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key, $single = false) => $this->meta[$id][$key] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$id][$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta[$id][$key]);
            return true;
        });
        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = (int) $value;
            return true;
        });
        Functions\when('get_posts')->alias(fn (array $args) => $this->posts);
        Functions\when('plugin_dir_url')->justReturn('');
        Functions\when('hwe_i18n_locales')->justReturn(['en']);

        $this->loadOwnFile('plugins/hwe-banners/hwe-banners.php');
    }

    private function post(int $id = 100): \WP_Post
    {
        $p = new \WP_Post();
        $p->ID = $id;
        $p->post_type = 'hwe_banner';
        return $p;
    }

    /** Un banner publicado con sus metas y slides. */
    private function banner(array $slides, string $type = 'carousel', string $location = 'home', int $id = 100): void
    {
        $this->posts = [$this->post($id)];
        $this->meta[$id] = [
            '_hwe_banner_type' => $type,
            '_hwe_banner_location' => $location,
            '_hwe_banner_slides' => $slides,
        ];
    }

    // --- tipos, locaciones, plantillas -----------------------------------------------------------

    public function testLosTiposPorDefectoSonLosCincoDelFramework(): void
    {
        $this->assertSame(['carousel', 'band', 'allies', 'page_hero', 'landing'], array_keys(BannerTypes::all()));
        $this->assertTrue(BannerTypes::config('carousel')['interval']);
        $this->assertFalse(BannerTypes::config('band')['interval']);
        $this->assertSame(1, BannerTypes::config('band')['max_slides']);
        $this->assertNull(BannerTypes::config('carousel')['max_slides']);
    }

    public function testUnaInstanciaAnadeTiposYNormalizaLaForma(): void
    {
        Filters\expectApplied('hwe_banners_banner_types')->andReturnUsing(static fn (array $d): array => $d + [
            'popup' => 'Ventana emergente',
            'strip' => ['label' => 'Cinta', 'max_slides' => '2', 'interval' => 0, 'locations' => ['home', 'Tienda'], 'types' => ['Default']],
            '' => 'sin slug',
        ]);

        $types = BannerTypes::all();

        $this->assertSame(['label' => 'Ventana emergente', 'description' => '', 'types' => null, 'max_slides' => null, 'interval' => true, 'locations' => ['home']], $types['popup']);
        $this->assertSame(['label' => 'Cinta', 'description' => '', 'types' => ['default'], 'max_slides' => 2, 'interval' => false, 'locations' => ['home', 'tienda']], $types['strip']);
        $this->assertArrayNotHasKey('', $types);
    }

    public function testLaLocacionPorDefectoEsHomeYUnaInstanciaAnadeLasSuyas(): void
    {
        $this->assertSame(['home' => 'Inicio'], Locations::all());

        Filters\expectApplied('hwe_banners_locations')->andReturnUsing(static fn (array $d): array => $d + ['tienda' => ['label' => 'Tienda'], 'Raro Slug!' => 'Raro']);

        $this->assertSame(['home' => 'Inicio', 'tienda' => 'Tienda', 'raroslug' => 'Raro'], Locations::all());
        $this->assertTrue(Locations::isValid('tienda'));
        $this->assertFalse(Locations::isValid('nada'));
        $this->assertSame('nada', Locations::label('nada'));
    }

    public function testUnTipoSoloAdmiteSusLocacionesValidas(): void
    {
        $this->assertTrue(BannerTypes::isLocationAllowed('carousel', 'home'));
        $this->assertFalse(BannerTypes::isLocationAllowed('carousel', 'tienda'));
        $this->assertFalse(BannerTypes::isLocationAllowed('inexistente', 'home'));
        // `page_hero` declara `store`, que el catálogo por defecto no tiene: no se admite hasta declararla.
        $this->assertFalse(BannerTypes::isLocationAllowed('page_hero', 'store'));
        $this->assertSame('store', BannerTypes::defaultLocationFor('page_hero'));
    }

    public function testLasPlantillasDeSlideCaenALasDisponiblesSiElTipoPideUnaInexistente(): void
    {
        Filters\expectApplied('hwe_banners_banner_types')->andReturnUsing(static fn (array $d): array => ['x' => ['label' => 'X', 'types' => ['fantasma']]]);

        $this->assertSame(['default'], array_keys(BannerTypes::allowedSlideTypes('x')));
        $this->assertSame('default', BannerTypes::defaultSlideTypeFor('x'));
        $this->assertTrue(BannerTypes::isSlideTypeAllowed('x', 'default'));
        $this->assertFalse(BannerTypes::isSlideTypeAllowed('x', 'fantasma'));
    }

    public function testLasPlantillasDeSlideLlevanSusCampos(): void
    {
        Filters\expectApplied('hwe_banners_slide_types')->andReturnUsing(static fn (array $d): array => $d + ['logo' => ['label' => 'Logo', 'fields' => ['Image', 'title']]]);

        $this->assertSame(['image', 'title'], SlideTypes::all()['logo']['fields']);
        $this->assertNull(SlideTypes::all()['default']['fields']);
        $this->assertSame('default', SlideTypes::defaultSlug());
    }

    // --- visual decorativo -----------------------------------------------------------------------

    public function testSinFiltroNoHayVisualesDeplantillaYSanearDaVacio(): void
    {
        $this->assertSame([], DecorPresets::all());
        $this->assertSame('', DecorPresets::defaultKey());
        $this->assertSame('', DecorPresets::sanitize('cualquiera'));
    }

    public function testLaClaveDesconocidaCaeAlPrimerPresetNuncaAUnValorInventado(): void
    {
        Filters\expectApplied('hwe_banners_decor_presets')->andReturn(['Linea-Oscuro' => 'Línea oscuro', 'color' => 'Color']);

        $this->assertSame(['linea-oscuro' => 'Línea oscuro', 'color' => 'Color'], DecorPresets::all());
        $this->assertSame('linea-oscuro', DecorPresets::defaultKey());
        $this->assertSame('color', DecorPresets::sanitize('COLOR'));
        $this->assertSame('linea-oscuro', DecorPresets::sanitize('borrado'));
    }

    public function testLosPresetsDelFixtureCompartidoSeOfrecenConLasMismasClavesYOrdenQueElFrontend(): void
    {
        // El mismo fixture lo lee `decor-presets.test.ts` (TypeScript): si una instancia declara
        // las claves en un lado y no en el otro, el selector del admin y el resolvedor discrepan.
        $fixture = json_decode((string) file_get_contents(HWE_BACKEND_DIR . '/tests/Fixtures/decor-presets.json'), true);
        $labels  = array_column($fixture['presets'], 'label', 'key');
        Filters\expectApplied('hwe_banners_decor_presets')->andReturn($labels);

        $this->assertSame(['linea-oscuro', 'linea-color', 'linea-claro'], array_keys(DecorPresets::all()));
        $this->assertSame($fixture['presets'][0]['key'], DecorPresets::defaultKey());
        $this->assertSame('linea-claro', DecorPresets::sanitize('LINEA-CLARO'));
    }

    public function testLosSlidesViejosSeLeenConLasClavesNuevas(): void
    {
        $upgraded = LegacySlide::upgrade(['mandala_mode' => 'default', 'mandala_preset' => 'a', 'mandala_svg' => 'x.svg', 'title' => 'T']);

        $this->assertEquals(['decor_mode' => 'default', 'decor_preset' => 'a', 'decor_svg' => 'x.svg', 'title' => 'T'], $upgraded);
    }

    public function testLasClavesNuevasMandanSobreLasViejas(): void
    {
        $this->assertSame(['decor_mode' => 'custom'], LegacySlide::upgrade(['mandala_mode' => 'default', 'decor_mode' => 'custom']));
        $this->assertSame(['title' => 'T'], LegacySlide::upgrade(['title' => 'T']));
    }

    // --- serialización ---------------------------------------------------------------------------

    public function testUnSlideNormalSeSirveConSuImagen(): void
    {
        $this->banner([['image' => 'https://x/a.jpg', 'title' => 'Hola', 'cta' => 'Ver', 'cta_href' => '/products']]);

        $cell = Serializer::cell('carousel', 'home', 'es');

        $this->assertSame(6000, $cell['intervalMs']);
        $this->assertCount(1, $cell['slides']);
        $this->assertSame('100-0', $cell['slides'][0]['id']);
        $this->assertSame('https://x/a.jpg', $cell['slides'][0]['image']);
        $this->assertSame('https://x/a.jpg', $cell['slides'][0]['imageMobile'], 'sin móvil, la de escritorio');
        $this->assertSame('/products', $cell['slides'][0]['ctaHref']);
    }

    public function testUnSlideSinImagenSeSirveSiTieneCopyYSeDescartaSiNoTieneNada(): void
    {
        $this->banner([
            ['image' => '', 'title' => 'Solo titular'],
            ['image' => '', 'title' => '', 'subtitle' => '', 'badge' => '', 'cta' => ''],
        ]);

        $slides = Serializer::cell('carousel', 'home', 'es')['slides'];

        $this->assertCount(1, $slides);
        $this->assertSame('Solo titular', $slides[0]['title']);
    }

    public function testElCopyDeUnCampoQueLaPlantillaNoDeclaraNoSostieneUnSlide(): void
    {
        Filters\expectApplied('hwe_banners_slide_types')->andReturnUsing(static fn (array $d): array => $d + ['solo-imagen' => ['label' => 'Solo imagen', 'fields' => ['image']]]);
        $this->banner([['type' => 'solo-imagen', 'image' => '', 'title' => 'No se pinta']]);

        $this->assertSame([], Serializer::cell('carousel', 'home', 'es')['slides']);
    }

    public function testElVisualDecorativoPorDefectoNoLlevaImagenYSirveLaClave(): void
    {
        Filters\expectApplied('hwe_banners_decor_presets')->andReturn(['uno' => 'Uno', 'dos' => 'Dos']);
        $this->banner([['decor_mode' => 'default', 'decor_preset' => 'dos', 'image' => 'https://x/a.jpg', 'title' => 'T']]);

        $slide = Serializer::cell('carousel', 'home', 'es')['slides'][0];

        $this->assertSame('', $slide['image']);
        $this->assertSame('default', $slide['decorMode']);
        $this->assertSame('dos', $slide['decorPreset']);
        $this->assertArrayNotHasKey('decorSvg', $slide);
    }

    public function testUnSvgPropioSeSirveYUnoVacioDescartaElSlide(): void
    {
        $this->banner([
            ['decor_mode' => 'custom', 'decor_svg' => 'https://x/d.svg', 'title' => 'A'],
            ['decor_mode' => 'custom', 'decor_svg' => '', 'title' => 'B'],
        ]);

        $slides = Serializer::cell('carousel', 'home', 'es')['slides'];

        $this->assertCount(1, $slides);
        $this->assertSame('https://x/d.svg', $slides[0]['decorSvg']);
    }

    public function testUnSlideGuardadoConLasClavesViejasSigueMostrandoSuVisual(): void
    {
        $this->banner([['mandala_mode' => 'custom', 'mandala_svg' => 'https://x/viejo.svg', 'title' => 'A']]);

        $this->assertSame('https://x/viejo.svg', Serializer::cell('carousel', 'home', 'es')['slides'][0]['decorSvg']);
    }

    public function testLasTraduccionesSeResuelvenCampoACampoParaCualquierIdiomaSecundario(): void
    {
        $this->banner([[
            'image' => 'https://x/es.jpg', 'title' => 'Hola', 'subtitle' => 'Sub', 'cta' => 'Ver',
            'i18n' => ['pt_br' => ['title' => 'Olá', 'subtitle' => '', 'image' => 'https://x/pt.jpg'], 'en' => ['title' => 'Hi']],
        ]]);

        $pt = Serializer::cell('carousel', 'home', 'pt_br')['slides'][0];
        $en = Serializer::cell('carousel', 'home', 'en')['slides'][0];
        $es = Serializer::cell('carousel', 'home', 'es')['slides'][0];

        $this->assertSame(['Olá', 'Sub', 'Ver', 'https://x/pt.jpg'], [$pt['title'], $pt['subtitle'], $pt['cta'], $pt['image']]);
        $this->assertSame(['Hi', 'https://x/es.jpg'], [$en['title'], $en['image']]);
        $this->assertSame('Hola', $es['title'], 'el idioma por defecto no usa overrides');
    }

    public function testLosFiltrosDeSlideVisibleYDeSlidePuedenOcultarOMutar(): void
    {
        $this->banner([['title' => 'A'], ['title' => 'B']]);
        Filters\expectApplied('hwe_banners_slide_visible')->andReturnUsing(static fn ($visible, array $slide): bool => 'B' !== $slide['title']);
        Filters\expectApplied('hwe_banners_slide')->andReturnUsing(static fn (array $slide): array => $slide + ['extra' => 1]);

        $slides = Serializer::cell('carousel', 'home', 'es')['slides'];

        $this->assertCount(1, $slides);
        $this->assertSame(1, $slides[0]['extra']);
    }

    public function testSinBannerPublicadoLaCeldaEstaVacia(): void
    {
        $this->posts = [];

        $this->assertSame(['intervalMs' => 6000, 'slides' => []], Serializer::cell('carousel', 'home', 'es'));
    }

    public function testElIntervaloSaleDelBanner(): void
    {
        $this->banner([['title' => 'A']]);
        $this->meta[100]['_hwe_banner_interval_ms'] = 4000;

        $this->assertSame(4000, Serializer::cell('carousel', 'home', 'es')['intervalMs']);
    }

    public function testPorLocacionSoloSeConsultanLosTiposQueLaAdmiten(): void
    {
        $this->banner([['title' => 'A']]);

        $all = Serializer::forLocation('home', 'es');

        $this->assertSame(['carousel', 'band', 'allies', 'page_hero', 'landing'], array_keys($all));
        $this->assertCount(1, $all['carousel']['slides']);
        $this->assertSame([], $all['page_hero']['slides'], '`page_hero` no admite `home`');
    }

    // --- REST ------------------------------------------------------------------------------------

    public function testUnTipoInexistenteEsUn404(): void
    {
        $r = RestApi::getOne(new \WP_REST_Request(['type' => 'fantasma']));

        $this->assertSame(404, $r->get_status());
        $this->assertSame('invalid_banner_type', $r->get_data()['code']);
    }

    public function testUnTipoValidoEnUnaLocacionQueNoAdmiteEsVacioPeroNoEsError(): void
    {
        $r = RestApi::getOne(new \WP_REST_Request(['type' => 'page_hero', 'location' => 'home']));

        $this->assertSame(200, $r->get_status());
        $this->assertSame(['intervalMs' => 6000, 'slides' => []], $r->get_data());
    }

    public function testSinLocacionOConUnaInvalidaSeUsaLaPrimeraDeclarada(): void
    {
        $this->banner([['title' => 'A']]);

        $a = RestApi::getOne(new \WP_REST_Request(['type' => 'carousel']))->get_data();
        $b = RestApi::getOne(new \WP_REST_Request(['type' => 'carousel', 'location' => 'inventada']))->get_data();

        $this->assertCount(1, $a['slides']);
        $this->assertSame($a, $b);
    }

    public function testElIdiomaDeLaPeticionSeSaneaYCaeAlPorDefecto(): void
    {
        $this->banner([['title' => 'Hola', 'i18n' => ['en' => ['title' => 'Hi']]]]);

        $en = RestApi::getAll(new \WP_REST_Request(['lang' => 'EN!']))->get_data();
        $none = RestApi::getAll(new \WP_REST_Request())->get_data();

        $this->assertSame('Hi', $en['carousel']['slides'][0]['title']);
        $this->assertSame('Hola', $none['carousel']['slides'][0]['title']);
    }

    // --- guardado --------------------------------------------------------------------------------

    private function save(array $post, int $id = 100): void
    {
        $_POST = ['hwe_banner_nonce' => 'x'] + $post;
        Save::save($id);
    }

    public function testElTipoSeFijaAlPrimerGuardadoYNoCambia(): void
    {
        $this->save(['hwe_banner_type' => 'band', 'hwe_banner_location' => 'home']);
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_location' => 'home']);

        $this->assertSame('band', $this->meta[100]['_hwe_banner_type']);
    }

    public function testUnTipoInvalidoNoGuardaNada(): void
    {
        $this->save(['hwe_banner_type' => 'fantasma']);

        $this->assertArrayNotHasKey(100, $this->meta);
    }

    public function testUnaLocacionNoAdmitidaCaeALaPorDefectoDelTipo(): void
    {
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_location' => 'tienda']);

        $this->assertSame('home', $this->meta[100]['_hwe_banner_location']);
    }

    public function testSoloElCarruselGuardaIntervaloConUnMinimo(): void
    {
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_interval_ms' => '200']);
        $this->assertSame(1000, $this->meta[100]['_hwe_banner_interval_ms']);

        $this->save(['hwe_banner_type' => 'band'], 101);
        $this->assertArrayNotHasKey('_hwe_banner_interval_ms', $this->meta[101]);
    }

    public function testUnSlideSinNadaSeDescartaYSeAvisa(): void
    {
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_slides' => [
            ['title' => 'Con titular'],
            ['title' => '', 'image' => ''],
            'no soy un slide',
        ]]);

        $this->assertCount(1, $this->meta[100]['_hwe_banner_slides']);
        $this->assertSame(1, $this->transients['hwe_banner_discarded_100']);
    }

    public function testElTopeDeSlidesDelTipoRecortaYAvisa(): void
    {
        $this->save(['hwe_banner_type' => 'band', 'hwe_banner_slides' => [
            ['title' => 'A', 'order' => 2], ['title' => 'B', 'order' => 1],
        ]]);

        $this->assertSame(['B'], array_column($this->meta[100]['_hwe_banner_slides'], 'title'), 'gana el de menor orden');
        $this->assertSame(1, $this->transients['hwe_banner_discarded_100']);
    }

    public function testElVisualDecorativoEsExcluyenteConLaImagen(): void
    {
        Filters\expectApplied('hwe_banners_decor_presets')->andReturn(['uno' => 'Uno']);
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_slides' => [
            ['title' => 'A', 'decor_mode' => 'default', 'decor_preset' => 'nope', 'image' => 'https://x/a.jpg', 'decor_svg' => 'https://x/d.svg'],
            ['title' => 'B', 'decor_mode' => 'custom', 'decor_svg' => 'https://x/d.svg', 'image' => 'https://x/a.jpg'],
            ['title' => 'C', 'decor_mode' => 'custom', 'decor_svg' => 'https://x/d.png'],
            ['title' => 'D', 'decor_mode' => 'none', 'decor_svg' => 'https://x/d.svg', 'image' => 'https://x/a.jpg'],
        ]]);

        $saved = array_column($this->meta[100]['_hwe_banner_slides'], null, 'title');

        $this->assertSame(['', 'uno', ''], [$saved['A']['image'], $saved['A']['decor_preset'], $saved['A']['decor_svg']], 'clave inválida → la primera');
        $this->assertSame(['', 'https://x/d.svg'], [$saved['B']['image'], $saved['B']['decor_svg']]);
        $this->assertArrayNotHasKey('C', $saved, 'un SVG que no termina en .svg descarta el slide');
        $this->assertSame(['https://x/a.jpg', ''], [$saved['D']['image'], $saved['D']['decor_svg']]);
        $this->assertSame(1, $this->transients['hwe_banner_discarded_100']);
    }

    public function testLosEnlacesInternosSePermitenYLosProtocoloRelativoNo(): void
    {
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_slides' => [[
            'title' => 'A', 'cta_href' => '/products', 'cta2_href' => '//evil.example/x', 'link' => 'javascript:alert(1)',
        ]]]);

        $slide = $this->meta[100]['_hwe_banner_slides'][0];

        $this->assertSame('/products', $slide['cta_href']);
        $this->assertSame('//evil.example/x', $slide['cta2_href'], 'pasa por esc_url_raw, no como ruta interna');
        $this->assertSame('', $slide['link'], 'un esquema peligroso se descarta');
    }

    public function testLasTraduccionesSeGuardanPorCadaIdiomaSecundarioDelSitio(): void
    {
        Functions\when('hwe_i18n_locales')->justReturn(['en', 'pt_br']);
        $this->save(['hwe_banner_type' => 'carousel', 'hwe_banner_slides' => [[
            'title' => 'Hola',
            'i18n' => ['pt_br' => ['title' => 'Olá', 'cta' => 'Ver'], 'fr' => ['title' => 'Salut']],
        ]]]);

        $i18n = $this->meta[100]['_hwe_banner_slides'][0]['i18n'];

        $this->assertSame(['en', 'pt_br'], array_keys($i18n), 'solo los idiomas del sitio');
        $this->assertSame(['Olá', 'Ver'], [$i18n['pt_br']['title'], $i18n['pt_br']['cta']]);
        $this->assertSame('', $i18n['en']['title']);
    }

    public function testSinNonceONiPermisosNoSeGuardaNada(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);
        $this->save(['hwe_banner_type' => 'carousel']);
        $this->assertArrayNotHasKey(100, $this->meta);
    }

    // --- idiomas, revalidación, guías ------------------------------------------------------------

    public function testLosIdiomasSecundariosSalenDeHweContentI18nOSonNinguno(): void
    {
        Functions\when('hwe_i18n_locales')->justReturn(['en', 'PT_BR', 'en', '']);

        $this->assertSame(['en', 'pt_br'], Plugin::secondaryLocales());
    }

    public function testCambiarUnBannerPurgaLaCacheYRevalidaElTagBanners(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn (array $core): array => $core);
        $bodies = [];
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$bodies): array {
            $bodies[] = $args['body'];
            return [];
        });
        Functions\expect('wp_cache_flush_group')->once()->with('hwe_banners');

        Revalidation::onChange(100);

        $this->assertCount(1, $bodies);
        $this->assertSame('banners', json_decode($bodies[0], true)['tag']);
    }

    public function testLasGuiasDeMedidasYDeCamposSonFiltrablesYSaneadas(): void
    {
        $this->assertSame(['carousel', 'band', 'allies', 'page_hero', 'landing'], array_keys(SizeGuide::all()));
        $this->assertStringContainsString('Recomendado: 28–40 caracteres.', FieldHints::for('title'));
        $this->assertSame('', FieldHints::for('inventado'));

        Filters\expectApplied('hwe_banners_size_guide')->andReturn(['band' => ['summary' => 'S', 'rows' => [['label' => 'L'], 'roto']], 5 => 'roto']);

        $this->assertSame(['band' => ['summary' => 'S', 'rows' => [['label' => 'L', 'desktop' => '', 'mobile' => '', 'notes' => '']]]], SizeGuide::all());
    }
}
