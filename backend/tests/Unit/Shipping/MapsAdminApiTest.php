<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Admin\MapsAdminApi;
use HWE\Shipping\Distance\DistanceService;
use HWE\Tests\TestCase;
use HWE\Tests\Unit\Shipping\Support\ThrowingProvider;
use HWE\Tests\Unit\Shipping\Support\WpShippingFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rutas de wp-admin para mapas: «Validar» (geocodificar) y «Probar conexión» (llamada directa al
 * proveedor configurado, sin caché ni respaldo). Se comprueba permiso, que la prueba no cae a
 * Nominatim, la clasificación de cada error con su pista, que la clave nunca sale y que la
 * dirección de prueba es obligatoria.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MapsAdminApiTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-admin-mapas-0123456789';
    private const DIRECCION = 'Dirección de prueba 10, Ciudad de prueba';

    private WpShippingFake $wp;

    /** @var array<string,array{route:string,args:array<string,mixed>}> */
    private array $registered = [];

    private bool $canManage = true;
    private bool $nonceValid = true;

    protected function setUp(): void
    {
        parent::setUp();

        define('HWE_SECRETS_KEY', 'clave-de-cifrado-de-pruebas-admin-mapas');

        $this->wp = new WpShippingFake();
        $this->wp->install();

        Functions\stubs([
            '__'                       => static fn (string $texto): string => $texto,
            'plugin_dir_url'           => static fn (): string => '',
            'register_activation_hook' => static fn (): null => null,
            'is_admin'                 => static fn (): bool => false,
        ]);
        Functions\when('current_user_can')->alias(fn (string $capability): bool => $this->canManage && 'manage_woocommerce' === $capability);
        Functions\when('wp_verify_nonce')->alias(fn (string $nonce, string $action): int|false => $this->nonceValid && 'wp_rest' === $action && '' !== $nonce ? 1 : false);
        Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args): bool {
            $this->registered[$namespace . $route] = ['route' => $route, 'args' => $args];
            return true;
        });

        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');

        $this->wp->options['hwe_config'] = [
            'ecommerce'    => ['country' => 'CO'],
            'integrations' => ['maps_provider' => 'google_maps'],
            'shipping'     => ['distance_method' => 'maps'],
        ];
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => self::KEY]]);
    }

    /* ------------------------------------------------------------------ utilidades */

    private function request(string $address = self::DIRECCION): \WP_REST_Request
    {
        return new \WP_REST_Request(['address' => $address], ['X-WP-Nonce' => 'nonce-de-prueba'], '/hwe-shipping/v1/admin/maps-test');
    }

    private function body(mixed $response): array
    {
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        return (array) $response->get_data();
    }

    /** Respuesta de Google Geocoding v4 con un resultado válido. */
    private function resultadoGoogle(): array
    {
        return [
            'results' => [[
                'location'         => ['latitude' => 4.6097, 'longitude' => -74.0817],
                'formattedAddress' => 'Dirección de prueba 10, Ciudad de prueba',
                'granularity'      => 'ROOFTOP',
            ]],
        ];
    }

    /** Error 403 de Google con el motivo estructurado que devuelve la API. */
    private function errorGoogle(string $reason, string $mensaje = 'Mensaje del proveedor con ' . self::KEY): array
    {
        $error = [
            'code'    => 403,
            'message' => $mensaje,
            'status'  => 'PERMISSION_DENIED',
        ];
        if ('' !== $reason) {
            $error['details'] = [[
                '@type'  => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => $reason,
                'domain' => 'googleapis.com',
            ]];
        }
        return ['error' => $error];
    }

    /* ----------------------------------------------------------- permisos y rutas */

    public function testLasDosRutasSeRegistranConPermisoDeWooYPOST(): void
    {
        MapsAdminApi::routes();

        foreach (['/admin/geocode', '/admin/maps-test'] as $route) {
            $this->assertArrayHasKey('hwe-shipping/v1' . $route, $this->registered);
            $args = $this->registered['hwe-shipping/v1' . $route]['args'];
            $this->assertSame('POST', $args['methods']);
            $this->assertIsCallable($args['permission_callback']);
        }
    }

    public function testSinCapacidadManageWooCommerceResponde403(): void
    {
        $this->canManage = false;

        $result = MapsAdminApi::permission($this->request());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function testSinNonceWpRestResponde403(): void
    {
        $this->nonceValid = false;

        $result = MapsAdminApi::permission($this->request());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function testConCapacidadYNonceElPermisoSeConcede(): void
    {
        $this->assertTrue(MapsAdminApi::permission($this->request()));
    }

    /* ------------------------------------------------------- «Probar conexión» */

    public function testExitoDevuelveOkConElProveedorQueRespondio(): void
    {
        $this->wp->respond(200, $this->resultadoGoogle());

        $body = $this->body(MapsAdminApi::mapsTest($this->request()));

        $this->assertTrue($body['ok']);
        $this->assertSame('ok', $body['code']);
        $this->assertSame('google_maps', $body['provider']);
        $this->assertNotSame('', $body['message']);
    }

    public function testLaPruebaLlamaAlProveedorSinRespaldoANominatim(): void
    {
        // Solo hay una respuesta encolada: si la prueba cayera a Nominatim, `WpShippingFake`
        // lanzaría una petición inesperada.
        $this->wp->respond(403, $this->errorGoogle('API_KEY_SERVICE_BLOCKED'));

        $body = $this->body(MapsAdminApi::mapsTest($this->request()));

        $this->assertSame('api_not_enabled', $body['code']);
        $this->assertCount(1, $this->wp->requests);
        $this->assertStringContainsString('geocode.googleapis.com', $this->wp->requests[0]['url']);
        foreach ($this->wp->requests as $request) {
            $this->assertStringNotContainsString('nominatim', $request['url']);
        }
    }

    public function testSinClaveDevuelveMissingKeySinLlamarAHttp(): void
    {
        $this->wp->options['hwe_config_secrets'] = [];

        $body = $this->body(MapsAdminApi::mapsTest($this->request()));

        $this->assertFalse($body['ok']);
        $this->assertSame('missing_key', $body['code']);
        $this->assertSame([], $this->wp->requests);
    }

    public function testSinProveedorElegidoDevuelveMissingKeySinLlamarAHttp(): void
    {
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'none';

        $body = $this->body(MapsAdminApi::mapsTest($this->request()));

        $this->assertSame('missing_key', $body['code']);
        $this->assertSame('none', $body['provider']);
        $this->assertSame([], $this->wp->requests);
    }

    #[DataProvider('clasificacionDeErrores')]
    public function testCadaErrorDelProveedorSeClasificaConUnaPistaAccionable(
        int $status,
        mixed $cuerpo,
        string $codigoEsperado
    ): void {
        $this->wp->respond($status, $cuerpo);

        $body = $this->body(MapsAdminApi::mapsTest($this->request()));

        $this->assertFalse($body['ok']);
        $this->assertSame($codigoEsperado, $body['code']);
        $this->assertNotSame('', $body['message']);
        $this->assertNotSame('', $body['hint']);
        $this->assertSame('google_maps', $body['provider']);
    }

    /** @return array<string,array{0:int,1:mixed,2:string}> */
    public static function clasificacionDeErrores(): array
    {
        return [
            'clave rechazada (401)'                  => [401, '', 'unauthorized'],
            'permiso denegado sin motivo'            => [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 'unauthorized'],
            'clave inválida con HTTP 400'            => [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]], 'unauthorized'],
            'API no habilitada en el proyecto'       => [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]]], 'api_not_enabled'],
            'API bloqueada por restricciones de clave' => [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'API_KEY_SERVICE_BLOCKED']]]], 'api_not_enabled'],
            'cuota agotada (429)'                    => [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 'rate_limited'],
            'error del servidor del proveedor (500)' => [500, '', 'network'],
            'respuesta que no es JSON'               => [200, '<html>mantenimiento</html>', 'network'],
            'sin resultados para la dirección'       => [200, ['results' => []], 'no_results'],
        ];
    }

    public function testLaClaveNiParcialNiElMensajeCrudoSaleDeLaRespuesta(): void
    {
        $this->wp->respond(403, $this->errorGoogle('SERVICE_DISABLED', 'Clave ' . self::KEY . ' sin permiso'));

        $json = (string) json_encode(MapsAdminApi::mapsTest($this->request())->get_data());

        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString(substr(self::KEY, 0, 8), $json);
        $this->assertStringNotContainsString('maps_api_key', $json);
    }

    public function testLaPruebaEnviaLaDireccionTalCualYLaRespuestaNoSeCachea(): void
    {
        $this->wp->respond(200, $this->resultadoGoogle());

        $response = MapsAdminApi::mapsTest($this->request('  Dirección   de prueba 10 '));

        $this->assertStringContainsString('%20de%20prueba%2010', $this->wp->requests[0]['url']);
        $this->assertStringNotContainsString('%20%20', $this->wp->requests[0]['url']);
        $this->assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null);
        $this->assertSame([], $this->wp->transientWrites);
    }

    public function testUnProveedorQueLanzaExcepcionSeClasificaComoRedSinFiltrarElMensaje(): void
    {
        $this->wp->filterProviders(static fn (array $providers): array => ['google_maps' => new ThrowingProvider('google_maps')]);

        $body = $this->body(MapsAdminApi::mapsTest($this->request()));

        $this->assertSame('network', $body['code']);
        $this->assertStringNotContainsString(ThrowingProvider::MESSAGE, (string) json_encode($body));
        foreach ($this->wp->logged as $line) {
            $this->assertStringNotContainsString(ThrowingProvider::MESSAGE, $line);
        }
    }

    /* ------------------------------------------------------ dirección obligatoria */

    #[DataProvider('direccionesNoValidas')]
    public function testLaDireccionDePruebaEsObligatoriaEnAmbasRutas(mixed $direccion): void
    {
        $request = new \WP_REST_Request(['address' => $direccion], ['X-WP-Nonce' => 'n'], '/x');

        foreach ([MapsAdminApi::class . '::mapsTest', MapsAdminApi::class . '::geocode'] as $handler) {
            $result = call_user_func($handler, $request);

            $this->assertInstanceOf(\WP_Error::class, $result, $handler);
            $this->assertSame(422, $result->get_error_data()['status'], $handler);
        }
        $this->assertSame([], $this->wp->requests);
    }

    /** @return array<string,array{0:mixed}> */
    public static function direccionesNoValidas(): array
    {
        return [
            'ausente'          => [null],
            'vacía'            => [''],
            'solo espacios'    => ['   '],
            'no es texto'      => [12345],
            'demasiado larga' => [str_repeat('a', 201)],
        ];
    }

    /* ----------------------------------------------------------------- «Validar» */

    public function testValidarDevuelveCoordenadaEtiquetaPrecisionYProveedor(): void
    {
        $this->wp->respond(200, $this->resultadoGoogle());

        $response = MapsAdminApi::geocode($this->request());

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            [
                'lat'       => 4.6097,
                'lng'       => -74.0817,
                'label'     => 'Dirección de prueba 10, Ciudad de prueba',
                'precision' => 'ROOFTOP',
                'provider'  => 'google_maps',
            ],
            $response->get_data()
        );
        $this->assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null);
    }

    public function testValidarInformaDelProveedorRealSiCaeANominatim(): void
    {
        $this->wp->respond(500, '');
        $this->wp->respond(200, [['lat' => '4.6', 'lon' => '-74.08', 'display_name' => 'Calle de prueba', 'type' => 'house']]);

        $data = MapsAdminApi::geocode($this->request())->get_data();

        $this->assertSame('nominatim', $data['provider']);
        $this->assertSame('Calle de prueba', $data['label']);
    }

    public function testValidarSinResultadosResponde422ConCodigoEstable(): void
    {
        $this->wp->options['hwe_config']['integrations']['maps_provider'] = 'none';
        $this->wp->respond(200, []);

        $result = MapsAdminApi::geocode($this->request());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('geocoding_no_results', $result->get_error_code());
        $this->assertSame(422, $result->get_error_data()['status']);
    }

    public function testLaRutaDeValidarUsaElServicioDeDistanciasConSuCache(): void
    {
        $this->wp->respond(200, $this->resultadoGoogle());

        MapsAdminApi::geocode($this->request());

        $this->assertCount(1, $this->wp->transientWrites, 'El resultado de «Validar» sí pasa por la caché del servicio.');
        $this->assertStringStartsWith('hwe_shipping_geo_', $this->wp->transientWrites[0]['key']);
    }

    public function testElIdiomaDelServicioEsElPrincipalDelSitio(): void
    {
        $this->wp->options['hwe_config']['brand'] = ['locale' => 'pt_BR'];

        $this->assertSame('pt', DistanceService::language());
    }
}
