<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Shipping\DeliveryPoint;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-cart.php';

/**
 * Punto de entrega del cliente en la sesión de WooCommerce (tarea 6).
 *
 * El navegador no calcula nada: solo declara el pin (validado aquí) y la dirección con la que lo
 * marcó. El punto se guarda junto a una huella de calle + ciudad + país y solo se usa si esa huella
 * sigue coincidiendo con el destino del paquete: cambiar la calle o la ciudad lo invalida, cambiar
 * mayúsculas, tildes o espacios no (Foco 1). El paquete de envío lo lleva en `destination` para que
 * el hash de WooCommerce cambie al mover el pin.
 *
 * También se comprueba que coordenadas basura (NaN, cadenas no numéricas, fuera de rango, 0,0) se
 * rechazan con un error de la Store API y no se guardan (Foco 3), y que sin sesión de WooCommerce
 * nada revienta.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DeliveryPointTest extends TestCase
{
    private const ADDRESS = 'Calle Córdoba 10';
    private const CITY    = 'A Coruña';
    private const COUNTRY = 'ES';

    private TestDeliveryPointWc $wc;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->wc     = new TestDeliveryPointWc();
        $this->logged = [];

        Functions\stubs([
            '__'                       => static fn (string $text): string => $text,
            'is_admin'                 => static fn (): bool => false,
            'plugin_dir_url'           => static fn (): string => '',
            'register_activation_hook' => static fn (): null => null,
            'did_action'               => static fn (string $hook): int => 0,
            'remove_accents'           => static fn (string $text): string => strtr($text, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
                'ñ' => 'n', 'Ñ' => 'N',
            ]),
            'sanitize_text_field'      => static function (mixed $value): string {
                $text = is_scalar($value) ? (string) $value : '';

                return trim((string) preg_replace('/\s+/', ' ', $text));
            },
        ]);
        Functions\when('WC')->alias(fn (): TestDeliveryPointWc => $this->wc);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    /* ------------------------------------------------------------------ utilidades */

    private function session(): TestDeliveryPointSession
    {
        return $this->wc->session;
    }

    /** Destino del carrito tal como lo arma WooCommerce. @return array<string,string> */
    private function destination(array $overrides = []): array
    {
        return array_merge(
            [
                'address_1' => self::ADDRESS,
                'city'      => self::CITY,
                'country'   => self::COUNTRY,
            ],
            $overrides
        );
    }

    /** @param array<string,mixed> $overrides */
    private function setPoint(array $overrides = []): void
    {
        DeliveryPoint::updatePoint(
            array_merge(
                [
                    'action'    => 'set_point',
                    'lat'       => 43.3623,
                    'lng'       => -8.4115,
                    'address_1' => self::ADDRESS,
                    'city'      => self::CITY,
                    'country'   => self::COUNTRY,
                ],
                $overrides
            )
        );
    }

    /** @param array<string,mixed> $destination @return list<array<string,mixed>> */
    private function packages(array $destination): array
    {
        return [
            [
                'contents'        => [],
                'destination'     => $destination,
                'applied_coupons' => [],
            ],
        ];
    }

    /* ------------------------------------------------------------------ set_point */

    public function testElPuntoValidoSeGuardaEnLaSesionConSuHuella(): void
    {
        $this->setPoint();

        $state = $this->session()->get(DeliveryPoint::SESSION_KEY);

        $this->assertIsArray($state);
        $this->assertSame(43.3623, $state['lat']);
        $this->assertSame(-8.4115, $state['lng']);
        $this->assertSame(DeliveryPoint::fingerprint($this->destination()), $state['fingerprint']);
        $this->assertSame([], $this->logged);
    }

    /** @return array<string,array{0:mixed,1:mixed}> */
    public static function invalidCoordinates(): array
    {
        return [
            'NaN'            => [NAN, -8.4115],
            'infinito'       => [43.3623, INF],
            'cadena no numérica' => ['norte', -8.4115],
            'cadena vacía'   => ['', ''],
            'latitud fuera de rango' => [90.5, -8.4115],
            'longitud fuera de rango' => [43.3623, 180.5],
            'cero cero'      => [0, 0],
            'booleano'       => [true, false],
            'array'          => [['43.3623'], ['-8.4115']],
            'nulos'          => [null, null],
        ];
    }

    /** @param mixed $lat @param mixed $lng */
    #[DataProvider('invalidCoordinates')]
    public function testLasCoordenadasBasuraNoSeGuardan($lat, $lng): void
    {
        try {
            $this->setPoint(['lat' => $lat, 'lng' => $lng]);
            $this->fail('Se esperaba un error de la Store API para ' . json_encode([$lat, $lng]));
        } catch (RouteException $error) {
            $this->assertSame('hwe_shipping_invalid_point', $error->getErrorCode());
            $this->assertSame(400, $error->getCode());
        }

        $this->assertNull($this->session()->get(DeliveryPoint::SESSION_KEY));
    }

    public function testSinCalleNoSeGuardaElPunto(): void
    {
        foreach (['', '   ', null, ['Calle Córdoba 10']] as $address) {
            try {
                $this->setPoint(['address_1' => $address]);
                $this->fail('Se esperaba un error de la Store API sin calle.');
            } catch (RouteException $error) {
                $this->assertSame('hwe_shipping_invalid_point', $error->getErrorCode());
            }
        }

        $this->assertNull($this->session()->get(DeliveryPoint::SESSION_KEY));
    }

    public function testUnPuntoInvalidoNoPisaElPuntoValidoAnterior(): void
    {
        $this->setPoint();
        $before = $this->session()->get(DeliveryPoint::SESSION_KEY);

        try {
            $this->setPoint(['lat' => 100]);
            $this->fail('Se esperaba un error de la Store API.');
        } catch (RouteException $error) {
            $this->assertSame('hwe_shipping_invalid_point', $error->getErrorCode());
        }

        $this->assertSame($before, $this->session()->get(DeliveryPoint::SESSION_KEY));
    }

    public function testUnaAccionDesconocidaEsUnError(): void
    {
        foreach ([['action' => 'move_point'], ['action' => ''], ['lat' => 43.3623], []] as $payload) {
            try {
                DeliveryPoint::updatePoint($payload);
                $this->fail('Se esperaba un error para ' . json_encode($payload));
            } catch (RouteException $error) {
                $this->assertSame('hwe_shipping_invalid_action', $error->getErrorCode());
                $this->assertSame(400, $error->getCode());
            }
        }

        $this->assertNull($this->session()->get(DeliveryPoint::SESSION_KEY));
    }

    public function testClearPointBorraElPunto(): void
    {
        $this->setPoint();
        $this->assertNotNull($this->session()->get(DeliveryPoint::SESSION_KEY));

        DeliveryPoint::updatePoint(['action' => 'clear_point']);

        $this->assertNull($this->session()->get(DeliveryPoint::SESSION_KEY));
        $this->assertNull(DeliveryPoint::forDestination($this->destination()));
    }

    /* --------------------------------------------------------------- forDestination */

    public function testForDestinationDevuelveElPuntoSiLaHuellaCoincide(): void
    {
        $this->setPoint();

        $point = DeliveryPoint::forDestination($this->destination());

        $this->assertInstanceOf(GeoPoint::class, $point);
        $this->assertSame(43.3623, $point->lat());
        $this->assertSame(-8.4115, $point->lng());
    }

    public function testCambiarLaCalleInvalidaElPuntoAunqueSeaLaMismaCiudad(): void
    {
        $this->setPoint();

        $this->assertNull(
            DeliveryPoint::forDestination($this->destination(['address_1' => 'Calle Córdoba 12'])),
            'Foco 1: cambiar la calle deja el pin viejo sin valor.'
        );
    }

    public function testCambiarLaCiudadInvalidaElPunto(): void
    {
        $this->setPoint();

        $this->assertNull(DeliveryPoint::forDestination($this->destination(['city' => 'Ourense'])));
    }

    public function testCambiarElPaisInvalidaElPunto(): void
    {
        $this->setPoint();

        $this->assertNull(DeliveryPoint::forDestination($this->destination(['country' => 'PT'])));
    }

    public function testLasVariantesDeMayusculasTildesYEspaciosConservanElPunto(): void
    {
        $this->setPoint();

        $point = DeliveryPoint::forDestination([
            'address_1' => '  CALLE   CORDOBA 10 ',
            'city'      => 'A  CORUNA',
            'country'   => 'es',
        ]);

        $this->assertInstanceOf(GeoPoint::class, $point);
        $this->assertSame(43.3623, $point->lat());
    }

    public function testLaHuellaNoIncluyeLasCoordenadas(): void
    {
        $this->setPoint();
        $first = DeliveryPoint::fingerprint($this->destination());

        $this->setPoint(['lat' => 43.3700, 'lng' => -8.4000]);

        $this->assertSame($first, DeliveryPoint::fingerprint($this->destination()));
        $point = DeliveryPoint::forDestination($this->destination());
        $this->assertNotNull($point);
        $this->assertSame(43.3700, $point->lat());
        $this->assertSame(-8.4000, $point->lng());
    }

    public function testUnEstadoDeSesionCorruptoSeIgnoraYSeRegistra(): void
    {
        $this->session()->set(DeliveryPoint::SESSION_KEY, [
            'lat'         => NAN,
            'lng'         => -8.4115,
            'fingerprint' => DeliveryPoint::fingerprint($this->destination()),
        ]);

        $this->assertNull(DeliveryPoint::forDestination($this->destination()));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] shipping:', $this->logged[0]);
    }

    public function testUnEstadoDeSesionQueNoEsArraySeIgnora(): void
    {
        $this->session()->set(DeliveryPoint::SESSION_KEY, 'roto');

        $this->assertNull(DeliveryPoint::forDestination($this->destination()));
        $this->assertSame([], $this->logged, 'un valor ausente no es un fallo que registrar.');
    }

    /* ------------------------------------------------------------------- paquetes */

    public function testElPaqueteLlevaElPuntoVigente(): void
    {
        $this->setPoint();

        $packages = DeliveryPoint::addPointToPackages($this->packages($this->destination()));

        $this->assertSame(['lat' => 43.3623, 'lng' => -8.4115], $packages[0]['destination']['hwe_point']);
    }

    public function testSinPuntoElPaqueteNoLlevaLaClave(): void
    {
        $packages = DeliveryPoint::addPointToPackages($this->packages($this->destination()));

        $this->assertArrayNotHasKey('hwe_point', $packages[0]['destination']);
    }

    public function testSiCambioLaCalleElPaqueteNoLlevaElPuntoViejo(): void
    {
        $this->setPoint();

        $packages = DeliveryPoint::addPointToPackages(
            $this->packages($this->destination(['address_1' => 'Calle Córdoba 12']))
        );

        $this->assertArrayNotHasKey('hwe_point', $packages[0]['destination']);
    }

    public function testCadaPaqueteLlevaElPuntoSoloSiSuDestinoCoincide(): void
    {
        $this->setPoint();

        $packages = DeliveryPoint::addPointToPackages([
            ['destination' => $this->destination()],
            ['destination' => $this->destination(['city' => 'Ourense'])],
        ]);

        $this->assertSame(['lat' => 43.3623, 'lng' => -8.4115], $packages[0]['destination']['hwe_point']);
        $this->assertArrayNotHasKey('hwe_point', $packages[1]['destination']);
    }

    public function testMoverElPinCambiaElPaquete(): void
    {
        $this->setPoint();
        $before = DeliveryPoint::addPointToPackages($this->packages($this->destination()));

        $this->setPoint(['lat' => 43.3700, 'lng' => -8.4000]);
        $after = DeliveryPoint::addPointToPackages($this->packages($this->destination()));

        $this->assertNotSame(json_encode($before), json_encode($after), 'el hash del paquete debe cambiar con el pin');
    }

    public function testBorrarElPuntoQuitaLaClaveDelPaquete(): void
    {
        $this->setPoint();
        DeliveryPoint::updatePoint(['action' => 'clear_point']);

        $packages = DeliveryPoint::addPointToPackages($this->packages($this->destination()));

        $this->assertArrayNotHasKey('hwe_point', $packages[0]['destination']);
    }

    /* -------------------------------------------------------------- sin sesión y registro */

    public function testSinSesionDeWoocommerceNoRompe(): void
    {
        $this->wc->session = null;

        $this->setPoint();
        DeliveryPoint::updatePoint(['action' => 'clear_point']);

        $this->assertNull(DeliveryPoint::forDestination($this->destination()));
        $this->assertSame(
            $this->packages($this->destination()),
            DeliveryPoint::addPointToPackages($this->packages($this->destination()))
        );
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] shipping:', $this->logged[0]);
    }

    public function testSinLaFuncionWcNoRompe(): void
    {
        Functions\when('WC')->justReturn(null);

        $this->setPoint();

        $this->assertNull(DeliveryPoint::forDestination($this->destination()));
    }

    public function testElUpdateCallbackSeRegistraConElNamespaceDelModulo(): void
    {
        $registered = [];
        Functions\when('woocommerce_store_api_register_update_callback')->alias(
            static function (array $args) use (&$registered): void {
                $registered[] = $args['namespace'];
            }
        );

        DeliveryPoint::registerStoreApi();

        $this->assertSame(['hwe_shipping'], $registered);
    }

    public function testRegisterEnganchaLaExtensionsYElFiltroDePaquetes(): void
    {
        Functions\stubs(['did_action' => static fn (string $hook): int => 0]);
        Actions\expectAdded('woocommerce_blocks_loaded');
        Filters\expectAdded('woocommerce_cart_shipping_packages');

        DeliveryPoint::register();
    }

    public function testRegisterRegistraLaStoreApiSiBlocksYaEstaCargado(): void
    {
        Functions\stubs(['did_action' => static fn (string $hook): int => 'woocommerce_blocks_loaded' === $hook ? 1 : 0]);
        $registered = [];
        Functions\when('woocommerce_store_api_register_update_callback')->alias(
            static function (array $args) use (&$registered): void {
                $registered[] = $args['namespace'];
            }
        );

        DeliveryPoint::register();

        $this->assertSame(['hwe_shipping'], $registered);
    }
}

/** Contenedor mínimo de `WC()`: la sesión que guarda el pin. */
final class TestDeliveryPointWc
{
    public ?TestDeliveryPointSession $session;

    public function __construct()
    {
        $this->session = new TestDeliveryPointSession();
    }
}

/** Sesión de WooCommerce en memoria. */
final class TestDeliveryPointSession
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
