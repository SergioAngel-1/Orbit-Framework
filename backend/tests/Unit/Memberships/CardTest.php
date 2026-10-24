<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Carné digital (`HWE_Memberships_Card` y sus rutas internas `hwe/v1/memberships/card|verify`).
 *
 * Se prueba el contrato, no el SQL literal:
 *  · el token se emite con alta entropía y en la base de datos solo queda su SHA-256;
 *  · como mucho hay un token vivo por usuario (emitir otro revoca el anterior);
 *  · caduca según `card_token_ttl_hours` y solo se emite con nivel efectivo;
 *  · cualquier evento del ciclo revoca el carné;
 *  · `verify()` responde lo mínimo, enmascara el nombre y, solo con `customer-identity`
 *    activo, el documento; un token inexistente, caducado o revocado no se distingue;
 *  · las rutas exigen el secreto interno del BFF y responden 404 con el carné apagado.
 *
 * El doble de `$wpdb` mantiene solo lo que tocan el carné y `Members::state()`: concesiones
 * vivas, la fila del socio y los tokens, con transacciones simuladas por foto del estado.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardTest extends TestCase
{
    private const USER  = 7;
    private const OTHER = 8;
    private const THIRD = 9;
    private const LEVEL = 101;
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private int $now = 1_800_000_000;
    /** @var array<string,mixed> Opción `hwe_config`. */
    private array $config = [];
    /** @var list<string> */
    private array $activePlugins = [];
    /** @var array<int,object> */
    private array $users = [];
    /** @var array<int,array<string,string>> */
    private array $userMeta = [];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $levelMeta = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now           = 1_800_000_000;
        $this->config        = [];
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php'];
        $this->users         = [
            self::USER => (object) [
                'ID'           => self::USER,
                'user_login'   => 'ana_login',
                'user_email'   => 'ana@example.com',
                'display_name' => 'Ana Pérez',
                'first_name'   => 'Ana',
                'last_name'    => 'Pérez',
            ],
            self::OTHER => (object) [
                'ID'           => self::OTHER,
                'user_login'   => 'beto_login',
                'user_email'   => 'beto@example.com',
                'display_name' => 'beto_login',
                'first_name'   => 'Beto',
                'last_name'    => 'Gómez Ruiz',
            ],
            self::THIRD => (object) [
                'ID'           => self::THIRD,
                'user_login'   => 'carla_login',
                'user_email'   => 'carla@example.com',
                'display_name' => 'carla_login',
                'first_name'   => '',
                'last_name'    => '',
            ],
        ];
        $this->userMeta = [];
        $this->levelPosts = [];
        $this->levelMeta  = [];
        $this->logged     = [];

        $GLOBALS['wpdb'] = new TestMembershipsCardWpdb();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Card.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Rest/CardRoutes.php');
        \HWE_Memberships_Events::clear();

        $this->makeLevel(self::LEVEL, 5, 'Nivel Socio', 'nivel-socio');

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return $this->activePlugins;
            }
            return $default;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_user_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->userMeta[$id][$key] ?? ''
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            '__' => static fn (string $text): string => $text,
        ]);
    }

    private function wpdb(): TestMembershipsCardWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function makeLevel(int $id, int $rank, string $name, string $slug): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = 'publish';
        $post->post_title   = $name;
        $post->post_name    = $slug;
        $post->post_content = '';
        $this->levelPosts[ $id ] = $post;
        $this->levelMeta[ $id ]  = [ \HWE_Memberships_Levels::RANK_META => (string) $rank ];
    }

    /** Configura el módulo con el carné encendido y los valores del contrato. */
    private function enableCard(array $overrides = []): void
    {
        $this->config['memberships'] = array_merge(
            [
                'card_enabled'         => true,
                'card_verify_path'     => '/verify',
                'member_number_prefix' => 'SOC-',
                'card_token_ttl_hours' => '24',
            ],
            $overrides
        );
    }

    /** Deja al usuario con nivel efectivo: concesión vigente y fila de socio con número. */
    private function giveMembership(int $userId = self::USER, int $levelId = self::LEVEL): void
    {
        $this->wpdb()->seedGrant(
            [
                'user_id'   => $userId,
                'level_id'  => $levelId,
                'starts_at' => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
                'ends_at'   => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
                'status'    => 'active',
            ]
        );
        $this->wpdb()->seedMember(
            $userId,
            [
                'member_number' => '000123',
                'member_since'  => '2026-01-02 03:04:05',
            ]
        );
    }

    private function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function sql(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    // ── Emisión ─────────────────────────────────────────────────────────────

    public function testIssueReturnsAHighEntropyTokenAndStoresOnlyItsHash(): void
    {
        $this->enableCard();
        $this->giveMembership();

        $issued = \HWE_Memberships_Card::issue(self::USER);

        $this->assertIsArray($issued);
        $this->assertSame(['token', 'expires_at'], array_keys($issued));

        $token = (string) $issued['token'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, '32 bytes aleatorios = 256 bits en hexadecimal.');
        $this->assertSame($this->iso($this->now + 24 * HOUR_IN_SECONDS), $issued['expires_at']);

        $row = $this->wpdb()->tokenByHash(hash('sha256', $token));
        $this->assertNotNull($row, 'La fila se busca por el hash del token.');
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertNotSame($token, $row['token_hash'], 'El token en claro nunca se guarda.');
        $this->assertSame($this->sql($this->now + 24 * HOUR_IN_SECONDS), $row['expires_at']);
        $this->assertNull($row['revoked_at']);

        // Ni el alta ni las consultas de la transacción arrastran el token en claro.
        $trace = json_encode([$this->wpdb()->inserts, $this->wpdb()->queries]);
        $this->assertIsString($trace);
        $this->assertStringNotContainsString($token, $trace);
    }

    public function testIssueRevokesThePreviousLiveTokenSoThereIsAtMostOne(): void
    {
        $this->enableCard();
        $this->giveMembership();

        $first  = \HWE_Memberships_Card::issue(self::USER);
        $second = \HWE_Memberships_Card::issue(self::USER);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertCount(2, $this->wpdb()->tokens);
        $this->assertCount(1, $this->wpdb()->liveTokensOf(self::USER), 'Nunca hay dos tokens vivos a la vez.');

        $old = $this->wpdb()->tokenByHash(hash('sha256', (string) $first['token']));
        $new = $this->wpdb()->tokenByHash(hash('sha256', (string) $second['token']));
        $this->assertNotNull($old);
        $this->assertNotNull($new);
        $this->assertSame($this->sql($this->now), $old['revoked_at'], 'El anterior queda revocado en la misma transacción.');
        $this->assertNull($new['revoked_at']);
    }

    public function testIssueUsesTheConfiguredTtlHours(): void
    {
        $this->enableCard(['card_token_ttl_hours' => '5']);
        $this->giveMembership();

        $issued = \HWE_Memberships_Card::issue(self::USER);

        $this->assertIsArray($issued);
        $this->assertSame($this->iso($this->now + 5 * HOUR_IN_SECONDS), $issued['expires_at']);
        $row = $this->wpdb()->tokenByHash(hash('sha256', (string) $issued['token']));
        $this->assertNotNull($row);
        $this->assertSame($this->sql($this->now + 5 * HOUR_IN_SECONDS), $row['expires_at']);
    }

    public function testIssueFailsClosedWithoutAnEffectiveLevel(): void
    {
        $this->enableCard();

        // Sin concesiones: no hay nivel.
        $this->assertNull(\HWE_Memberships_Card::issue(self::USER));

        // Congelado: la fila del socio anula el nivel efectivo.
        $this->wpdb()->seedGrant(
            [
                'user_id'   => self::OTHER,
                'level_id'  => self::LEVEL,
                'starts_at' => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
                'ends_at'   => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
                'status'    => 'active',
            ]
        );
        $this->wpdb()->seedMember(self::OTHER, ['frozen_at' => $this->sql($this->now)]);
        $this->assertNull(\HWE_Memberships_Card::issue(self::OTHER));

        // Concesión vencida: la caducidad se calcula por fechas, no por la caché.
        $this->wpdb()->seedGrant(
            [
                'user_id'   => self::THIRD,
                'level_id'  => self::LEVEL,
                'starts_at' => gmdate('Y-m-d H:i:s', $this->now - 40 * DAY_IN_SECONDS),
                'ends_at'   => gmdate('Y-m-d H:i:s', $this->now - 1),
                'status'    => 'active',
            ]
        );
        $this->wpdb()->seedMember(self::THIRD);
        $this->assertNull(\HWE_Memberships_Card::issue(self::THIRD));

        $this->assertSame([], $this->wpdb()->tokens, 'Sin nivel efectivo no se emite ni se toca la tabla.');
    }

    public function testIssueFailsClosedWhenTheSwitchOrTheModuleIsOff(): void
    {
        $this->giveMembership();

        // Carné apagado.
        $this->enableCard(['card_enabled' => false]);
        $this->assertNull(\HWE_Memberships_Card::issue(self::USER));

        // Módulo inactivo (incluir el archivo a mano no lo enciende).
        $this->enableCard();
        $this->activePlugins = [];
        $this->assertNull(\HWE_Memberships_Card::issue(self::USER));

        $this->assertSame([], $this->wpdb()->tokens);
        $this->assertSame([], $this->wpdb()->queries, 'La puerta se comprueba antes de abrir la transacción.');
    }

    public function testIssueRollsBackAndLogsWhenTheTokenCannotBeStored(): void
    {
        $this->enableCard();
        $this->giveMembership();
        $this->wpdb()->seedToken(self::OTHER, str_repeat('b', 64));
        $this->wpdb()->failTokenInsert = true;

        $this->assertNull(\HWE_Memberships_Card::issue(self::USER));

        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertCount(1, $this->wpdb()->liveTokensOf(self::OTHER), 'El ROLLBACK no toca a otros usuarios.');
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
        $this->assertStringNotContainsString('ana@example.com', $this->logged[0], 'Los logs no llevan PII.');
    }

    // ── Revocación ──────────────────────────────────────────────────────────

    public function testRevokeRevokesEveryLiveTokenAndIsIdempotent(): void
    {
        $this->wpdb()->seedToken(self::USER, self::TOKEN);
        $this->wpdb()->seedToken(self::OTHER, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        \HWE_Memberships_Card::revoke(self::USER);

        $target = $this->wpdb()->tokenByHash(hash('sha256', self::TOKEN));
        $this->assertNotNull($target);
        $this->assertSame($this->sql($this->now), $target['revoked_at']);

        $other = $this->wpdb()->tokenByHash(hash('sha256', str_repeat('b', 64)));
        $this->assertNotNull($other);
        $this->assertNull($other['revoked_at'], 'La revocación no toca a otros usuarios.');

        // Repetir no reescribe el instante de la primera revocación.
        $this->now += 3600;
        \HWE_Memberships_Card::revoke(self::USER);
        $target = $this->wpdb()->tokenByHash(hash('sha256', self::TOKEN));
        $this->assertNotNull($target);
        $this->assertSame($this->sql($this->now - 3600), $target['revoked_at']);
    }

    public function testRegisterHooksTheRevocationOnEveryLifecycleEvent(): void
    {
        $hooks = [
            'hwe_membership_expired'          => ['onExpired', 3],
            'hwe_membership_frozen'           => ['onFrozen', 2],
            'hwe_membership_revoked'          => ['onRevoked', 3],
            'hwe_membership_removed_by_admin' => ['onRemoved', 3],
            'hwe_membership_level_changed'    => ['onLevelChanged', 3],
        ];

        foreach ($hooks as $hook => [$method, $acceptedArgs]) {
            Actions\expectAdded($hook)
                ->once()
                ->with([\HWE_Memberships_Card::class, $method], 10, $acceptedArgs);
        }

        \HWE_Memberships_Card::register();
    }

    public function testEveryLifecycleEventRevokesTheCard(): void
    {
        $events = [
            'expired'   => static fn (int $userId) => \HWE_Memberships_Card::onExpired($userId, 1, self::LEVEL),
            'frozen'    => static fn (int $userId) => \HWE_Memberships_Card::onFrozen($userId, 1),
            'revoked'   => static fn (int $userId) => \HWE_Memberships_Card::onRevoked($userId, 1, self::LEVEL),
            'removed'   => static fn (int $userId) => \HWE_Memberships_Card::onRemoved($userId, 0, self::LEVEL),
            'downgrade' => static fn (int $userId) => \HWE_Memberships_Card::onLevelChanged($userId, self::LEVEL, 0),
        ];

        foreach ($events as $label => $event) {
            $this->wpdb()->tokens = [];
            $this->wpdb()->seedToken(self::USER, self::TOKEN);

            $event(self::USER);

            $row = $this->wpdb()->tokenByHash(hash('sha256', self::TOKEN));
            $this->assertNotNull($row, $label);
            $this->assertSame($this->sql($this->now), $row['revoked_at'], 'El evento «' . $label . '» revoca el carné.');
        }

        // Un cambio a un nivel superior no revoca: el carné sigue vigente.
        $this->wpdb()->tokens = [];
        $this->wpdb()->seedToken(self::USER, self::TOKEN);
        \HWE_Memberships_Card::onLevelChanged(self::USER, self::LEVEL, self::LEVEL + 1);
        $row = $this->wpdb()->tokenByHash(hash('sha256', self::TOKEN));
        $this->assertNotNull($row);
        $this->assertNull($row['revoked_at']);
    }

    // ── Verificación ────────────────────────────────────────────────────────

    public function testVerifyReturnsOnlyTheMinimalPublicData(): void
    {
        $this->enableCard();
        $this->giveMembership();
        $this->adminPluginsWithIdentity();
        $this->userMeta[ self::USER ] = [
            'billing_document_type' => 'CC',
            'billing_document'      => '1020304050',
        ];
        $this->wpdb()->seedToken(self::USER, self::TOKEN);

        $data = \HWE_Memberships_Card::verify(self::TOKEN);

        $this->assertIsArray($data);
        $this->assertSame(
            [
                'valid'         => true,
                'level'         => ['name' => 'Nivel Socio', 'slug' => 'nivel-socio'],
                'member_since'  => '2026-01-02T03:04:05Z',
                'member_number' => 'SOC-000123',
                'member_name'   => 'Ana P.',
                'document'      => ['type' => 'CC', 'number' => '***050'],
            ],
            $data
        );

        // La prueba de pago es el hash: nunca viaja el id de usuario, ni email, ni login.
        $encoded = json_encode($data);
        $this->assertIsString($encoded);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('login', $data);
        $this->assertStringNotContainsString('ana@example.com', $encoded);
        $this->assertStringNotContainsString('ana_login', $encoded);
        $this->assertStringNotContainsString('Pérez', $encoded, 'El apellido completo no sale.');
        $this->assertStringNotContainsString('1020304050', $encoded, 'El documento completo no sale.');
        $this->assertNotSame((string) self::USER, $data['member_number']);
    }

    public function testVerifyMasksTheNameWithTheReducedVisibleRule(): void
    {
        $this->enableCard();
        $this->giveMembership(self::USER);
        $this->giveMembership(self::OTHER);
        $this->giveMembership(self::THIRD);
        $this->users[ self::USER ]->display_name = 'Ana María Pérez';
        $this->wpdb()->seedToken(self::USER, self::TOKEN);
        $this->wpdb()->seedToken(
            self::OTHER,
            str_repeat('b', 64)
        );
        $this->wpdb()->seedToken(
            self::THIRD,
            str_repeat('c', 64)
        );

        $first = \HWE_Memberships_Card::verify(self::TOKEN);
        $this->assertNotNull($first);
        $this->assertSame('Ana P.', $first['member_name'], 'Nombre de pila + inicial del apellido.');

        // `display_name` es el login: se usa el nombre real con la inicial del apellido.
        $other = \HWE_Memberships_Card::verify(str_repeat('b', 64));
        $this->assertNotNull($other);
        $this->assertSame('Beto G.', $other['member_name']);

        // Sin nombre fiable, cadena vacía (el BFF ya trata el vacío como «sin nombre»).
        $third = \HWE_Memberships_Card::verify(str_repeat('c', 64));
        $this->assertNotNull($third);
        $this->assertSame('', $third['member_name']);
    }

    public function testVerifyOnlyReturnsTheMaskedDocumentWithCustomerIdentityActive(): void
    {
        $this->enableCard();
        $this->giveMembership();
        $this->userMeta[ self::USER ] = [
            'billing_document_type' => 'CC',
            'billing_document'      => '50',
        ];
        $this->wpdb()->seedToken(self::USER, self::TOKEN);

        // Módulo apagado: ni siquiera viaja la clave.
        $data = \HWE_Memberships_Card::verify(self::TOKEN);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('document', $data);

        // Activo: tipo + número enmascarado; un documento muy corto no deja ver dígitos.
        $this->adminPluginsWithIdentity();
        $data = \HWE_Memberships_Card::verify(self::TOKEN);
        $this->assertIsArray($data);
        $this->assertSame(['type' => 'CC', 'number' => '***'], $data['document']);
    }

    public function testVerifyRejectsUnknownExpiredRevokedAndWithoutLevelTheSameWay(): void
    {
        $this->enableCard();
        $this->wpdb()->seedToken(
            self::USER,
            self::TOKEN,
            $this->sql($this->now - 1)
        );
        $this->wpdb()->seedToken(
            self::OTHER,
            str_repeat('b', 64),
            $this->sql($this->now + 3600),
            $this->sql($this->now - 10)
        );

        $this->assertNull(\HWE_Memberships_Card::verify(str_repeat('d', 64)), 'Inexistente.');
        $this->assertNull(\HWE_Memberships_Card::verify(self::TOKEN), 'Caducado.');
        $this->assertNull(\HWE_Memberships_Card::verify(str_repeat('b', 64)), 'Revocado.');
        $this->assertNull(\HWE_Memberships_Card::verify('no-es-un-token'), 'Formato inválido.');

        // Token vigente, pero el socio perdió el nivel o quedó congelado.
        $this->wpdb()->seedToken(self::THIRD, str_repeat('c', 64));
        $this->assertNull(\HWE_Memberships_Card::verify(str_repeat('c', 64)));
    }

    public function testVerifyRecordsTheLastUseWithoutFailingTheCheck(): void
    {
        $this->enableCard();
        $this->giveMembership();
        $this->wpdb()->seedToken(self::USER, self::TOKEN);

        $this->assertNotNull(\HWE_Memberships_Card::verify(self::TOKEN));

        $row = $this->wpdb()->tokenByHash(hash('sha256', self::TOKEN));
        $this->assertNotNull($row);
        $this->assertSame($this->sql($this->now), $row['last_used_at']);

        // Un fallo al registrar el uso es diagnóstico: la verificación sigue respondiendo.
        $this->wpdb()->tokens = [];
        $this->wpdb()->failTouch = true;
        $this->wpdb()->seedToken(self::USER, self::TOKEN);
        $this->assertNotNull(\HWE_Memberships_Card::verify(self::TOKEN));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('último uso', $this->logged[0]);
    }

    // ── Rutas internas ──────────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private function routes(): array
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): bool {
                $routes[ $namespace . $route ] = $args;
                return true;
            }
        );

        \HWE_Memberships_CardRoutes::registerRoutes();

        return $routes;
    }

    public function testRegisterHooksTheRoutesOnRestApiInit(): void
    {
        Actions\expectAdded('rest_api_init')
            ->once()
            ->with([\HWE_Memberships_CardRoutes::class, 'registerRoutes']);

        \HWE_Memberships_CardRoutes::register();
    }

    public function testItRegistersTheCardAndVerifyRoutesWithGet(): void
    {
        $routes = $this->routes();

        $this->assertSame(
            ['hwe/v1/memberships/card', 'hwe/v1/memberships/verify'],
            array_keys($routes)
        );
        foreach ($routes as $route => $args) {
            $this->assertSame('GET', $args['methods'], $route . ' solo lee.');
            $this->assertIsCallable($args['permission_callback'], $route . ' exige el secreto interno.');
            $this->assertIsCallable($args['callback'], $route . ' necesita manejador.');
        }
    }

    public function testWithoutTheInternalSecretEveryRouteFailsClosed(): void
    {
        $request = new \WP_REST_Request();

        foreach ($this->routes() as $route => $args) {
            $this->assertFalse($args['permission_callback']($request), $route . ' debe fallar cerrada.');
        }
    }

    public function testWithTheSecretAndTheCardOnTheRoutesAreAllowed(): void
    {
        $this->enableCard();
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        foreach ($this->routes() as $route => $args) {
            $this->assertTrue($args['permission_callback']($request), $route . ' debe atender al BFF.');
        }
    }

    public function testTheCardOffOrTheModuleOffAnswers404(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        $this->enableCard(['card_enabled' => false]);
        foreach ($this->routes() as $route => $args) {
            $closed = $args['permission_callback']($request);
            $this->assertInstanceOf(\WP_Error::class, $closed, $route . ' se cierra con el carné apagado.');
            $this->assertSame('rest_no_route', $closed->get_error_code());
            $this->assertSame(['status' => 404], $closed->get_error_data());
        }

        $this->enableCard();
        $this->activePlugins = [];
        foreach ($this->routes() as $route => $args) {
            $closed = $args['permission_callback']($request);
            $this->assertInstanceOf(\WP_Error::class, $closed, $route . ' se cierra con el módulo apagado.');
            $this->assertSame(['status' => 404], $closed->get_error_data());
        }
    }

    public function testTheCardRouteRequiresAValidUserId(): void
    {
        foreach ([null, 0, -3] as $invalid) {
            $request = new \WP_REST_Request(null === $invalid ? [] : ['user_id' => $invalid]);

            $response = \HWE_Memberships_CardRoutes::handleCard($request);

            $this->assertSame(400, $response->get_status());
            $this->assertSame(['error' => 'invalid_request'], $response->get_data());
        }
    }

    public function testTheCardRouteAnswers403WithoutAnEffectiveLevel(): void
    {
        $this->enableCard();

        $response = \HWE_Memberships_CardRoutes::handleCard(new \WP_REST_Request(['user_id' => self::USER]));

        $this->assertSame(403, $response->get_status());
        $this->assertSame(['error' => 'membership_card_unavailable'], $response->get_data());
    }

    public function testTheCardRouteReturnsTheTokenAndTheFrontendPath(): void
    {
        $this->enableCard(['card_verify_path' => '/mi-carnet']);
        $this->giveMembership();

        $response = \HWE_Memberships_CardRoutes::handleCard(new \WP_REST_Request(['user_id' => self::USER]));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(['token', 'expires_at', 'verify_path'], array_keys($data));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $data['token']);
        $this->assertSame($this->iso($this->now + 24 * HOUR_IN_SECONDS), $data['expires_at']);
        $this->assertSame('/mi-carnet', $data['verify_path'], 'La URL absoluta la compone el BFF con la marca.');
    }

    public function testTheVerifyRouteNeedsAToken(): void
    {
        $response = \HWE_Memberships_CardRoutes::handleVerify(new \WP_REST_Request());

        $this->assertSame(400, $response->get_status());
        $this->assertSame(['error' => 'invalid_request'], $response->get_data());
    }

    public function testTheVerifyRouteGivesTheSameAnswerForUnknownExpiredAndRevoked(): void
    {
        $this->enableCard();
        $this->wpdb()->seedToken(self::USER, self::TOKEN, $this->sql($this->now - 1));
        $this->wpdb()->seedToken(
            self::OTHER,
            str_repeat('b', 64),
            $this->sql($this->now + 3600),
            $this->sql($this->now - 10)
        );

        $answers = [];
        foreach ([str_repeat('d', 64), self::TOKEN, str_repeat('b', 64)] as $token) {
            $response = \HWE_Memberships_CardRoutes::handleVerify(new \WP_REST_Request(['token' => $token]));
            $answers[] = [$response->get_status(), $response->get_data()];
        }

        $this->assertSame(
            [
                [200, ['valid' => false]],
                [200, ['valid' => false]],
                [200, ['valid' => false]],
            ],
            $answers,
            'Las tres respuestas son idénticas: no se distingue el motivo.'
        );
    }

    public function testTheVerifyRouteReturnsThePublicCardData(): void
    {
        $this->enableCard();
        $this->giveMembership();
        $this->wpdb()->seedToken(self::USER, self::TOKEN);

        $response = \HWE_Memberships_CardRoutes::handleVerify(new \WP_REST_Request(['token' => self::TOKEN]));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertTrue($data['valid']);
        $this->assertSame('Nivel Socio', $data['level']['name']);
        $this->assertSame('SOC-000123', $data['member_number']);
        $this->assertArrayNotHasKey('document', $data);
    }

    private function adminPluginsWithIdentity(): void
    {
        $this->activePlugins = [
            'hwe-memberships/hwe-memberships.php',
            'hwe-customer-identity/hwe-customer-identity.php',
        ];
    }
}

