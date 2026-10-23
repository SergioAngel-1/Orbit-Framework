<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\PostTypes;
use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Rates\City;
use HWE\Shipping\Rates\DeliveryOption;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Repositorio de ciudades (`Cities`) y sus valores (`GeoPoint`, `DeliveryOption`, `City`).
 * Las ciudades se leen de los posts publicados del CPT: se simulan con `get_posts` y
 * `get_post_meta`, y la caché de objetos con un almacén en memoria.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CitiesTest extends TestCase
{
    /** @var array<int,array{title:string,status:string,meta:array<string,string>}> */
    private array $posts = [];
    /** @var array<string,mixed> */
    private array $cache = [];
    private int $postsQueries = 0;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            '__' => static fn ($v) => $v,
            'remove_accents' => static fn (string $s) => strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N']),
            'wc_get_price_decimals' => static fn () => 2,
        ]);
        Functions\when('wp_cache_get')->alias(fn (string $key, string $group = '') => $this->cache[$group . ':' . $key] ?? false);
        Functions\when('wp_cache_set')->alias(function (string $key, $value, string $group = '', int $ttl = 0): bool {
            $this->cache[$group . ':' . $key] = $value;
            return true;
        });
        Functions\when('wp_cache_delete')->alias(function (string $key, string $group = ''): bool {
            unset($this->cache[$group . ':' . $key]);
            return true;
        });
        Functions\when('get_the_title')->alias(static fn (\WP_Post $post): string => $post->post_title);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->posts[$id]['meta'][$key] ?? '');
        Functions\when('get_posts')->alias(fn (array $args): array => $this->query($args));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'plugin_dir_url' => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin' => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');

    }

    /** @param array<string,mixed> $args @return \WP_Post[] */
    private function query(array $args): array
    {
        $this->postsQueries++;
        $status = $args['post_status'] ?? 'publish';
        $exclude = $args['exclude'] ?? [];
        $out = [];
        foreach ($this->posts as $id => $row) {
            if ($row['status'] !== $status || in_array($id, $exclude, true)) {
                continue;
            }
            $post = new \WP_Post();
            $post->ID = $id;
            $post->post_title = $row['title'];
            $post->post_status = $row['status'];
            $out[] = $post;
        }
        return $out;
    }

    /** @param array<string,string> $meta */
    private function post(int $id, string $title, array $meta = [], string $status = 'publish'): void
    {
        $this->posts[$id] = ['title' => $title, 'status' => $status, 'meta' => $meta];
    }

    /** @param list<array<string,mixed>> $options */
    private function fixedCity(int $id, string $title, array $options): void
    {
        $this->post($id, $title, [
            '_hwe_shipping_city_type' => 'fixed',
            '_hwe_shipping_city_options' => json_encode($options),
        ]);
    }

    /** @param list<array<string,mixed>> $options */
    private function distanceCity(int $id, string $title, array $options, string $lat = '37.3891', string $lng = '-5.9845', string $coverage = '30'): void
    {
        $this->post($id, $title, [
            '_hwe_shipping_city_type' => 'distance',
            '_hwe_shipping_city_options' => json_encode($options),
            '_hwe_shipping_city_origin_address' => 'Calle de referencia 1',
            '_hwe_shipping_city_origin_lat' => $lat,
            '_hwe_shipping_city_origin_lng' => $lng,
            '_hwe_shipping_city_origin_label' => 'Calle de referencia 1, Sevilla',
            '_hwe_shipping_city_coverage_km' => $coverage,
        ]);
    }

    /** @return array<string,mixed> */
    private function fixedOption(string $id, string $name, float $amount, bool $free = false): array
    {
        return ['id' => $id, 'name' => $name, 'free_eligible' => $free, 'amount' => $amount, 'price_per_km' => null, 'min_cost' => 0.0, 'max_cost' => 0.0];
    }

    /** @return array<string,mixed> */
    private function kmOption(string $id, string $name, float $perKm, float $min = 0.0, float $max = 0.0, bool $free = false): array
    {
        return ['id' => $id, 'name' => $name, 'free_eligible' => $free, 'amount' => null, 'price_per_km' => $perKm, 'min_cost' => $min, 'max_cost' => $max];
    }

    // --- lectura de ciudades ---------------------------------------------------------------------

    public function testLeeUnaCiudadFijaConSusOpciones(): void
    {
        $this->fixedCity(1, 'Madrid', [
            $this->fixedOption('estandar1', 'Estándar', 8.0, true),
            $this->fixedOption('express12', 'Express', 15.5),
        ]);

        $city = Cities::forCity('Madrid');

        $this->assertInstanceOf(City::class, $city);
        $this->assertSame(1, $city->postId());
        $this->assertSame('Madrid', $city->name());
        $this->assertSame('fixed', $city->type());
        $this->assertNull($city->origin());
        $this->assertCount(2, $city->options());
        $this->assertSame('estandar1', $city->options()[0]->id());
        $this->assertSame(8.0, $city->options()[0]->amount());
        $this->assertTrue($city->options()[0]->isFreeEligible());
        $this->assertSame(15.5, $city->options()[1]->amount());
        $this->assertFalse($city->options()[1]->isFreeEligible());
    }

    public function testLeeUnaCiudadPorDistanciaConSuReferencia(): void
    {
        $this->distanceCity(2, 'Sevilla', [$this->kmOption('estandar2', 'Estándar', 1000.0, 2000.0, 0.0)]);

        $city = Cities::forCity('Sevilla');

        $this->assertInstanceOf(City::class, $city);
        $this->assertSame('distance', $city->type());
        $this->assertTrue($city->isDistance());
        $this->assertNotNull($city->origin());
        $this->assertSame(37.3891, $city->origin()->lat());
        $this->assertSame(-5.9845, $city->origin()->lng());
        $this->assertSame('Calle de referencia 1, Sevilla', $city->originLabel());
        $this->assertSame(30.0, $city->coverageKm());
        $this->assertSame(1000.0, $city->options()[0]->pricePerKm());
        $this->assertSame(2000.0, $city->options()[0]->minCost());
        $this->assertSame(0.0, $city->options()[0]->maxCost(), '0 = sin límite');
        $this->assertNull($city->options()[0]->amount());
    }

    public function testCoincideConMayusculasTildesYEspaciosDistintos(): void
    {
        $this->fixedCity(3, 'Córdoba', [$this->fixedOption('estandar3', 'Estándar', 5.0)]);

        foreach (['córdoba', 'CORDOBA', '  Cordoba ', 'Córdoba', 'CÓRDOBA'] as $variant) {
            $city = Cities::forCity($variant);
            $this->assertNotNull($city, "«{$variant}» debería casar con Córdoba");
            $this->assertSame(3, $city->postId());
        }
        $this->assertNull(Cities::forCity('Cordova'));
        $this->assertNull(Cities::forCity('   '));
    }

    public function testUnTituloConGuionSeComparaSinElTextoTipograficoDeWordPress(): void
    {
        // `get_the_title` pasa el título por `wptexturize`: « - » sale como raya «&#8211;».
        \Brain\Monkey\Functions\when('get_the_title')->alias(static fn (\WP_Post $post): string => str_replace(' - ', ' &#8211; ', $post->post_title));
        $this->fixedCity(17, 'Villa - Norte', [$this->fixedOption('estandar17', 'Estándar', 5.0)]);

        $this->assertSame(17, Cities::forCity('Villa - Norte')?->postId());
        $this->assertSame('Villa - Norte', Cities::forCity('villa - norte')?->name());
    }

    public function testLosEspaciosDoblesEntrePalabrasNoDistinguenLaCiudad(): void
    {
        $this->fixedCity(16, 'Puerto  Real', [$this->fixedOption('estandar16', 'Estándar', 5.0)]);

        $this->assertSame(16, Cities::forCity('puerto real')->postId());
        $this->assertSame(16, Cities::forCity(' PUERTO   REAL ')->postId());
    }

    public function testUnaCiudadSinTipoNoSeDevuelveYSiApareceEnLegacy(): void
    {
        $this->post(4, 'Pamplona', ['_hwe_shipping_rate_amount' => '5', '_hwe_shipping_rate_free_eligible' => '1']);
        $this->post(5, 'Gijón', ['_hwe_shipping_rate_amount' => '7'], 'draft');

        $this->assertNull(Cities::forCity('Pamplona'));
        $this->assertSame([4], Cities::legacyPostIds(), 'los borradores del modelo anterior no avisan');
        $this->assertSame([], Cities::all());
    }

    public function testUnaCiudadConDatosRotosSeOmiteYSeRegistra(): void
    {
        // Tipo distance sin coordenadas de referencia: no es una ciudad válida.
        $this->post(6, 'Vitoria', [
            '_hwe_shipping_city_type' => 'distance',
            '_hwe_shipping_city_options' => json_encode([$this->kmOption('estandar6', 'Estándar', 900.0)]),
            '_hwe_shipping_city_origin_lat' => 'NaN',
            '_hwe_shipping_city_origin_lng' => '',
            '_hwe_shipping_city_coverage_km' => '20',
        ]);

        $this->assertNull(Cities::forCity('Vitoria'));
        $this->assertSame([], Cities::legacyPostIds(), 'no es del modelo anterior: tiene tipo');
        $this->assertStringContainsString('[hwe] shipping:', $this->logged[0] ?? '');
    }

    public function testDosRegistrosDeLaMismaCiudadSoloDejanUnaYSeRegistra(): void
    {
        $this->fixedCity(7, 'Bilbao', [$this->fixedOption('estandar7', 'Estándar', 4.0)]);
        $this->fixedCity(8, 'BILBAO ', [$this->fixedOption('estandar8', 'Estándar', 9.0)]);

        $city = Cities::forCity('bilbao');

        $this->assertSame(7, $city->postId());
        $this->assertCount(1, Cities::all());
        $this->assertStringContainsString('repite', $this->logged[0] ?? '');
    }

    // --- caché -----------------------------------------------------------------------------------

    public function testLaCacheSeInvalidaConClearCache(): void
    {
        $this->fixedCity(9, 'Madrid', [$this->fixedOption('estandar9', 'Estándar', 8.0)]);

        Cities::all();
        Cities::forCity('Madrid');
        $this->assertSame(1, $this->postsQueries, 'la segunda lectura sale de caché');

        Cities::clearCache();
        Cities::all();
        $this->assertSame(2, $this->postsQueries, 'tras clearCache se vuelve a leer');
    }

    // --- consultas derivadas ---------------------------------------------------------------------

    public function testLasCiudadesConEnvioGratisSeDerivanDeSusOpciones(): void
    {
        $this->fixedCity(10, 'Madrid', [$this->fixedOption('estandar10', 'Estándar', 8.0, true)]);
        $this->fixedCity(11, 'Sevilla', [$this->fixedOption('estandar11', 'Estándar', 12.0, false)]);
        $this->fixedCity(12, 'Bilbao', [
            $this->fixedOption('estandar12', 'Estándar', 5.0, false),
            $this->fixedOption('express12', 'Express', 9.0, true),
        ]);

        $this->assertSame(['Bilbao', 'Madrid'], Cities::freeEligibleCityNames(), 'basta una opción marcada');
        $this->assertSame(['madrid' => true, 'sevilla' => false, 'bilbao' => true], Cities::freeEligibilityByCity());
    }

    public function testHasDistanceCitiesSoloSiHayUnaCiudadPorDistancia(): void
    {
        $this->fixedCity(13, 'Madrid', [$this->fixedOption('estandar13', 'Estándar', 8.0)]);
        $this->assertFalse(Cities::hasDistanceCities());

        $this->distanceCity(14, 'Sevilla', [$this->kmOption('estandar14', 'Estándar', 900.0)]);
        Cities::clearCache(); // lo hace el hook save_post al guardar la ciudad
        $this->assertTrue(Cities::hasDistanceCities());
    }

    public function testElNombreRepetidoEnOtroPostCuentaComoTomadoSinContarElPropio(): void
    {
        $this->fixedCity(15, 'Málaga', [$this->fixedOption('estandar15', 'Estándar', 8.0)]);

        $this->assertTrue(Cities::isNameTaken('malaga', 99));
        $this->assertFalse(Cities::isNameTaken('malaga', 15), 'el propio post no cuenta');
        $this->assertFalse(Cities::isNameTaken('Granada', 99));
    }

    // --- valores ---------------------------------------------------------------------------------

    /** @return array<string,array{0:mixed,1:mixed}> */
    public static function coordenadasBasura(): array
    {
        return [
            'NaN' => [NAN, 2.0],
            'infinito' => [40.0, INF],
            'cadena no numérica' => ['cuarenta', -3.7],
            'cadena vacía' => ['', -3.7],
            'null' => [null, -3.7],
            'booleano' => [true, -3.7],
            'notación científica desbordada' => ['1e400', -3.7],
            'latitud fuera de rango' => [90.5, 10.0],
            'latitud negativa fuera de rango' => [-91, 10.0],
            'longitud fuera de rango' => [10.0, 180.5],
            'punto nulo 0,0' => ['0', '0'],
            'arreglo' => [[40.0], -3.7],
        ];
    }

    #[DataProvider('coordenadasBasura')]
    public function testUnaCoordenadaBasuraNoSeConstruye(mixed $lat, mixed $lng): void
    {
        $this->assertNull(GeoPoint::tryFrom($lat, $lng));
    }

    public function testUnaCoordenadaValidaSeConstruyeConNumerosOCadenas(): void
    {
        $point = GeoPoint::tryFrom('40.4168', -3.7038);

        $this->assertNotNull($point);
        $this->assertSame(40.4168, $point->lat());
        $this->assertSame(-3.7038, $point->lng());
        $this->assertNotNull(GeoPoint::tryFrom(90, -180), 'los límites son válidos');
    }

    public function testUnaOpcionConIdNoValidoNoSeConstruye(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryOption("ID con espacios\n", 'Estándar', false, 5.0, null);
    }

    public function testLaOpcionRechazaMinimoMayorQueMaximoSiAmbosSonPositivos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryOption('abcd1234', 'Estándar', false, null, 900.0, 500.0, 100.0);
    }

    public function testLaOpcionNoAceptaPreciosNegativos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryOption('abcd1234', 'Estándar', false, -1.0, null);
    }

    public function testUnNuevoIdEsCortoEnMinusculasYDistinto(): void
    {
        $ids = [DeliveryOption::newId(), DeliveryOption::newId()];

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{4,32}$/D', $id);
        }
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function testUnaCiudadSinOpcionesNoSeConstruye(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new City(1, 'Madrid', 'fixed', [], null, '', 0.0);
    }

    public function testUnaCiudadPorDistanciaSinRadioPositivoNoSeConstruye(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new City(1, 'Sevilla', 'distance', [new DeliveryOption('abcd1234', 'Estándar', false, null, 900.0)], new GeoPoint(37.3, -5.9), '', 0.0);
    }
}
