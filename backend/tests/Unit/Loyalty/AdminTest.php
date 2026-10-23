<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-order.php';

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de administración en wp-admin (spec §12).
 *
 * Se prueba lo que decide el panel y no cómo lo pinta WordPress: qué capacidad exige cada
 * escritura, que el ajuste manual lleve nonce y nota, que un cargo no deje saldo negativo ni un
 * doble envío duplique el movimiento, que editar el perfil no sobrescriba el saldo y que las
 * reglas de la red (autorreferido y ciclos) sigan siendo las de `HWE_Loyalty_Referrals`. También
 * se fijan las dos propiedades que un test de integración no ve: la consulta del listado de
 * usuarios ordena por SQL con un JOIN (no carga todos los usuarios) y el CSV neutraliza las
 * celdas que Excel ejecutaría como fórmula.
 *
 * El doble de `$wpdb` guarda el estado mínimo del libro y de los referidos para que las clases
 * reales (`Ledger`, `Referrals`, `Settings`) tomen sus decisiones sin WordPress. No interpreta
 * el SQL literal: solo las sentencias que esas clases emiten.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminTest extends TestCase
{
    private const USER = 7;
    private const OTHER = 8;
    private const THIRD = 9;
    private const FOURTH = 10;
    private const FIFTH = 11;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var list<string> Capacidades del usuario simulado. */
    private array $caps = ['hwe_loyalty_view', 'hwe_loyalty_manage'];
    /** @var list<string> Mensajes de `wp_die`. */
    private array $died = [];
    /** @var list<string> Redirecciones de `wp_safe_redirect`. */
    private array $redirects = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,mixed> Transients de los avisos. */
    private array $transients = [];
    private bool $nonceValid = true;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        $_GET  = [];

        $this->config = [
            'loyalty' => [
                'points_enabled'    => true,
                'referrals_enabled' => true,
                'currency_name'     => 'punto',
                'currency_name_plural' => 'puntos',
            ],
        ];
        $this->activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
        $this->users = [
            self::USER => (object) [
                'ID'           => self::USER,
                'display_name' => 'Cliente Siete',
                'user_email'   => 'siete@example.test',
                'user_login'   => 'cliente-siete',
                'roles'        => ['customer'],
            ],
            self::OTHER => (object) [
                'ID'           => self::OTHER,
                'display_name' => 'Cliente Ocho',
                'user_email'   => 'ocho@example.test',
                'user_login'   => 'cliente-ocho',
                'roles'        => ['customer'],
            ],
            self::THIRD => (object) [
                'ID'           => self::THIRD,
                'display_name' => 'Cliente Nueve',
                'user_email'   => 'nueve@example.test',
                'user_login'   => 'cliente-nueve',
                'roles'        => ['customer'],
            ],
            self::FOURTH => (object) [
                'ID'           => self::FOURTH,
                'display_name' => 'Cliente Diez',
                'user_email'   => 'diez@example.test',
                'user_login'   => 'cliente-diez',
                'roles'        => ['customer'],
            ],
            self::FIFTH => (object) [
                'ID'           => self::FIFTH,
                'display_name' => 'Cliente Once',
                'user_email'   => 'once@example.test',
                'user_login'   => 'cliente-once',
                'roles'        => ['customer'],
            ],
        ];
        $this->caps      = ['hwe_loyalty_view', 'hwe_loyalty_manage'];
        $this->died      = [];
        $this->redirects  = [];
        $this->logged     = [];
        $this->transients = [];
        $this->nonceValid = true;

        $GLOBALS['wpdb'] = new TestAdminWpdb();
        $GLOBALS['wpdb']->userRoles = [
            self::USER   => ['customer'],
            self::OTHER  => ['customer'],
            self::THIRD  => ['customer'],
            self::FOURTH => ['customer'],
            self::FIFTH  => ['customer'],
        ];

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
        Functions\when('get_user_by')->alias(function (string $field, string $value) {
            foreach ($this->users as $user) {
                $haystack = match ($field) {
                    'email' => $user->user_email,
                    'login' => $user->user_login,
                    'slug'  => $user->user_login,
                    default => '',
                };
                if ($haystack === $value) {
                    return $user;
                }
            }
            return false;
        });
        Functions\when('current_user_can')->alias(function (string $cap, mixed ...$args): bool {
            if ('edit_user' === $cap) {
                return true;
            }
            return in_array($cap, $this->caps, true);
        });
        Functions\when('wp_verify_nonce')->alias(fn (mixed $nonce, mixed $action): bool => $this->nonceValid);
        Functions\when('wp_die')->alias(function (string $message): void {
            $this->died[] = $message;
        });
        Functions\when('wp_safe_redirect')->alias(function (string $url): void {
            $this->redirects[] = $url;
        });
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('update_user_meta')->justReturn(true);
        Functions\when('add_query_arg')->alias(function (mixed $args, string $url = ''): string {
            $args = is_array($args) ? $args : array();
            $query = http_build_query(array_filter($args, static fn ($value): bool => null !== $value));
            return '' === $query ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
        });
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl = 0): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value): string => trim((string) $value),
            'sanitize_key'        => static fn ($value): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value) ?? ''),
            'wp_unslash'          => static fn ($value) => $value,
            'absint'              => static fn ($value): int => abs((int) $value),
            'esc_html'            => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'esc_attr'            => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'esc_url'             => static fn ($value): string => (string) $value,
            'esc_html__'          => static fn ($value): string => (string) $value,
            'esc_attr__'          => static fn ($value): string => (string) $value,
            '__'                  => static fn ($value): string => (string) $value,
            'number_format_i18n'  => static fn ($value, int $decimals = 0): string => number_format((float) $value, $decimals),
            'wp_date'             => static fn (string $format, int $timestamp): string => gmdate($format, $timestamp),
            'admin_url'           => static fn (string $path = ''): string => 'https://example.test/wp-admin/' . $path,
            'wc_price'            => static fn ($amount): string => '<span class="woocommerce-Price-amount">' . number_format((float) $amount, 2, ',', '.') . '</span>',
            'wp_kses_post'        => static fn ($value): string => (string) $value,
        ]);

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    private function wpdb(): TestAdminWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function entries(): array
    {
        return array_values($this->wpdb()->ledger);
    }

    /** @param list<string> $roles */
    private function seedReferral(int $userId, string $code, ?int $referrerId = null): void
    {
        $this->wpdb()->referrals[] = [
            'id'           => count($this->wpdb()->referrals) + 1,
            'user_id'      => $userId,
            'code'         => $code,
            'referrer_id'  => $referrerId,
            'status'       => 'pending',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now),
            'confirmed_at' => null,
        ];
    }

    // ── Capacidades ─────────────────────────────────────────────────────────

    public function testActivationGrantsBothCapabilitiesOnlyToAdministratorAndShopManager(): void
    {
        $roles = [];
        Functions\when('get_role')->alias(function (string $slug) use (&$roles) {
            if (!in_array($slug, ['administrator', 'shop_manager'], true)) {
                return false;
            }
            return $roles[$slug] ??= new TestAdminRole($slug);
        });

        \HWE_Loyalty_Admin_Capabilities::onActivated('otro-plugin/otro.php');
        $this->assertSame([], $roles, 'Activar otro plugin no concede capacidades de este módulo.');

        \HWE_Loyalty_Admin_Capabilities::onActivated('hwe-loyalty/hwe-loyalty.php');
        \HWE_Loyalty_Admin_Capabilities::grant();

        $this->assertSame(
            ['hwe_loyalty_view' => true, 'hwe_loyalty_manage' => true],
            $roles['administrator']->caps,
            'La concesión es idempotente: dos pasadas no duplican ni cambian nada.'
        );
        $this->assertSame(
            ['hwe_loyalty_view' => true, 'hwe_loyalty_manage' => true],
            $roles['shop_manager']->caps
        );
        $this->assertSame(['administrator', 'shop_manager'], array_keys($roles), 'Ningún otro rol recibe capacidades.');
    }

    public function testTheAdminInitRepairOnlyTouchesTheTwoCoreRoles(): void
    {
        $administrator = new TestAdminRole('administrator');
        $shopManager   = new TestAdminRole('shop_manager');
        $instanceRole  = new TestAdminRole('hwe_vendor');
        Functions\when('get_role')->alias(function (string $slug) use ($administrator, $shopManager, $instanceRole) {
            return match ($slug) {
                'administrator' => $administrator,
                'shop_manager'  => $shopManager,
                'hwe_vendor'    => $instanceRole,
                default         => false,
            };
        });

        \HWE_Loyalty_Admin_Capabilities::maybeRepair();

        $this->assertTrue($administrator->has_cap('hwe_loyalty_manage'));
        $this->assertTrue($shopManager->has_cap('hwe_loyalty_manage'));
        $this->assertSame([], $instanceRole->caps, 'Un rol de la instancia nunca recibe las capacidades.');
        $this->assertNotEmpty($this->logged, 'La autorreparación se registra.');
    }

    public function testWithoutTheManageCapabilityNothingIsWritten(): void
    {
        $this->caps = ['hwe_loyalty_view'];
        $this->seedReferral(self::USER, 'JKLMNPQR');

        $adjust = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 100, 'Ajuste', 'manual-key-1');

        $_POST = [
            'hwe_loyalty_profile_nonce' => 'valid',
            'hwe_loyalty_code'          => 'ABCDEFGH',
            'hwe_loyalty_referrer'      => (string) self::OTHER,
        ];
        \HWE_Loyalty_Admin_UserProfile::save(self::USER);

        $this->assertSame('forbidden', $adjust['code']);
        $this->assertCount(0, $this->entries(), 'Sin capacidad no hay movimiento.');
        $this->assertSame('JKLMNPQR', $this->wpdb()->codeOf(self::USER), 'Sin capacidad no cambia el código.');
        $this->assertNull($this->wpdb()->referralForUser(self::USER)['referrer_id'], 'Sin capacidad no cambia el referidor.');
    }

    // ── Ajuste manual ───────────────────────────────────────────────────────

    public function testAManualAdjustmentRequiresANote(): void
    {
        $result = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 100, "   ", 'manual-key-2');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_note', $result['code']);
        $this->assertCount(0, $this->entries());
    }

    public function testAManualAdjustmentRequiresAValidNonce(): void
    {
        $this->nonceValid = false;
        $_POST = [
            'hwe_loyalty_user'         => (string) self::USER,
            'hwe_loyalty_direction'    => 'credit',
            'hwe_loyalty_points'       => '100',
            'hwe_loyalty_note'         => 'Ajuste de prueba',
            'hwe_loyalty_key'          => 'manual-key-3',
            'hwe_loyalty_adjust_nonce' => 'manipulado',
        ];

        \HWE_Loyalty_Admin_Adjustments::handleRequest();

        $this->assertNotEmpty($this->died, 'Sin nonce la petición muere con 403.');
        $this->assertCount(0, $this->entries());
        $this->assertSame([], $this->redirects);
    }

    public function testACreditAndADebitMoveTheBalanceAndKeepProvenance(): void
    {
        $credit = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 100, 'Compensación', 'manual-key-4');
        $debit  = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'debit', 30, 'Corrección', 'manual-key-5');

        $this->assertTrue($credit['ok']);
        $this->assertTrue($debit['ok']);
        $this->assertSame(70, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);

        $rows = $this->entries();
        $this->assertSame('admin_credit', $rows[0]['type']);
        $this->assertSame('admin_debit', $rows[1]['type']);
        $this->assertSame('admin', $rows[0]['actor']);
        $this->assertSame(1, (int) $rows[0]['actor_id'], 'El actor es el administrador de la sesión simulada.');
        $this->assertSame('Compensación', $rows[0]['note']);
        $this->assertSame('Corrección', $rows[1]['note']);
    }

    public function testADebitLargerThanTheBalanceIsRejectedWithoutGoingNegative(): void
    {
        \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 50, 'Saldo inicial', 'manual-key-6');

        $result = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'debit', 80, 'Cargo excesivo', 'manual-key-7');

        $this->assertFalse($result['ok']);
        $this->assertSame('insufficient', $result['code']);
        $this->assertCount(1, $this->entries(), 'El cargo rechazado no escribe.');
        $this->assertSame(50, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
    }

    public function testASecondSendOfTheSameAdjustmentDoesNotDuplicateTheMovement(): void
    {
        $first  = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 100, 'Ajuste', 'same-form-key-1');
        $second = \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 100, 'Ajuste', 'same-form-key-1');

        $this->assertTrue($first['ok']);
        $this->assertSame('duplicate', $second['code']);
        $this->assertCount(1, $this->entries(), 'La clave de idempotencia frena el doble clic.');
        $this->assertSame(100, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
    }

    public function testTheAdjustmentHandlerDispatchesAValidRequestAndRedirects(): void
    {
        $_POST = [
            'hwe_loyalty_user'         => (string) self::USER,
            'hwe_loyalty_direction'    => 'credit',
            'hwe_loyalty_points'       => '25',
            'hwe_loyalty_note'         => 'Ajuste desde el panel',
            'hwe_loyalty_key'          => 'manual-key-8',
            'hwe_loyalty_adjust_nonce' => 'valid',
        ];

        \HWE_Loyalty_Admin_Adjustments::handleRequest();

        $this->assertCount(1, $this->entries());
        $this->assertCount(1, $this->redirects);
        $this->assertSame([], $this->died);
    }

    // ── Perfil de usuario ───────────────────────────────────────────────────

    public function testEditingTheProfileNeverOverwritesTheBalance(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:55');
        $this->seedReferral(self::USER, 'JKLMNPQR');

        $_POST = [
            'hwe_loyalty_profile_nonce' => 'valid',
            'hwe_loyalty_balance'       => '999',
            'hwe_loyalty_code'          => 'ABCDEFGH',
            'hwe_loyalty_referrer'      => '',
        ];

        \HWE_Loyalty_Admin_UserProfile::save(self::USER);

        $this->assertSame(100, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
        $this->assertCount(1, $this->entries(), 'El perfil no crea un movimiento de ajuste por su cuenta.');
        $this->assertSame('ABCDEFGH', $this->wpdb()->codeOf(self::USER));
    }

    public function testTheProfileRequiresAValidNonce(): void
    {
        $this->seedReferral(self::USER, 'JKLMNPQR');
        $this->nonceValid = false;

        $_POST = [
            'hwe_loyalty_profile_nonce' => 'manipulado',
            'hwe_loyalty_code'          => 'ABCDEFGH',
            'hwe_loyalty_referrer'      => (string) self::OTHER,
        ];

        \HWE_Loyalty_Admin_UserProfile::save(self::USER);

        $this->assertSame('JKLMNPQR', $this->wpdb()->codeOf(self::USER));
        $this->assertNull($this->wpdb()->referralForUser(self::USER)['referrer_id']);
    }

    public function testTheProfileAcceptsAnExplicitCreditInsteadOfANewBalance(): void
    {
        $this->seedReferral(self::USER, 'JKLMNPQR', self::OTHER);

        $_POST = [
            'hwe_loyalty_profile_nonce' => 'valid',
            'hwe_loyalty_code'          => '',
            'hwe_loyalty_referrer'      => (string) self::OTHER,
            'hwe_loyalty_points'        => '40',
            'hwe_loyalty_direction'     => 'credit',
            'hwe_loyalty_note'          => 'Cortesía',
            'hwe_loyalty_key'           => 'profile-key-1',
        ];

        \HWE_Loyalty_Admin_UserProfile::save(self::USER);

        $this->assertSame(40, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
        $this->assertCount(1, $this->entries());
        $this->assertSame('admin_credit', $this->entries()[0]['type']);
    }

    public function testSettingTheReferrerRespectsSelfReferenceAndCycles(): void
    {
        $this->seedReferral(self::USER, 'JKLMNPQR', self::OTHER);
        $this->seedReferral(self::OTHER, 'NPQRSTUV');

        $_POST = [
            'hwe_loyalty_profile_nonce' => 'valid',
            'hwe_loyalty_code'          => '',
            'hwe_loyalty_referrer'      => (string) self::USER,
        ];
        \HWE_Loyalty_Admin_UserProfile::save(self::USER);
        $this->assertSame(self::OTHER, $this->wpdb()->referralForUser(self::USER)['referrer_id'], 'Nadie puede ser su propio referidor.');

        $_POST['hwe_loyalty_referrer'] = (string) self::USER;
        \HWE_Loyalty_Admin_UserProfile::save(self::OTHER);
        $this->assertNull($this->wpdb()->referralForUser(self::OTHER)['referrer_id'], 'Un ciclo se rechaza.');

        $_POST['hwe_loyalty_referrer'] = (string) self::THIRD;
        \HWE_Loyalty_Admin_UserProfile::save(self::OTHER);
        $this->assertSame(self::THIRD, $this->wpdb()->referralForUser(self::OTHER)['referrer_id'], 'Un cambio válido sí se guarda.');
    }

    // ── Listado de usuarios ─────────────────────────────────────────────────

    public function testTheUserListOrdersByBalanceAndReferrerWithASqlJoin(): void
    {
        $balance = $this->queryFor('hwe_loyalty_balance');
        \HWE_Loyalty_Admin_UserColumns::applySort($balance);
        $this->assertStringContainsString('LEFT JOIN wp_hwe_loyalty_balances', $balance->query_from);
        $this->assertStringContainsString('COALESCE(hwe_lb.balance, 0)', $balance->query_orderby);

        $referrer = $this->queryFor('hwe_loyalty_referrer');
        \HWE_Loyalty_Admin_UserColumns::applySort($referrer);
        $this->assertStringContainsString('LEFT JOIN wp_hwe_loyalty_referrals', $referrer->query_from);
        $this->assertStringContainsString('COALESCE(hwe_lr.referrer_id, 0)', $referrer->query_orderby);

        $login = $this->queryFor('login');
        \HWE_Loyalty_Admin_UserColumns::applySort($login);
        $this->assertSame('', $login->query_from, 'Una ordenación ajena no toca la consulta.');
        $this->assertSame('', $login->query_orderby);
    }

    public function testTheUserListAddsBothColumns(): void
    {
        $columns = \HWE_Loyalty_Admin_UserColumns::columns(['username' => 'Usuario', 'email' => 'Correo']);
        $this->assertArrayHasKey('hwe_loyalty_balance', $columns);
        $this->assertArrayHasKey('hwe_loyalty_referrer', $columns);

        $sortable = \HWE_Loyalty_Admin_UserColumns::sortable([]);
        $this->assertSame('hwe_loyalty_balance', $sortable['hwe_loyalty_balance']);
        $this->assertSame('hwe_loyalty_referrer', $sortable['hwe_loyalty_referrer']);
    }

    public function testTheUserListCellReceivesWordPressArgumentOrder(): void
    {
        // WordPress llama a `manage_users_custom_column` con ($output, $column_name, $user_id)
        // y el id llega como cadena: con otra firma, el listado de usuarios entero da un error
        // fatal (visto en la pila real).
        \HWE_Loyalty_Admin_Adjustments::apply(self::USER, 'credit', 1234, 'Saldo de prueba', 'manual-key-cols');

        $this->assertSame('1,234', \HWE_Loyalty_Admin_UserColumns::render('', 'hwe_loyalty_balance', (string) self::USER));
        $this->assertSame('—', \HWE_Loyalty_Admin_UserColumns::render('', 'hwe_loyalty_referrer', (string) self::USER));
        $this->assertSame('otra', \HWE_Loyalty_Admin_UserColumns::render('otra', 'email', (string) self::USER));
    }

    public function testCapabilitiesAreRepairedBeforeWordPressChecksMenuAccess(): void
    {
        // `wp-admin/menu.php` comprueba el acceso a `admin.php?page=…` ANTES de `admin_init`:
        // reparar solo ahí deja al administrador con un 403 en las páginas del módulo.
        \HWE_Loyalty_Admin_Capabilities::register();

        $this->assertNotFalse(has_action('admin_menu', [\HWE_Loyalty_Admin_Capabilities::class, 'maybeRepair']));
        $this->assertLessThan(10, has_action('admin_menu', [\HWE_Loyalty_Admin_Capabilities::class, 'maybeRepair']));

        $administrator = new TestAdminRole('administrator');
        $shopManager   = new TestAdminRole('shop_manager');
        Functions\when('get_role')->alias(
            static fn (string $slug) => match ($slug) {
                'administrator' => $administrator,
                'shop_manager'  => $shopManager,
                default         => false,
            }
        );
        $current = new class () {
            public int $refreshed = 0;
            public function get_role_caps(): array
            {
                $this->refreshed++;
                return [];
            }
        };
        Functions\when('wp_get_current_user')->justReturn($current);

        \HWE_Loyalty_Admin_Capabilities::maybeRepair();

        $this->assertSame(1, $current->refreshed, 'El usuario de esta petición debe ver ya las capacidades concedidas.');
    }

    public function testAUserNameWithMarkupIsEscapedInTheMovementList(): void
    {
        $this->users[self::USER]->display_name = '<script>alert(1)</script>';
        $rows = [
            (object) [
                'id'               => 1,
                'created_at'       => gmdate('Y-m-d H:i:s', $this->now),
                'user_id'          => self::USER,
                'display_name'     => '<script>alert(1)</script>',
                'type'             => 'admin_credit',
                'points'           => 10,
                'remaining_points' => 10,
                'ref_type'         => null,
                'ref_id'           => null,
                'actor'            => 'admin',
                'note'             => '<b>nota</b>',
                'expires_at'       => null,
            ],
        ];

        $method = new \ReflectionMethod(\HWE_Loyalty_Admin_Pages::class, 'renderMovementRows');
        ob_start();
        $method->invoke(null, $rows);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<b>nota</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;nota&lt;/b&gt;', $html);
    }

    // ── Acción por lotes ────────────────────────────────────────────────────

    public function testTheBatchAssignsCodesOnlyToUsersWithoutOneAndResumes(): void
    {
        $this->seedReferral(self::THIRD, 'JKLMNPQR');

        $first = \HWE_Loyalty_Admin_Codes::assignMissing(2);

        $this->assertSame(2, $first['assigned']);
        $this->assertSame(2, $first['remaining']);
        $this->assertSame('JKLMNPQR', $this->wpdb()->codeOf(self::THIRD), 'A quien ya tenía código no se le toca.');
        $this->assertNotSame(
            $this->wpdb()->codeOf(self::USER),
            $this->wpdb()->codeOf(self::OTHER),
            'Cada usuario recibe un código único.'
        );
        $this->assertCount(3, $this->wpdb()->referrals, 'Tres filas: la previa y las dos nuevas.');

        $second = \HWE_Loyalty_Admin_Codes::assignMissing(2);
        $this->assertSame(2, $second['assigned']);
        $this->assertSame(0, $second['remaining']);

        $third = \HWE_Loyalty_Admin_Codes::assignMissing(2);
        $this->assertSame(0, $third['assigned'], 'Reanudar sin pendientes no duplica ni asigna de nuevo.');

        $this->assertCount(5, $this->wpdb()->referrals);
        $codes = array_map(static fn (array $row): string => (string) $row['code'], $this->wpdb()->referrals);
        $this->assertCount(5, array_unique($codes));
    }

    public function testTheBatchRequiresAValidNonce(): void
    {
        $this->nonceValid = false;
        $_POST = [
            'hwe_loyalty_codes_nonce' => 'manipulado',
            'hwe_loyalty_batch'       => '5',
        ];

        \HWE_Loyalty_Admin_Codes::handleRequest();

        $this->assertNotEmpty($this->died, 'Sin nonce el lote muere con 403.');
        $this->assertCount(0, $this->wpdb()->referrals, 'No se asigna ningún código.');
        $this->assertSame([], $this->redirects);
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    public function testCsvCellsThatExcelWouldExecuteAreMadeInert(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", \HWE_Loyalty_Admin_Csv::csvCell('=HYPERLINK("http://x")'));
        $this->assertSame("'+1", \HWE_Loyalty_Admin_Csv::csvCell('+1'));
        $this->assertSame("'-1+cmd|' /C calc'!A0", \HWE_Loyalty_Admin_Csv::csvCell("-1+cmd|' /C calc'!A0"));
        // Un número negativo es un dato, no una fórmula: escaparlo convierte cada cargo en
        // texto en la hoja de cálculo (visto en el CSV real).
        $this->assertSame('-100000', \HWE_Loyalty_Admin_Csv::csvCell(-100000));
        $this->assertSame('-1', \HWE_Loyalty_Admin_Csv::csvCell('-1'));
        $this->assertSame('-12.50', \HWE_Loyalty_Admin_Csv::csvCell('-12.50'));
        $this->assertSame("'@SUM(A1)", \HWE_Loyalty_Admin_Csv::csvCell('@SUM(A1)'));
        $this->assertSame("'\t=1", \HWE_Loyalty_Admin_Csv::csvCell("\t=1"));
        $this->assertSame("'\r=1", \HWE_Loyalty_Admin_Csv::csvCell("\r=1"));
        $this->assertSame('Cliente Siete', \HWE_Loyalty_Admin_Csv::csvCell('Cliente Siete'));
        $this->assertSame('', \HWE_Loyalty_Admin_Csv::csvCell(''));
    }

    public function testTheCsvStreamsRowsWithoutEmailOrLoginAndEscapesThem(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 10, 'admin_credit', 'earn:manual:1', [], null, [
            'actor'    => 'admin',
            'actor_id' => 1,
            'note'     => '=SUM(A1)',
        ]);
        $first = array_key_first($this->wpdb()->ledger);
        $this->wpdb()->ledger[$first]['display_name'] = '=2+2';

        $filters = \HWE_Loyalty_Admin_Movements::filters(['hwe_user' => (string) self::USER]);
        $handle  = fopen('php://memory', 'w+');
        $this->assertIsResource($handle);

        $written = \HWE_Loyalty_Admin_Csv::writeRows($handle, $filters);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $this->assertSame(1, $written);
        $this->assertStringContainsString("'=2+2", $csv, 'El nombre visible peligroso queda inerte.');
        $this->assertStringContainsString("'=SUM(A1)", $csv, 'La nota peligrosa queda inerte.');
        $this->assertStringNotContainsString('siete@example.test', $csv, 'El CSV no lleva el email.');
        $this->assertStringNotContainsString('cliente-siete', $csv, 'El CSV no lleva el login.');
    }

    // ── Caja del pedido ─────────────────────────────────────────────────────

    public function testTheOrderBoxReadsTheSharedMetaConstantsAndShowsBothScreens(): void
    {
        Functions\when('wc_get_page_screen_id')->alias(static fn (string $id): string => 'shop-order' === $id ? 'woocommerce_page_wc-orders' : '');

        $this->assertSame(
            ['shop_order', 'woocommerce_page_wc-orders'],
            \HWE_Loyalty_Admin_OrderBox::screens()
        );

        $order = new \WC_Order();
        $order->id = 55;
        $order->update_meta_data(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS, 250);
        $order->update_meta_data(\HWE_Loyalty_Earnings::META_DISCOUNT, '2.5000');
        $order->update_meta_data(\HWE_Loyalty_Earnings::META_PAID_WITH_POINTS, 'no');
        $order->update_meta_data(\HWE_Loyalty_Earnings::META_EARNINGS, [
            'version'       => 1,
            'status'        => \HWE_Loyalty_Earnings::STATUS_PROCESSED,
            'base'          => 50.0,
            'beneficiaries' => [
                ['user_id' => self::OTHER, 'type' => 'cashback', 'points' => 25, 'key' => 'k', 'credited' => true],
            ],
        ]);

        ob_start();
        \HWE_Loyalty_Admin_OrderBox::display($order);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('250', $html);
        $this->assertStringContainsString('2,50', $html);
        $this->assertStringContainsString('Procesadas', $html);
        $this->assertStringContainsString('Cliente Ocho', $html);
    }

    // ── Módulo apagado ──────────────────────────────────────────────────────

    public function testTheAdminIsNotRegisteredWhenTheModuleIsOff(): void
    {
        $this->activePlugins = [];

        Actions\expectAdded('admin_menu')->never();
        Actions\expectAdded('admin_init')->never();

        \HWE_Loyalty_Admin::register();
    }

    /** Objeto mínimo con la forma de `WP_User_Query` que usa `applySort`. */
    private function queryFor(string $orderby): object
    {
        $direction = 'ASC';

        return new class ($orderby, $direction) {
            public string $query_from = '';
            public string $query_orderby = '';
            private string $orderby;
            private string $direction;
            public function __construct(string $orderby, string $direction)
            {
                $this->orderby   = $orderby;
                $this->direction = $direction;
            }
            public function get(string $key): string
            {
                return match ($key) {
                    'orderby' => $this->orderby,
                    'order'   => $this->direction,
                    default   => '',
                };
            }
        };
    }
}

/** Rol mínimo de WordPress para probar la concesión de capacidades. */
final class TestAdminRole
{
    public string $slug;
    /** @var array<string,bool> */
    public array $caps = [];

    public function __construct(string $slug)
    {
        $this->slug = $slug;
    }

    public function add_cap(string $cap, bool $grant = true): void
    {
        $this->caps[$cap] = $grant;
    }

    public function has_cap(string $cap): bool
    {
        return !empty($this->caps[$cap]);
    }
}

/**
 * Doble de `$wpdb` con el estado mínimo del libro y los referidos.
 *
 * Interpreta solo las sentencias que emiten `HWE_Loyalty_Ledger`, `HWE_Loyalty_Referrals` y las
 * consultas propias de administración. Todo lo demás se ignora para que el test falle por una
 * decisión de negocio y no por el doble.
 */
final class TestAdminWpdb
{
    public string $prefix = 'wp_';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $ledger = [];
    /** @var list<array<string,mixed>> */
    public array $consumptions = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var list<array<string,mixed>> */
    public array $referrals = [];
    /** @var array<int,list<string>> */
    public array $userRoles = [];
    /** @var list<string> */
    public array $queries = [];
    private int $nextId = 1;
    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode($args) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_ends_with($table, 'hwe_loyalty_ledger')) {
            if (null !== $this->ledgerIdByKey((string) ($data['idempotency_key'] ?? ''))) {
                return false;
            }
            $id = $this->nextId++;
            $this->insert_id = $id;
            $this->ledger[$id] = $data + ['id' => $id];
            return 1;
        }

        if (str_ends_with($table, 'hwe_loyalty_ledger_consumptions')) {
            $id = $this->nextId++;
            $this->insert_id = $id;
            $this->consumptions[] = $data + ['id' => $id];
            return 1;
        }

        if (str_ends_with($table, 'hwe_loyalty_referrals')) {
            foreach ($this->referrals as $row) {
                if ((int) $row['user_id'] === (int) ($data['user_id'] ?? 0)) {
                    return false;
                }
                if ((string) $row['code'] === (string) ($data['code'] ?? '')) {
                    return false;
                }
            }
            $id = $this->nextId++;
            $this->insert_id = $id;
            $this->referrals[] = $data + [
                'id'           => $id,
                'referrer_id'  => null,
                'confirmed_at' => null,
            ];
            return 1;
        }

        $this->insert_id = $this->nextId++;
        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if ('START TRANSACTION' === $sql) {
            $this->snapshot = [
                'ledger'       => $this->ledger,
                'consumptions' => $this->consumptions,
                'balances'     => $this->balances,
                'referrals'    => $this->referrals,
                'nextId'       => $this->nextId,
            ];
            return 1;
        }
        if ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->ledger       = $this->snapshot['ledger'];
                $this->consumptions = $this->snapshot['consumptions'];
                $this->balances     = $this->snapshot['balances'];
                $this->referrals    = $this->snapshot['referrals'];
                $this->nextId       = $this->snapshot['nextId'];
            }
            $this->snapshot = null;
            return 1;
        }
        if ('COMMIT' === $sql) {
            $this->snapshot = null;
            return 1;
        }

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            $this->balances[$userId] ??= ['balance' => 0, 'earned' => 0, 'used' => 0];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) $args[count($args) - 1];
            $row    = $this->balances[$userId] ?? ['balance' => 0, 'earned' => 0, 'used' => 0];
            if (str_contains($sql, 'balance = balance +')) {
                $row['balance'] += (int) $args[0];
                $row['earned']  += (int) $args[1];
            } elseif (str_contains($sql, 'balance = balance -')) {
                $row['balance'] -= (int) $args[0];
                $row['used']    += (int) $args[1];
            }
            $this->balances[$userId] = $row;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_ledger')) {
            if (str_contains($sql, 'remaining_points = remaining_points - %d')) {
                [$points, $id, $minimum] = $args;
                if (isset($this->ledger[(int) $id]) && (int) $this->ledger[(int) $id]['remaining_points'] >= (int) $minimum) {
                    $this->ledger[(int) $id]['remaining_points'] -= (int) $points;
                    return 1;
                }
                return 0;
            }
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_referrals')) {
            $userId = (int) ($args[count($args) - 1] ?? 0);
            $index  = $this->referralIndexForUser($userId);
            if (null === $index) {
                return 0;
            }

            if (str_contains($sql, 'SET code = %s')) {
                $this->referrals[$index]['code'] = (string) ($args[0] ?? '');
                return 1;
            }
            if (str_contains($sql, 'SET referrer_id = NULL')) {
                $this->referrals[$index]['referrer_id'] = null;
                return 1;
            }
            if (str_contains($sql, 'SET referrer_id = %d')) {
                $this->referrals[$index]['referrer_id'] = (int) ($args[0] ?? 0);
                return 1;
            }
        }

        return 1;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'SELECT code FROM ' . $this->prefix . 'hwe_loyalty_referrals')) {
            return $this->codeOf((int) ($args[0] ?? 0)) ?: null;
        }

        if (str_contains($sql, 'idempotency_key')) {
            return $this->ledgerIdByKey((string) ($args[0] ?? ''));
        }

        if (str_contains($sql, 'COUNT(DISTINCT u.ID)')) {
            return count($this->missingParticipantIds(0));
        }

        return null;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            if (!isset($this->balances[$userId])) {
                return null;
            }
            return (object) ($this->balances[$userId] + ['user_id' => $userId]);
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_referrals')) {
            if (str_contains($sql, 'WHERE code = %s')) {
                $code = (string) ($args[0] ?? '');
                foreach ($this->referrals as $row) {
                    if ((string) $row['code'] === $code) {
                        return (object) $row;
                    }
                }
                return null;
            }
            $row = $this->referralForUser((int) ($args[0] ?? 0));
            return null === $row ? null : (object) $row;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'remaining_points > 0') && str_contains($sql, 'ORDER BY')) {
            $userId = (int) ($args[0] ?? 0);
            $rows   = [];
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId && (int) $row['points'] > 0 && (int) $row['remaining_points'] > 0) {
                    $rows[] = (object) ['id' => $row['id'], 'remaining_points' => $row['remaining_points'], 'expires_at' => $row['expires_at'] ?? null];
                }
            }
            return $rows;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[1] ?? 20);
            $offset = (int) ($args[2] ?? 0);
            $rows   = array_values(array_filter(
                $this->ledger,
                static fn (array $row): bool => (int) $row['user_id'] === $userId
            ));
            return array_map(static fn (array $row): object => (object) $row, array_slice($rows, $offset, $limit));
        }

        return [];
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        $args  = $this->args($sql);
        $limit = (int) ($args[count($args) - 1] ?? 0);

        return array_map('strval', $this->missingParticipantIds($limit));
    }

    /** @return array<string,mixed>|null */
    public function referralForUser(int $userId): ?array
    {
        foreach ($this->referrals as $row) {
            if ((int) $row['user_id'] === $userId) {
                return $row;
            }
        }
        return null;
    }

    public function codeOf(int $userId): string
    {
        return (string) ($this->referralForUser($userId)['code'] ?? '');
    }

    /** @return list<int> Ids de participantes sin fila de referidos, en orden ascendente. */
    private function missingParticipantIds(int $limit): array
    {
        $ids = [];
        foreach (array_keys($this->userRoles) as $userId) {
            if (null !== $this->referralForUser((int) $userId)) {
                continue;
            }
            $ids[] = (int) $userId;
        }
        sort($ids);
        return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    private function ledgerIdByKey(string $key): ?int
    {
        if ('' === $key) {
            return null;
        }
        foreach ($this->ledger as $id => $row) {
            if ((string) $row['idempotency_key'] === $key) {
                return (int) $id;
            }
        }
        return null;
    }

    private function referralIndexForUser(int $userId): ?int
    {
        foreach ($this->referrals as $index => $row) {
            if ((int) $row['user_id'] === $userId) {
                return $index;
            }
        }
        return null;
    }

    /** @return array<int,mixed> */
    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }
}
