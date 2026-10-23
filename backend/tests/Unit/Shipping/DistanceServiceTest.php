<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\DistanceService;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Distance\Haversine;
use HWE\Shipping\Distance\Provider;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Tests\TestCase;
use HWE\Tests\Unit\Shipping\Support\ScriptedProvider;
use HWE\Tests\Unit\Shipping\Support\ThrowingProvider;
use HWE\Tests\Unit\Shipping\Support\WpShippingFake;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Servicio de distancias (`DistanceService`): caché, respaldo a línea recta, geocodificación con
 * caída a Nominatim y la regla de «nunca falla» de `between`. Los proveedores de mapas son
 * `ScriptedProvider` (implementan la interfaz real, sin red); Nominatim sí pasa por el HTTP
 * simulado para comprobar la caída de verdad.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DistanceServiceTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-del-servicio-9876';

    private WpShippingFake $wp;

    protected function setUp(): void
    {
        parent::setUp();

        define('HWE_SECRETS_KEY', 'clave-de-cifrado-de-pruebas-servicio');

        $this->wp = new WpShippingFake();
        $this->wp->install();

        Functions\stubs([
            'plugin_dir_url'           => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin'                 => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');

        $this->wp->options['hwe_config'] = [
            'ecommerce'    => ['country' => 'CO'],
            'integrations' => ['maps_provider' => 'google_maps'],
            'shipping'     => ['distance_method' => 'maps', 'distance_cache_ttl_hours' => 2],
        ];
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => self::KEY]]);
    }

    /** Ajusta una clave de `shipping` (o `integrations`) del Control Center de prueba. */
    private function set(string $section, string $key, mixed $value): void
    {
        $this->wp->options['hwe_config'][$section][$key] = $value;
    }

    /** Registra un proveedor de mapas de prueba bajo el id `google_maps` (el que elige la config). */
    private function mapsProvider(Provider $provider): void
    {
        $this->wp->filterProviders(static function (array $providers) use ($provider): array {
            $providers[$provider->id()] = $provider;
            return $providers;
        });
    }

    private function google(mixed $distance = null, mixed $geocode = [], mixed $reverse = null): ScriptedProvider
    {
        return new ScriptedProvider(
            'google_maps',
            $distance ?? new DistanceResult(4.35, 11, 'google_maps'),
            $geocode,
            $reverse,
        );
    }

    private function origen(): GeoPoint
    {
        return new GeoPoint(4.609712, -74.081712);
    }

    private function destino(): GeoPoint
    {
        return new GeoPoint(4.7110, -74.0721);
    }

    public function testLaSegundaLlamadaIdenticaSaleDeLaCache(): void
    {
        $provider = $this->google();
        $this->mapsProvider($provider);

        $first  = DistanceService::between($this->origen(), $this->destino());
        $second = DistanceService::between($this->origen(), $this->destino());

        $this->assertSame(1, $provider->distanceCalls);
        $this->assertEqualsWithDelta($first->km(), $second->km(), 0.0001);
        $this->assertSame('google_maps', $second->provider());
    }

    public function testLaClaveDeCacheRedondeaA4DecimalesYDependeDelModo(): void
    {
        $provider = $this->google();
        $this->mapsProvider($provider);

        DistanceService::between($this->origen(), $this->destino());
        // Diferencia en el quinto decimal: la misma clave, sale de caché.
        DistanceService::between(new GeoPoint(4.609714, -74.081714), $this->destino());
        $this->assertSame(1, $provider->distanceCalls);

        $this->set('shipping', 'distance_travel_mode', 'walking');
        DistanceService::between($this->origen(), $this->destino());

        $this->assertSame(2, $provider->distanceCalls, 'cambiar el modo no sale de la caché anterior');
        $keys = array_column($this->wp->transientWrites, 'key');
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function testLaClaveDeCacheCambiaConElIncrementoDeLaLineaRecta(): void
    {
        $this->set('shipping', 'distance_method', 'straight_line');

        DistanceService::between($this->origen(), $this->destino());
        $this->set('shipping', 'distance_straight_line_increment', 50);
        DistanceService::between($this->origen(), $this->destino());

        $keys = array_column($this->wp->transientWrites, 'key');
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function testElTtlNormalSigueLasHorasDeConfiguracionYElDeRespaldoSonQuinceMinutos(): void
    {
        $this->mapsProvider($this->google());
        DistanceService::between($this->origen(), $this->destino());
        $this->assertSame(2 * 3600, $this->wp->transientWrites[0]['ttl']);

        $this->mapsProvider($this->google(new \WP_Error('provider_rate_limited', 'cuota')));
        $this->set('shipping', 'distance_travel_mode', 'walking');
        $result = DistanceService::between($this->origen(), $this->destino());

        $this->assertTrue($result->fallback());
        $this->assertSame(15 * 60, $this->wp->transientWrites[1]['ttl']);
    }

    public function testUnProveedorQueFallaDaRespaldoMarcadoConElMotivo(): void
    {
        $this->mapsProvider($this->google(new \WP_Error('provider_rate_limited', 'cuota agotada')));

        $result   = DistanceService::between($this->origen(), $this->destino());
        $expected = Haversine::estimate($this->origen(), $this->destino(), 30, 'driving');

        $this->assertTrue($result->fallback());
        $this->assertSame('provider_rate_limited', $result->fallbackReason());
        $this->assertSame('straight_line', $result->provider());
        $this->assertEqualsWithDelta($expected->km(), $result->km(), 0.0001);
        $this->assertGreaterThan(0.0, $result->km(), 'nunca un coste de 0 por error');
    }

    public function testElRespaldoSeRegistraEnElLogSinLaClave(): void
    {
        $this->mapsProvider($this->google(new \WP_Error('provider_unavailable', 'caída')));

        DistanceService::between($this->origen(), $this->destino());

        $this->assertNotEmpty($this->wp->logged);
        $this->assertStringContainsString('[hwe] shipping:', $this->wp->logged[0]);
        $this->assertStringContainsString('provider_unavailable', $this->wp->logged[0]);
        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(self::KEY, $line);
        }
    }

    public function testElMetodoLineaRectaNoLlamaAHttpNiAlProveedorParaLasDistancias(): void
    {
        $this->set('shipping', 'distance_method', 'straight_line');
        $provider = $this->google();
        $this->mapsProvider($provider);

        $result = DistanceService::between($this->origen(), $this->destino());

        $this->assertSame(0, $provider->distanceCalls);
        $this->assertSame([], $this->wp->requests);
        $this->assertFalse($result->fallback());
        $this->assertSame('straight_line', $result->provider());
    }

    public function testElMetodoMapasSinClaveSeMarcaComoRespaldoNoConfigurado(): void
    {
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => '']]);
        $provider = $this->google();
        $this->mapsProvider($provider);

        $result = DistanceService::between($this->origen(), $this->destino());

        $this->assertSame(0, $provider->distanceCalls);
        $this->assertTrue($result->fallback());
        $this->assertSame('not_configured', $result->fallbackReason());
    }

    public function testUnProveedorQueSeQuitaDelFiltroCaeAlRespaldo(): void
    {
        $this->wp->filterProviders(static fn (array $providers): array => []);

        $result = DistanceService::between($this->origen(), $this->destino());

        $this->assertTrue($result->fallback());
        $this->assertSame('provider_unavailable', $result->fallbackReason());
    }

    public function testElFiltroDeProveedoresAceptaSoloProveedoresValidos(): void
    {
        $extra = new ScriptedProvider('extra', new DistanceResult(1.0, 1, 'extra'));
        $this->wp->filterProviders(static fn (array $providers): array => $providers + ['extra' => $extra, 'roto' => 'no soy un proveedor']);

        $providers = DistanceService::providers();

        $this->assertArrayHasKey('extra', $providers);
        $this->assertArrayNotHasKey('roto', $providers);
        $this->assertArrayHasKey('straight_line', $providers);
        $this->assertContainsOnlyInstancesOf(Provider::class, $providers);
    }

    public function testLaGeocodificacionNormalizaLaConsultaConElPaqueteDelPais(): void
    {
        $provider = $this->google(geocode: [new GeocodeResult(new GeoPoint(4.6, -74.08), 'Calle 45 23 12, Bogotá', 'ROOFTOP')]);
        $this->mapsProvider($provider);

        $results = DistanceService::geocode('Cl 45 A BIS Sur # 23 B - 12 C Norte, Apto 301');

        $this->assertIsArray($results);
        $this->assertSame(['Calle 45 23 12'], $provider->geocodeQueries);
        $this->assertSame('ROOFTOP', $results[0]->precision());
    }

    public function testLaGeocodificacionCaeANominatimSiElProveedorDeMapasFalla(): void
    {
        $this->mapsProvider($this->google(geocode: new \WP_Error('provider_unauthorized', 'clave rechazada')));
        $this->wp->respond(200, [['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1, Bogotá', 'type' => 'street']]);

        $results = DistanceService::geocode('Calle 1 Bogotá');

        $this->assertIsArray($results);
        $this->assertSame('Calle 1, Bogotá', $results[0]->label());
        $this->assertStringContainsString('nominatim.openstreetmap.org/search', $this->wp->sent('GET')[0]['url']);
        $this->assertStringContainsString('provider_unauthorized', implode(' ', $this->wp->logged));
    }

    public function testLaGeocodificacionDirectaSeCachea7DiasYLosErroresNoSeCachean(): void
    {
        $provider = $this->google(geocode: [new GeocodeResult(new GeoPoint(4.6, -74.08), 'Calle 1', 'ROOFTOP')]);
        $this->mapsProvider($provider);

        DistanceService::geocode('Calle 1 Bogotá');
        DistanceService::geocode('Calle 1 Bogotá');

        $this->assertCount(1, $provider->geocodeQueries, 'la segunda sale de caché');
        $this->assertSame(7 * DAY_IN_SECONDS, $this->wp->transientWrites[0]['ttl']);

        $writes = count($this->wp->transientWrites);
        $failing = $this->google(geocode: new \WP_Error('provider_unavailable', 'caída'));
        $this->mapsProvider($failing);
        $this->wp->respond(200, []);

        $result = DistanceService::geocode('Otra calle');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('geocoding_no_results', $result->get_error_code());
        $this->assertCount($writes, $this->wp->transientWrites, 'un error no se cachea');
    }

    public function testLaGeocodificacionInversaSeCacheaYCaeANominatim(): void
    {
        $this->mapsProvider($this->google(reverse: new \WP_Error('provider_unavailable', 'caída')));
        $this->wp->respond(200, ['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1, Bogotá']);

        $first  = DistanceService::reverse(new GeoPoint(4.6, -74.08));
        $second = DistanceService::reverse(new GeoPoint(4.6, -74.08));

        $this->assertInstanceOf(GeocodeResult::class, $first);
        $this->assertSame('Calle 1, Bogotá', $first->label());
        $this->assertSame('Calle 1, Bogotá', $second->label());
        $this->assertCount(1, $this->wp->sent('GET'), 'la segunda inversa sale de caché');
        $this->assertSame(7 * DAY_IN_SECONDS, $this->wp->transientWrites[0]['ttl']);
    }

    public function testUnProveedorQueLanzaUnaExcepcionCaeAlRespaldoSinRomperElCalculo(): void
    {
        $this->mapsProvider(new ThrowingProvider('google_maps'));

        $result = DistanceService::between($this->origen(), $this->destino());

        $this->assertTrue($result->fallback());
        $this->assertSame('provider_unavailable', $result->fallbackReason());
        $this->assertSame('straight_line', $result->provider());
        $this->assertGreaterThan(0.0, $result->km());
        $this->assertStringContainsString('RuntimeException', implode(' ', $this->wp->logged));
        $this->assertStringContainsString('[hwe] shipping:', implode(' ', $this->wp->logged));
        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(ThrowingProvider::MESSAGE, $line, 'el mensaje de la excepción no se registra');
        }
    }

    public function testUnaExcepcionDelProveedorDeGeocodificacionCaeANominatim(): void
    {
        $this->mapsProvider(new ThrowingProvider('google_maps'));
        $this->wp->respond(200, [['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1, Bogotá', 'type' => 'street']]);

        $results = DistanceService::geocode('Calle 1 Bogotá');

        $this->assertIsArray($results);
        $this->assertSame('Calle 1, Bogotá', $results[0]->label());
        $this->assertStringContainsString('RuntimeException', implode(' ', $this->wp->logged));
        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(ThrowingProvider::MESSAGE, $line);
        }
    }

    public function testUnaExcepcionDelProveedorDeInversaCaeANominatim(): void
    {
        $this->mapsProvider(new ThrowingProvider('google_maps'));
        $this->wp->respond(200, ['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1, Bogotá']);

        $result = DistanceService::reverse(new GeoPoint(4.6, -74.08));

        $this->assertInstanceOf(GeocodeResult::class, $result);
        $this->assertSame('Calle 1, Bogotá', $result->label());
        $this->assertStringContainsString('RuntimeException', implode(' ', $this->wp->logged));
        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(ThrowingProvider::MESSAGE, $line);
        }
    }

    public function testLaGeocodificacionNuncaUsaLaClaveDeMapasEnUnLog(): void
    {
        $this->mapsProvider($this->google(geocode: new \WP_Error('provider_rate_limited', 'cuota')));
        $this->wp->respond(429, []);

        DistanceService::geocode('Calle 1');

        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(self::KEY, $line);
        }
        $this->assertStringNotContainsString(self::KEY, serialize($this->wp->requests));
    }
}
