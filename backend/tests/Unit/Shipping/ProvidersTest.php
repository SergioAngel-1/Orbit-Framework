<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Distance\GoogleProvider;
use HWE\Shipping\Distance\OpenRouteServiceProvider;
use HWE\Shipping\Distance\StraightLineProvider;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Tests\TestCase;
use HWE\Tests\Unit\Shipping\Support\WpShippingFake;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proveedores HTTP de distancia y geocodificación (Google, OpenRouteService, Nominatim) con las
 * peticiones simuladas. Cada caso de error de la tabla de la Tarea 4 tiene su test. Dos reglas
 * transversales: la clave de mapas viaja solo en cabecera (nunca en la URL ni en un log), y una
 * respuesta 200 absurda (sin ruta, distancia 0, cuerpo no JSON) es un error, nunca un 0.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProvidersTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-de-proveedores-0123';

    private WpShippingFake $wp;

    protected function setUp(): void
    {
        parent::setUp();

        define('HWE_SECRETS_KEY', 'clave-de-cifrado-de-pruebas-proveedores');

        $this->wp = new WpShippingFake();
        $this->wp->install();

        Functions\stubs([
            'plugin_dir_url'           => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin'                 => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');

        $this->wp->options['hwe_config'] = ['ecommerce' => ['country' => 'CO']];
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => self::KEY]]);
    }

    private function origen(): GeoPoint
    {
        return new GeoPoint(4.6097, -74.0817);
    }

    private function destino(): GeoPoint
    {
        return new GeoPoint(4.7110, -74.0721);
    }

    /** @return list<array<string,mixed>> Respuesta de Routes con una ruta válida. */
    private function rutaGoogle(int $metros = 4350, string $duracion = '610s'): array
    {
        return [[
            'originIndex'      => 0,
            'destinationIndex' => 0,
            'distanceMeters'   => $metros,
            'duration'         => $duracion,
            'condition'        => 'ROUTE_EXISTS',
        ]];
    }

    private function errorCode(mixed $result): string
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        return (string) $result->get_error_code();
    }

    /* ---------------------------------------------------------------- Google (Routes) */

    public function testRutaDeGoogleDaDistanciaYDuracionRedondeadaAlMinutoSuperior(): void
    {
        $this->wp->respond(200, $this->rutaGoogle(4350, '610s'));

        $result = (new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving');

        $this->assertInstanceOf(DistanceResult::class, $result);
        $this->assertEqualsWithDelta(4.35, $result->km(), 0.0001);
        $this->assertSame(11, $result->durationMin());
        $this->assertSame('google_maps', $result->provider());
        $this->assertFalse($result->fallback());
    }

    public function testLaRutaEnConduccionPideTraficoYRegionDelPais(): void
    {
        $this->wp->respond(200, $this->rutaGoogle());

        (new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving');

        $request = $this->wp->sent('POST')[0];
        $body    = json_decode((string) $request['args']['body'], true);
        $this->assertSame('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', $request['url']);
        $this->assertSame('DRIVE', $body['travelMode']);
        $this->assertSame('TRAFFIC_AWARE', $body['routingPreference']);
        $this->assertSame('CO', $body['regionCode']);
    }

    public function testLaRutaAPieNoPideTrafico(): void
    {
        $this->wp->respond(200, $this->rutaGoogle());

        (new GoogleProvider())->distance($this->origen(), $this->destino(), 'walking');

        $body = json_decode((string) $this->wp->sent('POST')[0]['args']['body'], true);
        $this->assertSame('WALK', $body['travelMode']);
        $this->assertArrayNotHasKey('routingPreference', $body);
    }

    public function testGoogleRechazaLaClaveConCodigoDeNoAutorizado(): void
    {
        $this->wp->respond(403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'API no habilitada']]);

        $this->assertSame('provider_unauthorized', $this->errorCode((new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testRoutesEnvuelveElErrorEnUnaListaYLaClaveInvalidaSigueSiendoNoAutorizado(): void
    {
        // Forma real de Routes API (computeRouteMatrix) con una clave inválida: HTTP 400 y el error dentro de una lista.
        $this->wp->respond(400, [[
            'error' => [
                'code'    => 400,
                'status'  => 'INVALID_ARGUMENT',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']],
            ],
        ]]);

        $result = (new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving');

        $this->assertSame('provider_unauthorized', $this->errorCode($result));
        $this->assertSame('API_KEY_INVALID', $result->get_error_data()['reason']);
    }

    public function testRoutesConElErrorEnUnaListaYHttp200TampocoEsUnaRuta(): void
    {
        $this->wp->respond(200, [['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]]);

        $this->assertSame('provider_rate_limited', $this->errorCode((new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testGoogleSinPermisosPorEstadoPERMISSIONDENIEDTambienEsNoAutorizado(): void
    {
        $this->wp->respond(200, ['error' => ['code' => 401, 'status' => 'PERMISSION_DENIED']]);

        $this->assertSame('provider_unauthorized', $this->errorCode((new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testGoogleCuotaAgotadaDaLimiteDeUso(): void
    {
        $this->wp->respond(429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]);

        $this->assertSame('provider_rate_limited', $this->errorCode((new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testGoogleCaidaOTiempoAgotadoEsNoDisponible(): void
    {
        $this->wp->respond(503, ['error' => ['status' => 'UNAVAILABLE']]);
        $this->wp->failTransport();

        $provider = new GoogleProvider();
        $this->assertSame('provider_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
        $this->assertSame('provider_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testUnaRutaSinROUTEEXISTSEsRutaNoDisponible(): void
    {
        $this->wp->respond(200, [['condition' => 'ROUTE_NOT_FOUND', 'status' => ['code' => 9]]]);

        $this->assertSame('route_unavailable', $this->errorCode((new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testUnCuerpoNoJsonNoSeConvierteEnUnResultadoConCero(): void
    {
        $this->wp->respond(200, '<html>mantenimiento</html>');

        $result = (new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving');

        $this->assertSame('provider_unavailable', $this->errorCode($result));
    }

    public function testUnaDistanciaCeroOUnaListaVaciaSonErrores(): void
    {
        $this->wp->respond(200, $this->rutaGoogle(0));
        $this->wp->respond(200, []);

        $provider = new GoogleProvider();
        $this->assertSame('route_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
        $this->assertSame('route_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testUnaDuracionAbsurdaEsErrorAunqueLaDistanciaSeaValida(): void
    {
        $this->wp->respond(200, $this->rutaGoogle(4350, 'rara'));

        $this->assertSame('route_unavailable', $this->errorCode((new GoogleProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testGoogleGeocodificaYDevuelveElResultadoDeMejorGranularidadPrimero(): void
    {
        $this->wp->respond(200, ['results' => [
            ['location' => ['latitude' => 4.1, 'longitude' => -74.1], 'granularity' => 'APPROXIMATE', 'formattedAddress' => 'Bogotá (aprox.)'],
            ['location' => ['latitude' => 4.6, 'longitude' => -74.08], 'granularity' => 'ROOFTOP', 'formattedAddress' => 'Calle 1 # 2-3, Bogotá'],
            ['location' => ['latitude' => 4.5, 'longitude' => -74.05], 'granularity' => 'GEOMETRIC_CENTER', 'formattedAddress' => 'Centro'],
        ]]);

        $results = (new GoogleProvider())->geocode('Calle 1 # 2-3, Bogotá', 'CO', 'es');

        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        $this->assertContainsOnlyInstancesOf(GeocodeResult::class, $results);
        $this->assertSame('ROOFTOP', $results[0]->precision());
        $this->assertSame('Calle 1 # 2-3, Bogotá', $results[0]->label());
        $this->assertEqualsWithDelta(4.6, $results[0]->point()->lat(), 0.0001);
        $this->assertStringContainsString(rawurlencode('Calle 1 # 2-3, Bogotá'), $this->wp->sent('GET')[0]['url']);
    }

    public function testGoogleGeocodificacionSinResultadosNoDevuelveNada(): void
    {
        $this->wp->respond(200, ['results' => []]);

        $this->assertSame('geocoding_no_results', $this->errorCode((new GoogleProvider())->geocode('nada', 'CO', 'es')));
    }

    public function testGoogleGeocodificacionInversaDevuelveLaDireccion(): void
    {
        $this->wp->respond(200, ['results' => [
            ['location' => ['latitude' => 4.6, 'longitude' => -74.08], 'granularity' => 'ROOFTOP', 'formattedAddress' => 'Calle 1 # 2-3, Bogotá'],
        ]]);

        $result = (new GoogleProvider())->reverse(new GeoPoint(4.6, -74.08), 'es');

        $this->assertInstanceOf(GeocodeResult::class, $result);
        $this->assertSame('Calle 1 # 2-3, Bogotá', $result->label());
        $this->assertStringContainsString('/v4/geocode/location/4.6,-74.08', $this->wp->sent('GET')[0]['url']);
    }

    public function testLaClaveDeGoogleVaSoloEnCabeceraYNuncaEnUrlNiEnLog(): void
    {
        $this->wp->respond(200, $this->rutaGoogle());
        $this->wp->respond(403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'denegado']]);
        $this->wp->respond(200, ['results' => [['location' => ['latitude' => 4.6, 'longitude' => -74.08], 'granularity' => 'ROOFTOP', 'formattedAddress' => 'x']]]);

        $provider = new GoogleProvider();
        $ok       = $provider->distance($this->origen(), $this->destino(), 'driving');
        $denied   = $provider->distance($this->origen(), $this->destino(), 'driving');
        $geo      = $provider->geocode('Calle 1', 'CO', 'es');

        foreach ($this->wp->requests as $request) {
            $this->assertStringNotContainsString(self::KEY, $request['url']);
            $this->assertSame(self::KEY, $request['args']['headers']['X-Goog-Api-Key']);
        }
        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(self::KEY, $line);
        }
        $this->assertStringNotContainsString(self::KEY, serialize([$ok, $denied, $geo]));
    }

    /* ---------------------------------------------------------- OpenRouteService */

    public function testOrsDevuelveLaDistanciaDeLaRutaEnConduccion(): void
    {
        $this->wp->respond(200, ['routes' => [['summary' => ['distance' => 4350.0, 'duration' => 600.0]]]]);

        $result = (new OpenRouteServiceProvider())->distance($this->origen(), $this->destino(), 'driving');

        $this->assertEqualsWithDelta(4.35, $result->km(), 0.0001);
        $this->assertSame(10, $result->durationMin());
        $this->assertSame('openrouteservice', $result->provider());

        $request = $this->wp->sent('POST')[0];
        $this->assertSame('https://api.openrouteservice.org/v2/directions/driving-car', $request['url']);
        $body = json_decode((string) $request['args']['body'], true);
        $this->assertSame([[$this->origen()->lng(), $this->origen()->lat()], [$this->destino()->lng(), $this->destino()->lat()]], $body['coordinates']);
    }

    public function testOrsCaminandoUsaElPerfilAPie(): void
    {
        $this->wp->respond(200, ['routes' => [['summary' => ['distance' => 900.0, 'duration' => 700.0]]]]);

        (new OpenRouteServiceProvider())->distance($this->origen(), $this->destino(), 'walking');

        $this->assertSame('https://api.openrouteservice.org/v2/directions/foot-walking', $this->wp->sent('POST')[0]['url']);
    }

    public function testOrsRechazaLaClaveConNoAutorizado(): void
    {
        $this->wp->respond(403, ['error' => ['code' => 403, 'message' => 'clave no válida']]);

        $this->assertSame('provider_unauthorized', $this->errorCode((new OpenRouteServiceProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testOrsLimiteDeUsoDaRateLimited(): void
    {
        $this->wp->respond(429, []);

        $this->assertSame('provider_rate_limited', $this->errorCode((new OpenRouteServiceProvider())->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testOrsSinRutaDaRouteUnavailableYLaCaidaDaNoDisponible(): void
    {
        $this->wp->respond(404, ['error' => ['code' => 2010]]);
        $this->wp->respond(500, []);
        $this->wp->failTransport();

        $provider = new OpenRouteServiceProvider();
        $this->assertSame('route_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
        $this->assertSame('provider_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
        $this->assertSame('provider_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testOrsUnaRutaDeDistanciaCeroOSinRutasEsError(): void
    {
        $this->wp->respond(200, ['routes' => [['summary' => ['distance' => 0.0, 'duration' => 0.0]]]]);
        $this->wp->respond(200, ['routes' => []]);

        $provider = new OpenRouteServiceProvider();
        $this->assertSame('route_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
        $this->assertSame('route_unavailable', $this->errorCode($provider->distance($this->origen(), $this->destino(), 'driving')));
    }

    public function testOrsGeocodificaOrdenandoPorConfianza(): void
    {
        $this->wp->respond(200, ['features' => [
            ['geometry' => ['coordinates' => [-74.1, 4.1]], 'properties' => ['label' => 'Vaga', 'confidence' => 0.4, 'layer' => 'locality']],
            ['geometry' => ['coordinates' => [-74.08, 4.6]], 'properties' => ['label' => 'Calle 1, Bogotá', 'confidence' => 0.9, 'layer' => 'address']],
        ]]);

        $results = (new OpenRouteServiceProvider())->geocode('Calle 1 Bogotá', 'CO', 'es');

        $this->assertSame('Calle 1, Bogotá', $results[0]->label());
        $this->assertSame('address', $results[0]->precision());
        $this->assertEqualsWithDelta(4.6, $results[0]->point()->lat(), 0.0001);
        $this->assertEqualsWithDelta(-74.08, $results[0]->point()->lng(), 0.0001);
        $this->assertStringContainsString('boundary.country=CO', $this->wp->sent('GET')[0]['url']);
    }

    public function testOrsGeocodificacionSinResultadosNoDevuelveNada(): void
    {
        $this->wp->respond(200, ['features' => []]);

        $this->assertSame('geocoding_no_results', $this->errorCode((new OpenRouteServiceProvider())->geocode('nada', 'CO', 'es')));
    }

    public function testOrsGeocodificacionInversaYLaClaveSoloEnCabecera(): void
    {
        $this->wp->respond(200, ['features' => [
            ['geometry' => ['coordinates' => [-74.08, 4.6]], 'properties' => ['label' => 'Calle 1, Bogotá', 'confidence' => 0.9]],
        ]]);

        $result = (new OpenRouteServiceProvider())->reverse(new GeoPoint(4.6, -74.08), 'es');

        $this->assertSame('Calle 1, Bogotá', $result->label());
        $request = $this->wp->sent('GET')[0];
        $this->assertStringContainsString('/geocode/reverse', $request['url']);
        $this->assertStringNotContainsString(self::KEY, $request['url']);
        $this->assertStringEndsWith(self::KEY, $request['args']['headers']['Authorization']);
    }

    /* ----------------------------------------------- Línea recta y Nominatim */

    public function testLaLineaRectaNoHaceNingunaPeticionParaLaDistancia(): void
    {
        $result = (new StraightLineProvider())->distance($this->origen(), $this->destino(), 'driving');

        $this->assertInstanceOf(DistanceResult::class, $result);
        $this->assertSame('straight_line', $result->provider());
        $this->assertFalse($result->fallback());
        $this->assertSame([], $this->wp->requests);
    }

    public function testNominatimSeIdentificaConLaUrlDelSitioYBuscaEnElPaisBase(): void
    {
        $this->wp->respond(200, [['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1, Bogotá', 'type' => 'house']]);

        $results = (new StraightLineProvider())->geocode('Calle 1, Bogotá', 'CO', 'es');

        $request = $this->wp->sent('GET')[0];
        $this->assertStringStartsWith('https://nominatim.openstreetmap.org/search', $request['url']);
        $this->assertStringContainsString('countrycodes=co', $request['url']);
        $this->assertStringContainsString('accept-language=es', $request['url']);
        $this->assertStringContainsString('https://sitio.test', $request['args']['headers']['User-Agent']);
        $this->assertStringNotContainsString('@', $request['args']['headers']['User-Agent'], 'sin correo escrito a mano');
        $this->assertSame('Calle 1, Bogotá', $results[0]->label());
        $this->assertSame('house', $results[0]->precision());
    }

    public function testNominatimSinResultadosNoDevuelveNada(): void
    {
        $this->wp->respond(200, []);

        $this->assertSame('geocoding_no_results', $this->errorCode((new StraightLineProvider())->geocode('nada', 'CO', 'es')));
    }

    public function testUnErrorDeRedPonePausaDe30SegundosYCortaLasSiguientesLlamadas(): void
    {
        $this->wp->failTransport();
        $provider = new StraightLineProvider();

        $this->assertSame('provider_unavailable', $this->errorCode($provider->geocode('Calle 1', 'CO', 'es')));
        $this->assertSame('geocoding_backoff', $this->errorCode($provider->geocode('Calle 2', 'CO', 'es')));

        $this->assertCount(1, $this->wp->requests, 'en pausa no sale ninguna petición');
        $this->assertSame(30, $this->wp->transientWrites[0]['ttl']);
    }

    public function testUnError5xxPonePausaDeDosMinutos(): void
    {
        $this->wp->respond(503, []);
        $provider = new StraightLineProvider();

        $this->assertSame('provider_unavailable', $this->errorCode($provider->geocode('Calle 1', 'CO', 'es')));
        $this->assertSame('geocoding_backoff', $this->errorCode($provider->reverse(new GeoPoint(4.6, -74.08), 'es')));

        $this->assertCount(1, $this->wp->requests);
        $this->assertSame(120, $this->wp->transientWrites[0]['ttl']);
    }

    public function testUn429PonePausaDeQuinceMinutos(): void
    {
        $this->wp->respond(429, []);
        $provider = new StraightLineProvider();

        $this->assertSame('provider_rate_limited', $this->errorCode($provider->geocode('Calle 1', 'CO', 'es')));
        $this->assertSame('geocoding_backoff', $this->errorCode($provider->geocode('Calle 2', 'CO', 'es')));

        $this->assertCount(1, $this->wp->requests);
        $this->assertSame(900, $this->wp->transientWrites[0]['ttl']);
    }

    public function testNominatimInversaDevuelveLaDireccionDeLaCoordenada(): void
    {
        $this->wp->respond(200, ['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1, Bogotá']);

        $result = (new StraightLineProvider())->reverse(new GeoPoint(4.6, -74.08), 'es');

        $this->assertInstanceOf(GeocodeResult::class, $result);
        $this->assertSame('Calle 1, Bogotá', $result->label());
        $this->assertStringStartsWith('https://nominatim.openstreetmap.org/reverse', $this->wp->sent('GET')[0]['url']);
    }

    public function testLaClaveDeMapasNoViajaANominatim(): void
    {
        $this->wp->respond(200, [['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle 1']]);

        (new StraightLineProvider())->geocode('Calle 1', 'CO', 'es');

        $request = $this->wp->sent('GET')[0];
        $this->assertStringNotContainsString(self::KEY, $request['url']);
        $this->assertStringNotContainsString(self::KEY, serialize($request['args']));
    }
}
