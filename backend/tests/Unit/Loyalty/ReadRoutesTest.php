<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rutas internas de lectura (`hwe/v1/loyalty/summary|transactions|referrals`), solo para el BFF.
 *
 * Se prueba el contrato, no el SQL: la puerta del secreto interno, el 404 con el módulo o el
 * interruptor apagado, la validación de `user_id` y `level`, el recorte de `per_page`, la
 * paginación, las fechas ISO y la forma de `data`. Las respuestas de un usuario que no participa
 * son vacías o a cero, nunca un error: una ruta interna no puede servir para enumerar cuentas.
 *
 * La red no expone `user_id`, email ni login de los referidos, y una transferencia solo lleva el
 * nombre visible de la otra parte. El texto de cada movimiento se compone en el BFF (Tarea 13):
 * aquí solo viaja `type` + `data` estructurado y estable.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReadRoutesTest extends TestCase
{
    private const USER = 7;
    private const OTHER = 8;
    private const THIRD = 9;
    private const FOURTH = 10;
    private const NON_PARTICIPANT = 11;
    private const MISSING = 999;

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
                'level2_enabled'    => '1',
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
            self::THIRD => (object) [
                'ID' => self::THIRD, 'roles' => ['subscriber'], 'display_name' => 'Carla Visible',
                'user_login' => 'carla_login', 'user_email' => 'carla@example.com',
            ],
            self::FOURTH => (object) [
                'ID' => self::FOURTH, 'roles' => ['customer'], 'display_name' => 'Dani Visible',
                'user_login' => 'dani_login', 'user_email' => 'dani@example.com',
            ],
            self::NON_PARTICIPANT => (object) [
                'ID' => self::NON_PARTICIPANT, 'roles' => ['administrator'], 'display_name' => 'Admin',
                'user_login' => 'admin_login', 'user_email' => 'admin@example.com',
            ],
        ];
        $this->activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
        $this->logged        = [];

        $wpdb = new TestReadRoutesWpdb();
        $wpdb->balances[self::USER] = ['balance' => 130, 'earned' => 180, 'used' => 70];
        $wpdb->expiring[self::USER] = ['points' => 40, 'date' => '2026-11-15 00:00:00'];
        $wpdb->ledger = [
            1 => [
                'id' => 1, 'user_id' => self::USER, 'points' => 100, 'type' => 'cashback',
                'remaining_points' => 100, 'ref_type' => 'order', 'ref_id' => '55',
                'data' => '{"order":55}', 'note' => null,
                'created_at' => '2026-10-01 10:00:00', 'expires_at' => '2026-11-01 10:00:00',
            ],
            2 => [
                'id' => 2, 'user_id' => self::USER, 'points' => 25, 'type' => 'referral_commission_l1',
                'remaining_points' => 25, 'ref_type' => 'user', 'ref_id' => (string) self::FOURTH,
                'data' => '{"order":66}', 'note' => null,
                'created_at' => '2026-10-02 10:00:00', 'expires_at' => null,
            ],
            3 => [
                'id' => 3, 'user_id' => self::USER, 'points' => -50, 'type' => 'transfer_out',
                'remaining_points' => null, 'ref_type' => 'user', 'ref_id' => (string) self::OTHER,
                'data' => '{"to":8,"fee":5,"received":50}', 'note' => null,
                'created_at' => '2026-10-03 10:00:00', 'expires_at' => null,
            ],
            4 => [
                'id' => 4, 'user_id' => self::USER, 'points' => 30, 'type' => 'transfer_in',
                'remaining_points' => 30, 'ref_type' => 'user', 'ref_id' => (string) self::THIRD,
                'data' => '{"from":9,"fee":0}', 'note' => null,
                'created_at' => '2026-10-04 10:00:00', 'expires_at' => null,
            ],
            5 => [
                'id' => 5, 'user_id' => self::USER, 'points' => 20, 'type' => 'admin_credit',
                'remaining_points' => 20, 'ref_type' => null, 'ref_id' => null,
                'data' => '{"order":3}', 'note' => 'Ajuste manual',
                'created_at' => '2026-10-05 10:00:00', 'expires_at' => null,
            ],
            6 => [
                'id' => 6, 'user_id' => self::USER, 'points' => 5, 'type' => 'custom_entry_type',
                'remaining_points' => 5, 'ref_type' => null, 'ref_id' => null,
                'data' => '{"email":"filtrado@example.com","login":"filtrado_login"}', 'note' => null,
                'created_at' => '2026-10-06 10:00:00', 'expires_at' => null,
            ],
        ];
        $wpdb->referrals = [
            1 => [
                'id' => 1, 'user_id' => self::OTHER, 'code' => 'ABCDEFGH',
                'referrer_id' => self::USER, 'status' => 'confirmed',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => '2026-01-02 10:00:00',
            ],
            2 => [
                'id' => 2, 'user_id' => self::THIRD, 'code' => 'JKMNPQRS',
                'referrer_id' => self::USER, 'status' => 'pending',
                'attributed_at' => '2026-01-02 10:00:00', 'confirmed_at' => null,
            ],
            3 => [
                'id' => 3, 'user_id' => self::FOURTH, 'code' => 'TUVWXY34',
                'referrer_id' => self::OTHER, 'status' => 'pending',
                'attributed_at' => '2026-01-03 10:00:00', 'confirmed_at' => null,
            ],
        ];
        $GLOBALS['wpdb'] = $wpdb;

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
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('time')->justReturn(1_800_000_000);
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    private function wpdb(): TestReadRoutesWpdb
    {
        return $GLOBALS['wpdb'];
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

        \HWE_Loyalty_ReadRoutes::registerRoutes();

        return $routes;
    }

    public function test_register_hooks_the_routes_on_rest_api_init(): void
    {
        Actions\expectAdded('rest_api_init')
            ->once()
            ->with([\HWE_Loyalty_ReadRoutes::class, 'registerRoutes']);

        \HWE_Loyalty_ReadRoutes::register();
    }

    public function test_it_registers_the_three_read_routes_with_get(): void
    {
        $routes = $this->registeredRoutes();

        $this->assertSame(
            ['hwe/v1/loyalty/summary', 'hwe/v1/loyalty/transactions', 'hwe/v1/loyalty/referrals'],
            array_keys($routes)
        );
        foreach ($routes as $route => $args) {
            $this->assertSame('GET', $args['methods'], $route . ' solo lee.');
            $this->assertIsCallable($args['permission_callback'], $route . ' debe exigir el secreto interno.');
            $this->assertIsCallable($args['callback'], $route . ' necesita manejador.');
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

    public function test_with_the_secret_and_the_switches_on_the_routes_are_allowed(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        foreach ($this->registeredRoutes() as $route => $args) {
            $this->assertTrue($args['permission_callback']($request), $route . ' debe atender al BFF.');
        }
    }

    public function test_the_module_or_the_switch_off_answers_404(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        $this->config['loyalty']['points_enabled'] = '0';
        $routes = $this->registeredRoutes();
        foreach (['hwe/v1/loyalty/summary', 'hwe/v1/loyalty/transactions'] as $route) {
            $closed = $routes[$route]['permission_callback']($request);
            $this->assertInstanceOf(\WP_Error::class, $closed, $route . ' se cierra sin Puntos.');
            $this->assertSame('rest_no_route', $closed->get_error_code());
            $this->assertSame(['status' => 404], $closed->get_error_data());
        }

        $this->config['loyalty']['points_enabled']    = '1';
        $this->config['loyalty']['referrals_enabled'] = '0';
        $closed = $this->registeredRoutes()['hwe/v1/loyalty/referrals']['permission_callback']($request);
        $this->assertInstanceOf(\WP_Error::class, $closed);
        $this->assertSame(['status' => 404], $closed->get_error_data());

        $this->config['loyalty']['referrals_enabled'] = '1';
        $this->activePlugins = [];
        foreach ($this->registeredRoutes() as $route => $args) {
            $closed = $args['permission_callback']($request);
            $this->assertInstanceOf(\WP_Error::class, $closed, $route . ' se cierra con el módulo apagado.');
            $this->assertSame(['status' => 404], $closed->get_error_data());
        }
    }

    public function test_user_id_is_mandatory_and_positive(): void
    {
        foreach ([null, 0, -3] as $invalid) {
            $params = null === $invalid ? [] : ['user_id' => $invalid];

            $summary = \HWE_Loyalty_ReadRoutes::handleSummary(new \WP_REST_Request($params));
            $this->assertSame(400, $summary->get_status());
            $this->assertSame(['error' => 'invalid_request'], $summary->get_data());

            $transactions = \HWE_Loyalty_ReadRoutes::handleTransactions(new \WP_REST_Request($params));
            $this->assertSame(400, $transactions->get_status());
            $this->assertSame(['error' => 'invalid_request'], $transactions->get_data());

            $referrals = \HWE_Loyalty_ReadRoutes::handleReferrals(new \WP_REST_Request($params));
            $this->assertSame(400, $referrals->get_status());
            $this->assertSame(['error' => 'invalid_request'], $referrals->get_data());
        }
    }

    public function test_summary_returns_the_balance_and_the_next_expiry(): void
    {
        $response = \HWE_Loyalty_ReadRoutes::handleSummary(
            new \WP_REST_Request(['user_id' => self::USER])
        );

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            [
                'participant' => true,
                'balance'     => 130,
                'earned'      => 180,
                'used'        => 70,
                'expiring'    => ['points' => 40, 'date' => '2026-11-15T00:00:00Z'],
            ],
            $response->get_data()
        );
    }

    public function test_a_user_without_participation_is_not_enumerable(): void
    {
        foreach ([self::NON_PARTICIPANT, self::MISSING] as $userId) {
            $summary = \HWE_Loyalty_ReadRoutes::handleSummary(
                new \WP_REST_Request(['user_id' => $userId])
            );
            $this->assertSame(200, $summary->get_status());
            $this->assertSame(
                [
                    'participant' => false,
                    'balance'     => 0,
                    'earned'      => 0,
                    'used'        => 0,
                    'expiring'    => ['points' => 0, 'date' => null],
                ],
                $summary->get_data()
            );

            $transactions = \HWE_Loyalty_ReadRoutes::handleTransactions(
                new \WP_REST_Request(['user_id' => $userId])
            );
            $this->assertSame(200, $transactions->get_status());
            $this->assertSame(['items' => [], 'total' => 0, 'pages' => 0], $transactions->get_data());

            $referrals = \HWE_Loyalty_ReadRoutes::handleReferrals(
                new \WP_REST_Request(['user_id' => $userId])
            );
            $this->assertSame(200, $referrals->get_status());
            $this->assertSame(
                [
                    'items'  => [],
                    'total'  => 0,
                    'pages'  => 0,
                    'totals' => ['level1' => 0, 'level2' => 0, 'pending' => 0, 'earned' => 0],
                ],
                $referrals->get_data()
            );
        }

        $this->assertSame([], $this->wpdb()->historyLimits, 'No se lee el libro de quien no participa.');
    }

    public function test_transactions_page_shape_dates_and_curated_data(): void
    {
        $first = \HWE_Loyalty_ReadRoutes::handleTransactions(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 1, 'per_page' => 2])
        );

        $this->assertSame(200, $first->get_status());
        $page = $first->get_data();
        $this->assertSame(6, $page['total']);
        $this->assertSame(3, $page['pages']);
        $this->assertCount(2, $page['items']);

        $byId = array_column($page['items'], null, 'id');
        $this->assertSame(
            ['id', 'type', 'points', 'data', 'created_at', 'expires_at'],
            array_keys($byId[6])
        );
        $this->assertSame([], $byId[6]['data'], 'Un tipo ajeno no filtra sus datos.');
        $this->assertSame(['note' => 'Ajuste manual'], $byId[5]['data'], 'El ajuste manual lleva su nota.');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $byId[6]['created_at']
        );
        $this->assertNull($byId[6]['expires_at']);

        $second = \HWE_Loyalty_ReadRoutes::handleTransactions(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 2, 'per_page' => 2])
        )->get_data();
        $byId = array_column($second['items'], null, 'id');

        $this->assertSame(
            ['counterpart_name' => 'Carla Visible', 'fee' => 0],
            $byId[4]['data'],
            'Una entrada de transferencia lleva el nombre visible de la otra parte.'
        );
        $this->assertSame(
            ['counterpart_name' => 'Beto Visible', 'fee' => 5],
            $byId[3]['data']
        );
        $this->assertArrayNotHasKey('from', $byId[4]['data']);
        $this->assertArrayNotHasKey('to', $byId[3]['data']);
        $this->assertArrayNotHasKey('user_id', $byId[4]);

        $third = \HWE_Loyalty_ReadRoutes::handleTransactions(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 3, 'per_page' => 2])
        )->get_data();
        $byId = array_column($third['items'], null, 'id');
        $this->assertSame(['order' => 66], $byId[2]['data']);
        $this->assertSame(['order' => 55], $byId[1]['data']);
        $this->assertSame('2026-11-01T10:00:00Z', $byId[1]['expires_at']);

        $json = json_encode([$first->get_data(), $second, $third]) ?: '';
        $this->assertStringNotContainsString('@', $json);
        $this->assertStringNotContainsString('_login', $json);
        $this->assertStringNotContainsString('"ref"', $json);

        $this->assertSame(
            [[self::USER, 2, 0], [self::USER, 2, 2], [self::USER, 2, 4]],
            $this->wpdb()->historyLimits
        );
    }

    public function test_transactions_page_size_is_clamped_to_one_hundred(): void
    {
        $response = \HWE_Loyalty_ReadRoutes::handleTransactions(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => '0', 'per_page' => '5000'])
        );

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $response->get_data()['pages'], 'Con 6 movimientos y 100 por página hay 1 página.');
        $this->assertSame([[self::USER, 100, 0]], $this->wpdb()->historyLimits);

        \HWE_Loyalty_ReadRoutes::handleTransactions(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 1, 'per_page' => 0])
        );
        $this->assertSame([self::USER, 1, 0], $this->wpdb()->historyLimits[1], 'per_page mínimo 1.');
    }

    public function test_referrals_lists_both_levels_without_personal_identifiers(): void
    {
        $levelOne = \HWE_Loyalty_ReadRoutes::handleReferrals(
            new \WP_REST_Request(['user_id' => self::USER, 'level' => '1', 'page' => 1, 'per_page' => 20])
        );

        $this->assertSame(200, $levelOne->get_status());
        $page = $levelOne->get_data();
        $this->assertSame(2, $page['total']);
        $this->assertSame(1, $page['pages']);
        $this->assertCount(2, $page['items']);
        $this->assertSame(
            ['name', 'date', 'status', 'points_generated'],
            array_keys($page['items'][0])
        );
        $this->assertSame(
            // El `transfer_in` de Carla (30) no es «generado por la red»: solo los 25 de comisión.
            ['level1' => 2, 'level2' => 1, 'pending' => 2, 'earned' => 25],
            $page['totals']
        );

        $byName = array_column($page['items'], null, 'name');
        $this->assertSame('Beto Visible', $byName['Beto Visible']['name']);
        $this->assertSame('confirmed', $byName['Beto Visible']['status']);
        $this->assertSame(0, $byName['Beto Visible']['points_generated']);
        $this->assertSame('pending', $byName['Carla Visible']['status']);
        $this->assertSame(0, $byName['Carla Visible']['points_generated'], 'Una transferencia no cuenta como generado.');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $byName['Beto Visible']['date']
        );

        $levelTwo = \HWE_Loyalty_ReadRoutes::handleReferrals(
            new \WP_REST_Request(['user_id' => self::USER, 'level' => '2'])
        )->get_data();
        $this->assertSame(1, $levelTwo['total']);
        $this->assertSame('Dani Visible', $levelTwo['items'][0]['name']);
        $this->assertSame(25, $levelTwo['items'][0]['points_generated']);

        $json = json_encode([$page, $levelTwo]) ?: '';
        $this->assertStringNotContainsString('@', $json);
        $this->assertStringNotContainsString('_login', $json);
        $this->assertStringNotContainsString('user_id', $json);
    }

    public function test_referrals_level_two_with_the_second_level_off_is_an_empty_page(): void
    {
        $this->config['loyalty']['level2_enabled'] = '0';

        $response = \HWE_Loyalty_ReadRoutes::handleReferrals(
            new \WP_REST_Request(['user_id' => self::USER, 'level' => 2])
        );

        $this->assertSame(200, $response->get_status(), 'La ruta existe; el nivel 2 está apagado.');
        $this->assertSame(
            [
                'items'  => [],
                'total'  => 0,
                'pages'  => 0,
                'totals' => ['level1' => 2, 'level2' => 1, 'pending' => 2, 'earned' => 25],
            ],
            $response->get_data(),
            'Los totales conservan el histórico de la red.'
        );
    }

    public function test_referrals_rejects_an_invalid_level(): void
    {
        foreach (['3', 'x', '-1'] as $level) {
            $response = \HWE_Loyalty_ReadRoutes::handleReferrals(
                new \WP_REST_Request(['user_id' => self::USER, 'level' => $level])
            );
            $this->assertSame(400, $response->get_status(), 'Nivel «' . $level . '» inválido.');
            $this->assertSame(['error' => 'invalid_level'], $response->get_data());
        }

        $default = \HWE_Loyalty_ReadRoutes::handleReferrals(
            new \WP_REST_Request(['user_id' => self::USER])
        )->get_data();
        $this->assertSame(2, $default['total'], 'Sin level, se asume el nivel 1.');
    }

    public function test_referrals_page_size_is_clamped_to_one_hundred(): void
    {
        \HWE_Loyalty_ReadRoutes::handleReferrals(
            new \WP_REST_Request(['user_id' => self::USER, 'level' => 1, 'per_page' => 5000])
        );

        $this->assertSame([[self::USER, 1, 100, 0]], $this->wpdb()->networkLimits);
    }
}

