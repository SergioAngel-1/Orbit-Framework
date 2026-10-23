<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Shipping\Rest\GeocodeApi;
use HWE\Tests\TestCase;
use HWE\Tests\Unit\Shipping\Support\ScriptedProvider;
use HWE\Tests\Unit\Shipping\Support\WpShippingFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rutas internas de geocodificación para el mapa del BFF (`hwe-shipping/v1/geocode` y
 * `reverse-geocode`).
 *
 * Se prueba el contrato, no el proveedor: el secreto interno (fallo cerrado), la validación de la
 * consulta y de las coordenadas, el máximo de candidatos, la caché del servicio (sin segunda
 * llamada), el backoff de Nominatim, los códigos de error estables y que la respuesta no lleva
 * ni proveedor, ni precisión, ni clave. Los proveedores de mapas son `ScriptedProvider` (sin red);
 * Nominatim pasa por el HTTP simulado para comprobar de verdad el backoff.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GeocodeApiTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-geocodificacion-interna-5544';
    private const CONSULTA = 'Calle de prueba 1, Ciudad de prueba';

    private WpShippingFake $wp;

    /** @var array<string,array{route:string,args:array<string,mixed>}> */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();

        define('HWE_SECRETS_KEY', 'clave-de-cifrado-de-pruebas-geocodificacion');

        $this->wp = new WpShippingFake();
        $this->wp->install();

        Functions\stubs([
            '__'                       => static fn (string $texto): string => $texto,
            'plugin_dir_url'           => static fn (): string => '',
            'register_activation_hook' => static fn (): null => null,
            'is_admin'                 => static fn (): bool => false,
        ]);
        Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args): bool {
            $this->registered[$namespace . $route] = ['route' => $route, 'args' => $args];
            return true;
        });
        // Por defecto, la petición trae el secreto del BFF. Cada test que necesite otra cosa lo cambia.
        Functions\when('hwe_has_internal_secret')->justReturn(true);

        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');

        // Sin proveedor de mapas: la geocodificación cae a Nominatim (el HTTP simulado).
        $this->wp->options['hwe_config'] = [
            'ecommerce'    => ['country' => 'CO'],
            'integrations' => ['maps_provider' => 'none'],
            'shipping'     => ['distance_method' => 'straight_line'],
        ];
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => self::KEY]]);
    }

    /* ------------------------------------------------------------------ utilidades */

    private function request(array $params, string $route = '/hwe-shipping/v1/geocode'): \WP_REST_Request
    {
        return new \WP_REST_Request($params, ['X-HWE-Internal-Secret' => 'secreto-de-prueba'], $route);
    }

    /** Usa el proveedor de mapas de prueba (id `google_maps`, el que elige la config). */
    private function conMapas(ScriptedProvider $provider): void
    {
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'google_maps';
        $this->wp->filterProviders(static function (array $providers) use ($provider): array {
            $providers['google_maps'] = $provider;
            return $providers;
        });
    }

    private function mapas(mixed $geocode = [], mixed $reverse = null): ScriptedProvider
    {
        return new ScriptedProvider('google_maps', new DistanceResult(4.35, 11, 'google_maps'), $geocode, $reverse);
    }

    /** @return GeocodeResult[] Candidatos de prueba, con coordenadas distintas. */
    private function candidatos(int $cantidad): array
    {
        $resultados = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $resultados[] = new GeocodeResult(
                new GeoPoint(4.7110 + $i / 1000, -74.0721 - $i / 1000),
                'Calle de prueba ' . ($i + 1),
                'ROOFTOP',
                'google_maps',
            );
        }
        return $resultados;
    }

    private function httpStatus(mixed $response): int
    {
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        return $response->get_status();
    }

    private function body(mixed $response): array
    {
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        return (array) $response->get_data();
    }

    /* ----------------------------------------------------------- rutas y secreto */

    public function testLasDosRutasSeRegistranComoPostConPermisoDeSecretoInterno(): void
    {
        GeocodeApi::routes();

        foreach (['/geocode', '/reverse-geocode'] as $route) {
            $this->assertArrayHasKey('hwe-shipping/v1' . $route, $this->registered);
            $args = $this->registered['hwe-shipping/v1' . $route]['args'];
            $this->assertSame('POST', $args['methods']);
            $this->assertIsCallable($args['permission_callback']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function rutas(): iterable
    {
        yield 'geocodificación' => ['geocode'];
        yield 'geocodificación inversa' => ['reverse-geocode'];
    }

    #[DataProvider('rutas')]
    public function testSinSecretoInternoResponde401SinTocarLaRed(string $ruta): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(false);
        $this->conMapas($this->mapas(geocode: $this->candidatos(1), reverse: $this->candidatos(1)[0]));

        $result = $ruta === 'geocode'
            ? GeocodeApi::permission($this->request(['query' => self::CONSULTA]))
            : GeocodeApi::permission($this->request(['lat' => 4.7110, 'lng' => -74.0721]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status']);
        $this->assertSame([], $this->wp->requests);
    }

    public function testConSecretoInternoElPermisoSeConcede(): void
    {
        $this->assertTrue(GeocodeApi::permission($this->request(['query' => self::CONSULTA])));
    }

    /* ----------------------------------------------------------- geocodificación */

    /** @return iterable<string,array{mixed}> */
    public static function consultasNoValidas(): iterable
    {
        yield 'vacía' => [''];
        yield 'solo espacios y tabulaciones' => ["  \t\n  "];
        yield 'ausente' => [null];
        yield 'número' => [42];
        yield 'lista' => [['calle']];
    }

    #[DataProvider('consultasNoValidas')]
    public function testUnaConsultaNoValidaDa422SinLlamarAlProveedor(mixed $consulta): void
    {
        $provider = $this->mapas(geocode: $this->candidatos(1));
        $this->conMapas($provider);

        $response = GeocodeApi::geocode($this->request(['query' => $consulta]));

        $this->assertSame(422, $this->httpStatus($response));
        $this->assertSame(['code' => 'invalid_query'], $this->body($response));
        $this->assertSame([], $provider->geocodeQueries);
        $this->assertSame([], $this->wp->requests);
    }

    public function testUnaConsultaDeMasDe200CaracteresDa422(): void
    {
        $provider = $this->mapas(geocode: $this->candidatos(1));
        $this->conMapas($provider);

        $response = GeocodeApi::geocode($this->request(['query' => str_repeat('a', 201)]));

        $this->assertSame(422, $this->httpStatus($response));
        $this->assertSame(['code' => 'invalid_query'], $this->body($response));
        $this->assertSame([], $provider->geocodeQueries);
    }

    public function testUnaConsultaDe200CaracteresSeAceptaYLosEspaciosSeColapsan(): void
    {
        $provider = $this->mapas(geocode: $this->candidatos(1));
        $this->conMapas($provider);

        $response = GeocodeApi::geocode($this->request(['query' => '  ' . str_repeat('a', 199) . "\t"]));

        $this->assertSame(200, $this->httpStatus($response));
        $this->assertCount(1, $provider->geocodeQueries);
    }

    public function testLaBusquedaDevuelveComoMucho5CandidatosSoloConLatLngYEtiqueta(): void
    {
        $this->conMapas($this->mapas(geocode: $this->candidatos(7)));

        $response = GeocodeApi::geocode($this->request(['query' => self::CONSULTA]));
        $body     = $this->body($response);

        $this->assertSame(200, $this->httpStatus($response));
        $this->assertCount(5, $body['results']);
        $this->assertSame(['lat', 'lng', 'label'], array_keys($body['results'][0]));
        $this->assertSame('Calle de prueba 1', $body['results'][0]['label']);
        $this->assertSame(4.7110, $body['results'][0]['lat']);
    }

    public function testLaRespuestaNoIncluyeProveedorNiPrecisionNiClave(): void
    {
        $this->conMapas($this->mapas(geocode: $this->candidatos(2)));

        $json = (string) json_encode(GeocodeApi::geocode($this->request(['query' => self::CONSULTA]))->get_data());

        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString('google_maps', $json);
        $this->assertStringNotContainsString('precision', $json);
        $this->assertStringNotContainsString('ROOFTOP', $json);
        $this->assertStringNotContainsString('provider', $json);
    }

    public function testUnResultadoEnCacheNoLlamaAlProveedorDeNuevo(): void
    {
        $provider = $this->mapas(geocode: $this->candidatos(1));
        $this->conMapas($provider);

        $primera  = GeocodeApi::geocode($this->request(['query' => self::CONSULTA]));
        $segunda  = GeocodeApi::geocode($this->request(['query' => self::CONSULTA]));

        $this->assertSame(200, $this->httpStatus($primera));
        $this->assertSame(200, $this->httpStatus($segunda));
        $this->assertCount(1, $provider->geocodeQueries, 'la segunda búsqueda sale de la caché');
        $this->assertSame($this->body($primera), $this->body($segunda));
    }

    public function testSinCandidatosDa422NoResultsSinMensajeDelProveedor(): void
    {
        // Nominatim responde una lista vacía: no hay dirección para esa consulta.
        $this->wp->respond(200, []);

        $response = GeocodeApi::geocode($this->request(['query' => self::CONSULTA]));

        $this->assertSame(422, $this->httpStatus($response));
        $this->assertSame(['code' => 'no_results'], $this->body($response));
        $this->assertSame([], $this->wp->logged, 'un «sin resultados» es normal y no se registra como fallo');
    }

    public function testUnFalloDelProveedorDa503ConCodigoEstableYSinSuMensaje(): void
    {
        // Nominatim responde 429: entra en pausa y el error no lleva su texto a la respuesta.
        $this->wp->respond(429, ['error' => 'Mensaje del proveedor con ' . self::KEY]);

        $response = GeocodeApi::geocode($this->request(['query' => self::CONSULTA]));
        $json     = (string) json_encode($response->get_data());

        $this->assertSame(503, $this->httpStatus($response));
        $this->assertSame(['code' => 'provider_unavailable'], $this->body($response));
        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString('Mensaje del proveedor', $json);
        $this->assertStringContainsString('[hwe] shipping:', implode(' ', $this->wp->logged));
    }

    public function testLaPausaDeNominatimDa503GeocodingBackoffSinNuevaPeticion(): void
    {
        $this->wp->respond(429, []);
        GeocodeApi::geocode($this->request(['query' => 'Primera consulta']));
        $this->assertCount(1, $this->wp->requests);

        $response = GeocodeApi::geocode($this->request(['query' => 'Otra consulta distinta']));

        $this->assertSame(503, $this->httpStatus($response));
        $this->assertSame(['code' => 'geocoding_backoff'], $this->body($response));
        $this->assertCount(1, $this->wp->requests, 'durante la pausa no sale ninguna petición');
    }

    public function testLasRespuestasNoSeCachenEnElNavegador(): void
    {
        $this->conMapas($this->mapas(geocode: $this->candidatos(1)));

        $ok = GeocodeApi::geocode($this->request(['query' => self::CONSULTA]));
        $this->wp->respond(200, []);
        $error = GeocodeApi::geocode($this->request(['query' => 'Sin resultados']));

        $this->assertSame('no-store', $ok->get_headers()['Cache-Control'] ?? null);
        $this->assertSame('no-store', $error->get_headers()['Cache-Control'] ?? null);
    }

    /* ------------------------------------------------------- geocodificación inversa */

    /** @return iterable<string,array{mixed,mixed}> */
    public static function coordenadasNoValidas(): iterable
    {
        yield 'texto' => ['abc', '4.6'];
        yield 'vacías' => ['', ''];
        yield 'ausentes' => [null, null];
        yield 'cero y cero' => [0, 0];
        yield 'cadenas de cero' => ['0', '0.0'];
        yield 'latitud fuera de rango' => [91, -74.0];
        yield 'longitud fuera de rango' => [4.6, 181];
        yield 'infinito' => [INF, -74.0];
        yield 'no es un número' => [NAN, -74.0];
        yield 'booleano' => [true, false];
        yield 'lista' => [[4.6], -74.0];
    }

    #[DataProvider('coordenadasNoValidas')]
    public function testCoordenadasInvalidasDan422SinLlamarAlProveedor(mixed $lat, mixed $lng): void
    {
        $provider = $this->mapas(reverse: $this->candidatos(1)[0]);
        $this->conMapas($provider);

        $response = GeocodeApi::reverse($this->request(['lat' => $lat, 'lng' => $lng], '/hwe-shipping/v1/reverse-geocode'));

        $this->assertSame(422, $this->httpStatus($response));
        $this->assertSame(['code' => 'invalid_point'], $this->body($response));
        $this->assertSame(0, $provider->reverseCalls);
        $this->assertSame([], $this->wp->requests);
    }

    public function testLasCoordenadasComoCadenaNumericaSeAceptan(): void
    {
        $provider = $this->mapas(reverse: $this->candidatos(1)[0]);
        $this->conMapas($provider);

        $response = GeocodeApi::reverse($this->request(['lat' => '4.7110', 'lng' => '-74.0721'], '/hwe-shipping/v1/reverse-geocode'));

        $this->assertSame(200, $this->httpStatus($response));
        $this->assertSame(1, $provider->reverseCalls);
    }

    public function testLaGeocodificacionInversaDevuelveSoloLaEtiqueta(): void
    {
        $this->conMapas($this->mapas(reverse: $this->candidatos(1)[0]));

        $response = GeocodeApi::reverse($this->request(['lat' => 4.7110, 'lng' => -74.0721], '/hwe-shipping/v1/reverse-geocode'));

        $this->assertSame(200, $this->httpStatus($response));
        $this->assertSame(['label' => 'Calle de prueba 1'], $this->body($response));
    }

    public function testLaInversaSinDireccionDa422NoResults(): void
    {
        // Nominatim responde sin `display_name`: no hay dirección para ese punto.
        $this->wp->respond(200, ['error' => 'Unable to geocode']);

        $response = GeocodeApi::reverse($this->request(['lat' => 4.7110, 'lng' => -74.0721], '/hwe-shipping/v1/reverse-geocode'));

        $this->assertSame(422, $this->httpStatus($response));
        $this->assertSame(['code' => 'no_results'], $this->body($response));
    }

    public function testLaInversaEnPausaDa503GeocodingBackoff(): void
    {
        $this->wp->respond(500, []);
        GeocodeApi::reverse($this->request(['lat' => 4.7110, 'lng' => -74.0721], '/hwe-shipping/v1/reverse-geocode'));

        $response = GeocodeApi::reverse($this->request(['lat' => 4.8000, 'lng' => -74.1000], '/hwe-shipping/v1/reverse-geocode'));

        $this->assertSame(503, $this->httpStatus($response));
        $this->assertSame(['code' => 'geocoding_backoff'], $this->body($response));
        $this->assertCount(1, $this->wp->requests);
    }
}
