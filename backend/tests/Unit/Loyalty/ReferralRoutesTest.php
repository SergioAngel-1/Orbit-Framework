<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rutas internas de referidos (`hwe/v1/loyalty/…`): puerta del BFF, 404 con el módulo o
 * «Referidos» apagados y forma mínima de las respuestas.
 *
 * WordPress es quien habla con el BFF, nunca el navegador: sin `X-HWE-Internal-Secret` la
 * ruta no existe. `validate-code` es la única pensada para una comprobación pública (la
 * consume el BFF del registro) y por eso devuelve SOLO `{ valid }`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReferralRoutesTest extends TestCase
{
    private const USER = 7;
    private const OTHER = 8;
    private const NON_PARTICIPANT = 99;

    /** @var array<string,mixed> Opción `hwe_config`. */
    private array $config = [];
    /** @var array<int,object> */
    private array $users = [];
    /** @var list<string> */
    private array $activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'    => '1',
                'referrals_enabled' => '1',
                'allowed_roles'     => 'customer, subscriber',
            ],
        ];
        $this->users = [
            self::USER => (object) [
                'ID' => self::USER, 'roles' => ['customer'], 'display_name' => 'Ana Visible',
                'user_login' => 'ana_login', 'user_email' => 'ana@example.com',
            ],
            self::OTHER => (object) [
                'ID' => self::OTHER, 'roles' => ['customer'], 'display_name' => 'Beto Visible',
                'user_login' => 'beto_login', 'user_email' => 'beto@example.com',
            ],
            self::NON_PARTICIPANT => (object) [
                'ID' => self::NON_PARTICIPANT, 'roles' => ['administrator'], 'display_name' => 'Admin',
                'user_login' => 'admin_login', 'user_email' => 'admin@example.com',
            ],
        ];
        $this->activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
        $this->logged        = [];

        $GLOBALS['wpdb'] = new TestReferralRouteWpdb();
        $GLOBALS['wpdb']->referrals = [
            1 => [
                'id' => 1, 'user_id' => self::USER, 'code' => 'ABCDEFGH',
                'referrer_id' => null, 'status' => 'pending',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => null,
            ],
            2 => [
                'id' => 2, 'user_id' => self::OTHER, 'code' => 'JKMNPQRS',
                'referrer_id' => null, 'status' => 'pending',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => null,
            ],
            3 => [
                'id' => 3, 'user_id' => self::NON_PARTICIPANT, 'code' => 'ADMNCDE2',
                'referrer_id' => null, 'status' => 'pending',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => null,
            ],
        ];

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
            '__'                  => static fn (string $text): string => $text,
        ]);
        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return $this->activePlugins;
            }
            return $default;
        });
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('update_user_meta')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('time')->justReturn(1_800_000_000);
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    /** @return array<string,array<string,mixed>> Ruta completa => argumentos registrados. */
    private function registeredRoutes(): array
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): bool {
                $routes[$namespace . $route] = $args;
                return true;
            }
        );

        \HWE_Loyalty_ReferralRoutes::registerRoutes();

        return $routes;
    }

    public function test_register_hooks_the_routes_on_rest_api_init(): void
    {
        Actions\expectAdded('rest_api_init')
            ->once()
            ->with([\HWE_Loyalty_ReferralRoutes::class, 'registerRoutes']);

        \HWE_Loyalty_ReferralRoutes::register();
    }

    public function test_it_registers_the_three_internal_routes_with_their_methods(): void
    {
        $routes = $this->registeredRoutes();

        $this->assertSame(
            ['hwe/v1/loyalty/attribute', 'hwe/v1/loyalty/validate-code', 'hwe/v1/loyalty/referral-code'],
            array_keys($routes)
        );
        $this->assertSame('POST', $routes['hwe/v1/loyalty/attribute']['methods']);
        $this->assertSame('GET', $routes['hwe/v1/loyalty/validate-code']['methods']);
        $this->assertSame('GET', $routes['hwe/v1/loyalty/referral-code']['methods']);
        foreach ($routes as $route => $args) {
            $this->assertIsCallable($args['permission_callback'], $route . ' debe exigir el secreto interno.');
        }
    }

    public function test_without_the_internal_secret_every_route_fails_closed(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(false);
        $request = new \WP_REST_Request();

        foreach ($this->registeredRoutes() as $route => $args) {
            $this->assertFalse($args['permission_callback']($request), $route . ' debe fallar cerrada.');
        }
    }

    public function test_with_the_secret_and_the_feature_on_the_routes_are_allowed(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        foreach ($this->registeredRoutes() as $route => $args) {
            $this->assertTrue($args['permission_callback']($request), $route . ' debe atender al BFF.');
        }
    }

    public function test_the_module_or_the_referrals_switch_off_answers_404(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        $this->config['loyalty']['referrals_enabled'] = '0';
        $closed = $this->registeredRoutes()['hwe/v1/loyalty/attribute']['permission_callback']($request);
        $this->assertInstanceOf(\WP_Error::class, $closed);
        $this->assertSame('rest_no_route', $closed->get_error_code());
        $this->assertSame(['status' => 404], $closed->get_error_data());

        $this->config['loyalty']['referrals_enabled'] = '1';
        $this->config['loyalty']['points_enabled']    = '0';
        $closed = $this->registeredRoutes()['hwe/v1/loyalty/validate-code']['permission_callback']($request);
        $this->assertInstanceOf(\WP_Error::class, $closed);
        $this->assertSame(['status' => 404], $closed->get_error_data());

        $this->config['loyalty']['points_enabled'] = '1';
        $this->activePlugins = [];
        $closed = $this->registeredRoutes()['hwe/v1/loyalty/referral-code']['permission_callback']($request);
        $this->assertInstanceOf(\WP_Error::class, $closed);
        $this->assertSame(['status' => 404], $closed->get_error_data());
    }

    public function test_validate_code_returns_only_valid(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);

        $ok = \HWE_Loyalty_ReferralRoutes::handleValidateCode(new \WP_REST_Request(['code' => 'abcdefgh']));
        $this->assertSame(200, $ok->get_status());
        $this->assertSame(['valid' => true], $ok->get_data(), 'No se expone el dueño del código.');

        $unknown = \HWE_Loyalty_ReferralRoutes::handleValidateCode(new \WP_REST_Request(['code' => 'ZZZZZZZZ']));
        $this->assertSame(['valid' => false], $unknown->get_data());

        $nonParticipant = \HWE_Loyalty_ReferralRoutes::handleValidateCode(new \WP_REST_Request(['code' => 'ADMNCDE2']));
        $this->assertSame(['valid' => false], $nonParticipant->get_data());

        $missing = \HWE_Loyalty_ReferralRoutes::handleValidateCode(new \WP_REST_Request([]));
        $this->assertSame(400, $missing->get_status());
        $this->assertSame(['valid' => false], $missing->get_data());
    }

    public function test_referral_code_returns_the_code_of_the_user(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);

        $response = \HWE_Loyalty_ReferralRoutes::handleReferralCode(
            new \WP_REST_Request(['user_id' => self::USER])
        );
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['code' => 'ABCDEFGH'], $response->get_data());

        $invalid = \HWE_Loyalty_ReferralRoutes::handleReferralCode(new \WP_REST_Request([]));
        $this->assertSame(400, $invalid->get_status());
        $this->assertSame(['code' => ''], $invalid->get_data());

        $nonParticipant = \HWE_Loyalty_ReferralRoutes::handleReferralCode(
            new \WP_REST_Request(['user_id' => self::NON_PARTICIPANT])
        );
        $this->assertSame(['code' => ''], $nonParticipant->get_data());
    }

    public function test_attribute_returns_the_engine_result(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);

        $invalid = \HWE_Loyalty_ReferralRoutes::handleAttribute(
            new \WP_REST_Request(['user_id' => self::USER, 'code' => 'ZZZZZZZZ'])
        );
        $this->assertSame(200, $invalid->get_status());
        $this->assertSame(['result' => 'ignored:invalid_code'], $invalid->get_data());

        $self = \HWE_Loyalty_ReferralRoutes::handleAttribute(
            new \WP_REST_Request(['user_id' => self::USER, 'code' => 'abcdefgh'])
        );
        $this->assertSame(['result' => 'ignored:self'], $self->get_data());

        $missing = \HWE_Loyalty_ReferralRoutes::handleAttribute(new \WP_REST_Request(['code' => 'ABCDEFGH']));
        $this->assertSame(400, $missing->get_status());
        $this->assertSame(['result' => 'ignored:invalid_code'], $missing->get_data());
    }
}

/** Doble mínimo de `$wpdb` para las rutas: solo lee filas de referidos por usuario o código. */
final class TestReferralRouteWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $referrals = [];
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode($args) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$table, $data];
        $id = count($this->referrals) + 1;
        $this->insert_id = $id;
        $this->referrals[$id] = $data + ['id' => $id];
        return 1;
    }

    public function query(string $sql): int|false
    {
        return 1;
    }

    public function get_row(string $sql): ?object
    {
        $args = $this->args($sql);
        if (str_contains($sql, 'WHERE code = %s')) {
            $code = (string) ($args[0] ?? '');
            foreach ($this->referrals as $row) {
                if ((string) $row['code'] === $code) {
                    return (object) $row;
                }
            }
            return null;
        }
        $userId = (int) ($args[0] ?? 0);
        foreach ($this->referrals as $row) {
            if ((int) $row['user_id'] === $userId) {
                return (object) $row;
            }
        }
        return null;
    }

    public function get_var(string $sql): string|int|null
    {
        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        return [];
    }

    /** @return array<int,string> */
    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }
}
