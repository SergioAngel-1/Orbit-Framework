<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Geo;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `hwe-geo` (tarea 52): paquetes por país, `GET hwe/v1/geo` y el tag ISR `geo`.
 *
 * El registro guarda estado por proceso (avisos ya emitidos, constantes): cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GeoTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,mixed> */
    private array $config = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs(['wp_json_encode' => static fn ($v) => json_encode($v)]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('get_option')->alias(fn (string $key, $default = false) => 'hwe_config' === $key ? $this->config : $default);
        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');
        $this->loadOwnFile('mu-plugins/hwe-geo/rest.php');
    }

    /** @return array<string,mixed> Un paquete válido mínimo, para sustituir campos en cada caso. */
    private function validPack(array $over = []): array
    {
        return array_replace([
            'country'        => 'AR',
            'name'           => 'Argentina',
            'dial_code'      => '+54',
            'division_label' => 'Provincia',
            'address'        => ['division' => 'required', 'postcode' => 'required', 'city_division' => true],
            'divisions'      => [['code' => 'AR-C', 'name' => 'Buenos Aires (CABA)'], ['code' => 'AR-B', 'name' => 'Buenos Aires']],
            'cities'         => [['name' => 'La Plata', 'division' => 'AR-B']],
            'document_types' => [['code' => 'DNI', 'label' => 'DNI', 'pattern' => '^\d{7,8}$']],
        ], $over);
    }

    private function withPacks(mixed ...$packs): void
    {
        Filters\expectApplied('hwe_geo_packs')->andReturnUsing(static fn (array $core): array => [...array_values($core), ...$packs]);
    }

    public function testElNucleoTraeColombiaYElGenerico(): void
    {
        $packs = hwe_geo_packs();

        $this->assertSame(['CO', 'generic'], array_values(array_intersect(['CO', 'generic'], array_keys($packs))));
        $co = $packs['CO'];
        $this->assertCount(33, $co['divisions']);
        $this->assertSame('+57', $co['dial_code']);
        $this->assertSame(['CC', 'CE', 'PP', 'NIT'], array_column($co['document_types'], 'code'));
        $this->assertTrue($co['address']['city_division']);
        $this->assertSame([], $this->logged, 'los paquetes del núcleo no deben generar avisos');
    }

    public function testCadaCiudadDeColombiaApuntaAUnaDivisionQueExiste(): void
    {
        $co    = hwe_geo_packs()['CO'];
        $codes = array_column($co['divisions'], 'code');

        $this->assertCount(48, $co['cities']);
        foreach ($co['cities'] as $city) {
            $this->assertContains($city['division'], $codes, $city['name']);
        }
        $this->assertSame('CO-DC', array_column($co['cities'], 'division', 'name')['Bogotá']);
    }

    public function testElGenericoEsTextoLibreSinDocumentosNiPrefijo(): void
    {
        $generic = hwe_geo_packs()['generic'];

        $this->assertSame('free', $generic['address']['division']);
        $this->assertFalse($generic['address']['city_division']);
        $this->assertSame([], $generic['divisions']);
        $this->assertSame([], $generic['cities']);
        $this->assertSame([], $generic['document_types']);
        $this->assertSame('', $generic['dial_code']);
    }

    public function testLosPatronesDeDocumentoDeColombiaValidanLosNumerosEsperados(): void
    {
        $patterns = array_column(hwe_geo_packs()['CO']['document_types'], 'pattern', 'code');
        $matches  = static fn (string $code, string $number): bool => 1 === preg_match('~' . $patterns[$code] . '~', $number);

        $this->assertTrue($matches('CC', '1012345678'));
        $this->assertFalse($matches('CC', '0123456789'), 'una cédula no empieza por 0');
        $this->assertFalse($matches('CC', '123'));
        $this->assertTrue($matches('NIT', '9001234567'));
        $this->assertFalse($matches('NIT', '90012345'));
        $this->assertTrue($matches('PP', 'AB12345'));
    }

    public function testUnPaisConPaqueteNoCaeAlGenerico(): void
    {
        $resolved = hwe_geo_resolve(' co ');

        $this->assertSame('CO', $resolved['pack']['country']);
        $this->assertFalse($resolved['fallback']);
        $this->assertSame([], $this->logged);
    }

    public function testUnPaisSinPaqueteCaeAlGenericoYSeRegistraUnaVez(): void
    {
        $first  = hwe_geo_resolve('ES');
        $second = hwe_geo_resolve('ES');

        $this->assertSame('generic', $first['pack']['country']);
        $this->assertTrue($first['fallback']);
        $this->assertSame('ES', $first['requested']);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('[hwe] geo:', $this->logged[0]);
        $this->assertStringContainsString('«ES»', $this->logged[0]);
    }

    public function testPedirGenericOVacioTambienEsReserva(): void
    {
        $this->assertTrue(hwe_geo_resolve('generic')['fallback']);
        $this->assertTrue(hwe_geo_resolve('')['fallback']);
    }

    public function testUnaInstanciaAnadeUnPaquetePorFiltro(): void
    {
        $this->withPacks($this->validPack());

        $resolved = hwe_geo_resolve('AR');

        $this->assertFalse($resolved['fallback']);
        $this->assertSame('+54', $resolved['pack']['dial_code']);
        $this->assertSame('DNI', $resolved['pack']['document_types'][0]['code']);
        $this->assertSame([], $this->logged);
    }

    public function testUnaInstanciaPuedeSustituirElPaqueteDelNucleo(): void
    {
        $this->withPacks($this->validPack(['country' => 'CO', 'name' => 'Colombia (instancia)', 'dial_code' => '+57']));

        $this->assertSame('Colombia (instancia)', hwe_geo_resolve('CO')['pack']['name']);
    }

    public function testUnPaqueteMalFormadoSeDescartaSinTirarLosDemas(): void
    {
        $this->withPacks(
            $this->validPack(['country' => 'argentina']),
            $this->validPack(['country' => 'BR', 'name' => '']),
            'no soy un paquete',
            $this->validPack(['country' => 'CL', 'name' => 'Chile']),
        );

        $packs = hwe_geo_packs();

        $this->assertArrayNotHasKey('argentina', $packs);
        $this->assertArrayNotHasKey('BR', $packs);
        $this->assertArrayHasKey('CL', $packs);
        $this->assertArrayHasKey('CO', $packs);
        $this->assertCount(3, $this->logged);
    }

    public function testUnaCiudadConUnaDivisionInexistenteSeOmite(): void
    {
        $this->withPacks($this->validPack(['cities' => [
            ['name' => 'La Plata', 'division' => 'AR-B'],
            ['name' => 'Atlantis', 'division' => 'AR-ZZ'],
            ['name' => 'La Plata', 'division' => 'AR-C'],
        ]]));

        $cities = hwe_geo_packs()['AR']['cities'];

        $this->assertSame([['name' => 'La Plata', 'division' => 'AR-B']], $cities);
        $this->assertNotSame([], $this->logged);
    }

    public function testUnPatronDeDocumentoQueNoCompilaSeOmite(): void
    {
        $this->withPacks($this->validPack(['document_types' => [
            ['code' => 'DNI', 'label' => 'DNI', 'pattern' => '^\d{7,8}$'],
            ['code' => 'MAL', 'label' => 'Roto', 'pattern' => '^(\d+'],
            ['code' => 'VACIO', 'label' => 'Vacío', 'pattern' => ''],
        ]]));

        $this->assertSame(['DNI'], array_column(hwe_geo_packs()['AR']['document_types'], 'code'));
    }

    public function testUnaDivisionRepetidaOSinNombreSeOmite(): void
    {
        $this->withPacks($this->validPack(['divisions' => [
            ['code' => 'AR-B', 'name' => 'Buenos Aires'],
            ['code' => 'AR-B', 'name' => 'Duplicada'],
            ['code' => 'AR-X', 'name' => ''],
        ], 'cities' => []]));

        $this->assertSame([['code' => 'AR-B', 'name' => 'Buenos Aires']], hwe_geo_packs()['AR']['divisions']);
    }

    public function testUnPrefijoInvalidoSeDejaVacioYSigueSiendoUsable(): void
    {
        $this->withPacks($this->validPack(['dial_code' => '54']));

        $this->assertSame('', hwe_geo_packs()['AR']['dial_code']);
        $this->assertCount(1, $this->logged);
    }

    public function testElFiltroQueNoDevuelveUnArrayDejaSoloElNucleo(): void
    {
        Filters\expectApplied('hwe_geo_packs')->andReturn('roto');

        $packs = hwe_geo_packs();

        $this->assertSame(['generic', 'CO'], array_keys($packs));
        $this->assertCount(1, $this->logged);
    }

    public function testElPaqueteDeReservaSiempreExisteAunqueElFiltroLoQuite(): void
    {
        Filters\expectApplied('hwe_geo_packs')->andReturn([]);

        $this->assertArrayHasKey('generic', hwe_geo_packs());
    }

    public function testElPaisDeLaTiendaSaleDeLaConfigEnMayusculas(): void
    {
        $this->config = ['ecommerce' => ['country' => ' co ']];

        $this->assertSame('CO', hwe_geo_store_country());
    }

    public function testSinPaisGuardadoSeUsaElDelEsquema(): void
    {
        $this->config = [];

        $this->assertSame('ES', hwe_geo_store_country());
    }

    public function testElRestEntregaElPaqueteEnCamelCaseYCacheable(): void
    {
        $this->config = ['ecommerce' => ['country' => 'CO']];

        $response = hwe_geo_handle(new \WP_REST_Request());
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('CO', $data['country']);
        $this->assertFalse($data['fallback']);
        $this->assertSame('+57', $data['dialCode']);
        $this->assertSame('Departamento', $data['divisionLabel']);
        $this->assertTrue($data['address']['cityDivision']);
        $this->assertCount(33, $data['divisions']);
        $this->assertCount(4, $data['documentTypes']);
        $this->assertArrayNotHasKey('dial_code', $data, 'el contrato público es camelCase');
        $this->assertStringContainsString('s-maxage=300', $response->get_headers()['Cache-Control']);
    }

    public function testElRestDeUnPaisSinPaqueteMarcaLaReserva(): void
    {
        $this->config = ['ecommerce' => ['country' => 'ES']];

        $data = hwe_geo_handle(new \WP_REST_Request(['country' => 'CO']))->get_data();

        $this->assertSame('generic', $data['country']);
        $this->assertTrue($data['fallback']);
        $this->assertSame('ES', $data['requested']);
    }

    public function testElRestIgnoraUnPaisPedidoPorParametro(): void
    {
        $this->config = ['ecommerce' => ['country' => 'ES']];

        $data = hwe_geo_handle(new \WP_REST_Request(['country' => 'CO']))->get_data();

        $this->assertSame('generic', $data['country'], 'el endpoint no sirve para sondear otros paquetes');
    }

    public function testLaRutaEsPublicaYSeRegistraEnElNamespaceHwe(): void
    {
        Functions\expect('register_rest_route')
            ->once()
            ->with('hwe/v1', '/geo', \Mockery::on(static fn (array $args): bool => 'GET' === $args['methods'] && '__return_true' === $args['permission_callback']));

        hwe_geo_register_route();
    }

    public function testDeclaraElTagGeoSinDuplicarlo(): void
    {
        $this->assertSame(['site-config', 'geo'], hwe_geo_revalidation_tags(['site-config']));
        $this->assertSame(['site-config', 'geo'], hwe_geo_revalidation_tags(['site-config', 'geo']));
        $this->assertSame(['geo'], hwe_geo_revalidation_tags('roto'));
    }

    public function testElGuardadoDelControlCenterRevalidaElTagGeo(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn (array $core): array => [...$core, 'geo']);
        $posts = [];
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$posts): array {
            $posts[] = $args['body'];
            return [];
        });

        hwe_geo_on_config_saved();

        $this->assertCount(1, $posts);
        $this->assertSame('geo', json_decode($posts[0], true)['tag']);
    }

    public function testElPaqueteCODeclaraAbreviaturasDeViaYTokensDescartados(): void
    {
        $geocoding = hwe_geo_packs()['CO']['geocoding'];

        $this->assertSame('Calle', $geocoding['abbreviations']['Cl']);
        $this->assertSame('Carrera', $geocoding['abbreviations']['Kra']);
        $this->assertContains('BIS', $geocoding['drop_tokens']);
        $this->assertContains('#', $geocoding['drop_tokens']);
        $this->assertContains('Norte', $geocoding['drop_tokens']);
        $this->assertSame([], $this->logged);
    }

    public function testElGenericoNoTieneAbreviaturasNiTokens(): void
    {
        $this->assertSame(['abbreviations' => [], 'drop_tokens' => []], hwe_geo_packs()['generic']['geocoding']);
    }

    public function testUnPaqueteSinReglasDeGeocodificacionLasTieneVacias(): void
    {
        $this->withPacks($this->validPack());

        $this->assertSame(['abbreviations' => [], 'drop_tokens' => []], hwe_geo_packs()['AR']['geocoding']);
    }

    public function testLasAbreviaturasMalFormadasSeOmitenYSeRegistran(): void
    {
        $this->withPacks($this->validPack(['geocoding' => [
            'abbreviations' => ['Cl' => 'Calle', 'Roto' => 123, '' => 'Vacía'],
            'drop_tokens'   => ['#', '', 7],
        ]]));

        $geocoding = hwe_geo_packs()['AR']['geocoding'];

        $this->assertSame(['Cl' => 'Calle'], $geocoding['abbreviations']);
        $this->assertSame(['#'], $geocoding['drop_tokens']);
        $this->assertNotSame([], $this->logged);
    }

    public function testElRestExponeLaGeocodificacionEnCamelCase(): void
    {
        $this->config = ['ecommerce' => ['country' => 'CO']];

        $data = hwe_geo_handle(new \WP_REST_Request())->get_data();

        $this->assertSame('Calle', $data['geocoding']['abbreviations']['Cl']);
        $this->assertContains('#', $data['geocoding']['dropTokens']);
        $this->assertArrayNotHasKey('drop_tokens', $data['geocoding']);
    }

    public function testElCargadorEnlazaLosHooks(): void
    {
        $this->assertNotFalse(has_action('rest_api_init', 'hwe_geo_register_route'));
        $this->assertNotFalse(has_filter('hwe_revalidation_tags', 'hwe_geo_revalidation_tags'));
        $this->assertNotFalse(has_action('hwe_control_center_saved', 'hwe_geo_on_config_saved'));
    }
}