/**
 * Doble de `$wpdb` con estado para las tres lecturas.
 *
 * Entiende solo las sentencias que emiten `HWE_Loyalty_Ledger` (saldo, caducidad próxima e
 * historial) y `HWE_Loyalty_Referrals` (red paginada y totales): filtra, ordena y corta como el
 * SQL real. Cualquier otra consulta devuelve vacío, igual que una base de datos sin filas.
 */
final class TestReadRoutesWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var array<int,array{points:int,date:?string}> */
    public array $expiring = [];
    /** @var array<int,array<string,mixed>> */
    public array $ledger = [];
    /** @var array<int,array<string,mixed>> */
    public array $referrals = [];
    /** @var list<array{0:int,1:int,2:int}> argumentos de LIMIT/OFFSET del historial */
    public array $historyLimits = [];
    /** @var list<array{0:int,1:int,2:int,3:int}> argumentos de la red */
    public array $networkLimits = [];
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode($args) . ' */';
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'COALESCE(SUM(remaining_points)')) {
            $userId = (int) ($args[0] ?? 0);
            $row    = $this->expiring[$userId] ?? ['points' => 0, 'date' => null];
            return (object) $row;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->balances[$userId])
                ? (object) ($this->balances[$userId] + ['user_id' => $userId])
                : null;
        }

        return null;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'r.status = %s')) {
            $status = (string) ($args[0] ?? '');
            $userId = (int) ($args[1] ?? 0);
            $count  = 0;
            foreach ($this->referrals as $row) {
                if ((string) ($row['status'] ?? '') !== $status) {
                    continue;
                }
                $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
                if ($referrer === $userId || $this->parentOf($referrer) === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        if (str_contains($sql, 'CAST(l.ref_id AS UNSIGNED)')) {
            return $this->networkEarned((int) ($args[0] ?? 0), (string) ($args[1] ?? ''), array_map('strval', array_slice($args, 4)));
        }

        if (str_contains($sql, 'COALESCE(SUM(points)')) {
            $userId  = (int) ($args[0] ?? 0);
            $refType = (string) ($args[1] ?? '');
            $refId   = (string) ($args[2] ?? '');
            $sum     = 0;
            foreach ($this->ledger as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId
                    && (int) ($row['points'] ?? 0) > 0
                    && (string) ($row['ref_type'] ?? '') === $refType
                    && (string) ($row['ref_id'] ?? '') === $refId) {
                    $sum += (int) $row['points'];
                }
            }
            return $sum;
        }

        if (str_contains($sql, 'COUNT(*)')) {
            $userId = (int) ($args[0] ?? 0);

            if (str_contains($sql, 'WHERE user_id = %d')) {
                return count(array_filter(
                    $this->ledger,
                    static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId
                ));
            }

            if (str_contains($sql, 'INNER JOIN')) {
                $count = 0;
                foreach ($this->referrals as $row) {
                    if ($this->parentOf(null === $row['referrer_id'] ? null : (int) $row['referrer_id']) === $userId) {
                        $count++;
                    }
                }
                return $count;
            }

            $count = 0;
            foreach ($this->referrals as $row) {
                if (null !== $row['referrer_id'] && (int) $row['referrer_id'] === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'GROUP BY ref_id')) {
            // Puntos generados por cada referido de la página (`Referrals::generatedBy`):
            // [referidor, 'user', ...ids, ...5 tipos de ganancia por referido].
            $groupArgs = $this->args($sql);
            $owner     = (int) ($groupArgs[0] ?? 0);
            $refType   = (string) ($groupArgs[1] ?? '');
            $rest      = array_slice($groupArgs, 2);
            $types     = array_map('strval', array_slice($rest, -5));
            $ids       = array_map('strval', array_slice($rest, 0, -5));
            $sums      = [];
            foreach ($this->ledger as $entry) {
                $refId = (string) ($entry['ref_id'] ?? '');
                if ((int) ($entry['user_id'] ?? 0) === $owner
                    && (int) ($entry['points'] ?? 0) > 0
                    && (string) ($entry['ref_type'] ?? '') === $refType
                    && in_array($refId, $ids, true)
                    && in_array((string) ($entry['type'] ?? ''), $types, true)) {
                    $sums[$refId] = ($sums[$refId] ?? 0) + (int) $entry['points'];
                }
            }
            $out = [];
            foreach ($sums as $refId => $sum) {
                $out[] = (object) ['ref_id' => (string) $refId, 'generated' => $sum];
            }
            return $out;
        }

        $args = $this->args($sql);

        if (str_contains($sql, 'ORDER BY created_at DESC')) {
            $userId  = (int) ($args[0] ?? 0);
            $perPage = (int) ($args[1] ?? 0);
            $offset  = (int) ($args[2] ?? 0);
            $this->historyLimits[] = [$userId, $perPage, $offset];

            $rows = array_values(array_filter(
                $this->ledger,
                static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId
            ));
            usort($rows, static function (array $left, array $right): int {
                return [(string) $right['created_at'], (int) $right['id']]
                    <=> [(string) $left['created_at'], (int) $left['id']];
            });

            return array_map(
                static fn (array $row): object => (object) $row,
                array_slice($rows, $offset, $perPage)
            );
        }

        if (str_contains($sql, 'attributed_at DESC')) {
            $userId  = (int) ($args[0] ?? 0);
            $perPage = (int) ($args[1] ?? 0);
            $offset  = (int) ($args[2] ?? 0);
            $this->networkLimits[] = [$userId, str_contains($sql, 'INNER JOIN') ? 2 : 1, $perPage, $offset];

            $rows = [];
            foreach ($this->referrals as $row) {
                $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
                $parent   = $this->parentOf($referrer);
                $matches  = str_contains($sql, 'INNER JOIN')
                    ? $parent === $userId
                    : $referrer === $userId;
                if ($matches) {
                    $rows[] = $row;
                }
            }
            usort($rows, static function (array $left, array $right): int {
                return [(string) $right['attributed_at'], (int) $right['user_id']]
                    <=> [(string) $left['attributed_at'], (int) $left['user_id']];
            });

            return array_map(
                static fn (array $row): object => (object) $row,
                array_slice($rows, $offset, $perPage)
            );
        }

        return [];
    }

    /** @return list<int> Usuarios de la red directa y de segundo nivel. */
    private function networkMembers(int $userId): array
    {
        $members = [];
        foreach ($this->referrals as $row) {
            $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
            if ($referrer === $userId || $this->parentOf($referrer) === $userId) {
                $members[] = (int) $row['user_id'];
            }
        }
        return $members;
    }

    /** @param list<string> $types Tipos de ganancia por referido; vacío = sin filtro. */
    private function networkEarned(int $userId, string $refType, array $types = []): int
    {
        $members = array_map('strval', $this->networkMembers($userId));
        $sum     = 0;
        foreach ($this->ledger as $row) {
            if ((int) ($row['user_id'] ?? 0) === $userId
                && (int) ($row['points'] ?? 0) > 0
                && (string) ($row['ref_type'] ?? '') === $refType
                && ([] === $types || in_array((string) ($row['type'] ?? ''), $types, true))
                && in_array((string) ($row['ref_id'] ?? ''), $members, true)) {
                $sum += (int) $row['points'];
            }
        }
        return $sum;
    }

    /** Referidor de un usuario, para resolver el segundo nivel. */
    private function parentOf(?int $userId): ?int
    {
        if (null === $userId) {
            return null;
        }
        foreach ($this->referrals as $row) {
            if ((int) $row['user_id'] === $userId) {
                return null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
            }
        }
        return null;
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