/**
 * Doble de `$wpdb` para el carné: concesiones vivas, fila del socio y tokens.
 *
 * Solo interpreta las sentencias que emiten `HWE_Memberships_Card`,
 * `HWE_Memberships_Members` y `HWE_Memberships_Grants::forUser`. Las transacciones se
 * simulan con una foto del estado para comprobar que un `ROLLBACK` no deja nada escrito.
 */
final class TestMembershipsCardWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];
    /** @var list<array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
    public array $updates = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var array<int,array<string,mixed>> */
    public array $grants = [];
    /** @var array<int,array<string,mixed>> */
    public array $members = [];
    /** @var array<int,array<string,mixed>> */
    public array $tokens = [];
    public bool $failTokenInsert = false;
    public bool $failTouch = false;

    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;
    private int $nextGrantId = 1;
    private int $nextTokenId = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode(array_values($args)) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$table, $data];

        if (str_ends_with($table, 'hwe_memberships_card_tokens')) {
            if ($this->failTokenInsert) {
                return false;
            }
            $id              = $this->nextTokenId++;
            $this->insert_id = $id;
            $this->tokens[ $id ] = array_merge($data, ['id' => $id]);
            return 1;
        }

        return 1;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        $this->updates[] = [$table, $data, $where];

        if (str_ends_with($table, 'hwe_memberships_card_tokens')) {
            if ($this->failTouch) {
                return false;
            }
            $id = (int) ($where['id'] ?? 0);
            if (!isset($this->tokens[ $id ])) {
                return 0;
            }
            $this->tokens[ $id ] = array_merge($this->tokens[ $id ], $data);
            return 1;
        }

        return 0;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;

        if ('START TRANSACTION' === $sql) {
            $this->snapshot = [
                'grants'      => $this->grants,
                'members'     => $this->members,
                'tokens'      => $this->tokens,
                'nextGrantId' => $this->nextGrantId,
                'nextTokenId' => $this->nextTokenId,
            ];
            return 1;
        }

        if ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->grants      = $this->snapshot['grants'];
                $this->members     = $this->snapshot['members'];
                $this->tokens      = $this->snapshot['tokens'];
                $this->nextGrantId = $this->snapshot['nextGrantId'];
                $this->nextTokenId = $this->snapshot['nextTokenId'];
            }
            $this->snapshot = null;
            return 1;
        }

        if ('COMMIT' === $sql) {
            $this->snapshot = null;
            return 1;
        }

        $args = $this->args($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_memberships_members')) {
            $userId = (int) ($args[0] ?? 0);
            if (isset($this->members[ $userId ])) {
                return 0;
            }
            $this->members[ $userId ] = [
                'user_id'        => $userId,
                'level_id'       => null,
                'covers_until'   => null,
                'frozen_at'      => null,
                'frozen_seconds' => 0,
                'member_number'  => null,
                'member_since'   => null,
                'updated_at'     => (string) ($args[1] ?? ''),
            ];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_memberships_card_tokens')) {
            $now    = (string) ($args[0] ?? '');
            $userId = (int) ($args[1] ?? 0);
            $count  = 0;
            foreach ($this->tokens as $index => $token) {
                if ((int) ($token['user_id'] ?? 0) === $userId && null === ($token['revoked_at'] ?? null)) {
                    $this->tokens[ $index ]['revoked_at'] = $now;
                    $count++;
                }
            }
            return $count;
        }

        return 1;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_card_tokens')) {
            $hash = (string) ($args[0] ?? '');
            foreach ($this->tokens as $row) {
                if ((string) ($row['token_hash'] ?? '') === $hash) {
                    return (object) $row;
                }
            }
            return null;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_members')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->members[ $userId ]) ? (object) $this->members[ $userId ] : null;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_grants')) {
            $userId = (int) ($args[0] ?? 0);
            $rows   = [];
            foreach ($this->grants as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if (!in_array((string) ($row['status'] ?? ''), ['active', 'scheduled'], true)) {
                    continue;
                }
                $rows[] = $row;
            }
            return array_map(static fn (array $row): object => (object) $row, $rows);
        }

        return [];
    }

    public function seedGrant(array $overrides = []): int
    {
        $id = $this->nextGrantId++;
        $this->grants[ $id ] = array_merge(
            [
                'id'              => $id,
                'user_id'         => 7,
                'level_id'        => 101,
                'origin'          => 'order',
                'idempotency_key' => 'seed:' . $id,
                'duration_days'   => 30,
                'queued_seconds'  => null,
                'starts_at'       => null,
                'ends_at'         => null,
                'status'          => 'active',
                'frozen_seconds'  => 0,
                'revoked_at'      => null,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ],
            $overrides,
            ['id' => $id]
        );

        return $id;
    }

    public function seedMember(int $userId, array $overrides = []): void
    {
        $this->members[ $userId ] = array_merge(
            [
                'user_id'        => $userId,
                'level_id'       => null,
                'covers_until'   => null,
                'frozen_at'      => null,
                'frozen_seconds' => 0,
                'member_number'  => null,
                'member_since'   => null,
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ],
            $overrides,
            ['user_id' => $userId]
        );
    }

    public function seedToken(int $userId, string $token, ?string $expires = null, ?string $revoked = null): void
    {
        $id = $this->nextTokenId++;
        $this->tokens[ $id ] = [
            'id'           => $id,
            'user_id'      => $userId,
            'token_hash'   => hash('sha256', $token),
            'expires_at'   => $expires ?? gmdate('Y-m-d H:i:s', time() + 3600),
            'revoked_at'   => $revoked,
            'last_used_at' => null,
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function tokenByHash(string $hash): ?array
    {
        foreach ($this->tokens as $row) {
            if ((string) ($row['token_hash'] ?? '') === $hash) {
                return $row;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function liveTokensOf(int $userId): array
    {
        return array_values(array_filter(
            $this->tokens,
            static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId
                && null === ($row['revoked_at'] ?? null)
        ));
    }

    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }
}
