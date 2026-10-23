<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de referidos: códigos, atribución y
 * confirmación del alta (tarea 5).
 *
 * Se prueba la DECISIÓN: qué código nace (aleatorio, sin login ni email, con reintento ante
 * colisión), qué atribuciones se aceptan o se ignoran sin escribir, cuándo una atribución
 * pasa de `pending` a `confirmed` y qué abonos de alta se pagan (una sola vez) al confirmar.
 * El doble de `$wpdb` mantiene el estado mínimo de las tablas de referidos y del libro para
 * poder comprobar el efecto real; no interpreta SQL arbitrario.
 *
 * Foco de revisión 4: un referidor eliminado no recibe puntos y las lecturas de red no
 * rompen ni devuelven un id inexistente.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReferralsTest extends TestCase
{
    private const REFERRED = 7;
    private const REFERRER = 8;
    private const GRANDPARENT = 9;
    private const OTHER = 10;
    private const NON_PARTICIPANT = 99;
    private const MISSING = 404;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<int,array<string,mixed>> Meta de usuario en memoria. */
    private array $userMeta = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<int,bool> Resultado del filtro `hwe_account_is_pending`. */
    private array $pending = [];
    private int $now = 1_800_000_000;
    private bool $admin = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'            => '1',
                'referrals_enabled'         => '1',
                'referral_signup_points_l1' => '50',
                'referral_signup_points_l2' => '20',
                'level2_enabled'            => '1',
                'events'                    => ['registration' => '10', 'review' => '0', 'birthday' => '0'],
                'allowed_roles'             => 'customer, subscriber',
            ],
        ];
        $this->users = [
            self::REFERRED => (object) [
                'ID'           => self::REFERRED,
                'roles'        => ['customer'],
                'display_name' => 'Ana Visible',
                'user_login'   => 'ana_login',
                'user_email'   => 'ana@example.com',
            ],
            self::REFERRER => (object) [
                'ID'           => self::REFERRER,
                'roles'        => ['customer'],
                'display_name' => 'Beto Visible',
                'user_login'   => 'beto_login',
                'user_email'   => 'beto@example.com',
            ],
            self::GRANDPARENT => (object) [
                'ID'           => self::GRANDPARENT,
                'roles'        => ['subscriber'],
                'display_name' => 'Carla Visible',
                'user_login'   => 'carla_login',
                'user_email'   => 'carla@example.com',
            ],
            self::OTHER => (object) [
                'ID'           => self::OTHER,
                'roles'        => ['customer'],
                'display_name' => 'Dani Visible',
                'user_login'   => 'dani_login',
                'user_email'   => 'dani@example.com',
            ],
            self::NON_PARTICIPANT => (object) [
                'ID'           => self::NON_PARTICIPANT,
                'roles'        => ['administrator'],
                'display_name' => 'Admin',
                'user_login'   => 'admin_login',
                'user_email'   => 'admin@example.com',
            ],
        ];
        $this->userMeta = [];
        $this->logged   = [];
        $this->pending  = [];
        $this->now      = 1_800_000_000;
        $this->admin    = false;

        $GLOBALS['wpdb'] = new TestReferralsWpdb();

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
        ]);
        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return ['hwe-loyalty/hwe-loyalty.php'];
            }
            return $default;
        });
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_user_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->userMeta[$id][$key] ?? ''
        );
        Functions\when('update_user_meta')->alias(function (int $id, string $key, mixed $value): bool {
            $this->userMeta[$id][$key] = $value;
            return true;
        });
        Functions\when('delete_user_meta')->alias(function (int $id, string $key): bool {
            unset($this->userMeta[$id][$key]);
            return true;
        });
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('is_admin')->alias(fn (): bool => $this->admin);
        Functions\when('current_user_can')->alias(
            fn (string $capability): bool => $this->admin && 'create_users' === $capability
        );
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('apply_filters')->alias(
            function (string $hook, mixed $value, mixed ...$args): mixed {
                if ('hwe_account_is_pending' === $hook) {
                    return $this->pending[(int) ($args[0] ?? 0)] ?? false;
                }
                return $value;
            }
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    private function wpdb(): TestReferralsWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function row(int $userId): ?array
    {
        return $this->wpdb()->referralForUser($userId);
    }

    private function codeOf(int $userId): string
    {
        return (string) ($this->row($userId)['code'] ?? '');
    }

    private function verifyEmail(int $userId): void
    {
        $this->userMeta[$userId]['hwe_email_verified'] = '1';
    }

    /** @param array<string,mixed> $overrides */
    private function setLoyalty(array $overrides): void
    {
        $this->config['loyalty'] = array_merge($this->config['loyalty'], $overrides);
    }

    /** @return list<array<string,mixed>> Movimientos del libro del usuario. */
    private function entriesOf(int $userId): array
    {
        return array_values(array_filter(
            $this->wpdb()->ledger,
            static fn (array $row): bool => (int) $row['user_id'] === $userId
        ));
    }

    /** @return list<string> */
    private function creditTypesOf(int $userId): array
    {
        return array_map(static fn (array $row): string => (string) $row['type'], $this->entriesOf($userId));
    }

    /** @return list<string> */
    private function creditKeysOf(int $userId): array
    {
        return array_map(static fn (array $row): string => (string) $row['idempotency_key'], $this->entriesOf($userId));
    }

    // ── Códigos ──────────────────────────────────────────────────────────────

    public function test_the_code_is_random_eight_characters_and_never_derived_from_login_or_email(): void
    {
        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRED);

        $this->assertSame(8, strlen($code));
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
        $this->assertStringNotContainsString('ana_login', $code);
        $this->assertStringNotContainsString('ana@example.com', $code);
        $this->assertStringNotContainsString('@', $code);

        $other = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);
        $this->assertNotSame($code, $other, 'Cada usuario recibe su propio código.');
        $this->assertSame($code, \HWE_Loyalty_Referrals::codeFor(self::REFERRED), 'El código no cambia al pedirlo otra vez.');
        $this->assertCount(2, $this->wpdb()->insertedReferrals());
    }

    public function test_a_collision_with_the_unique_index_retries_with_another_code(): void
    {
        $this->wpdb()->failReferralInserts = 1;

        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRED);

        $this->assertSame(8, strlen($code));
        $this->assertCount(
            2,
            $this->wpdb()->insertedReferrals(),
            'El primer intento chocó con el índice único y se reintentó.'
        );
        $this->assertSame($code, $this->codeOf(self::REFERRED));
    }

    public function test_a_missing_or_non_participant_user_gets_no_code(): void
    {
        $this->assertSame('', \HWE_Loyalty_Referrals::codeFor(self::MISSING));
        $this->assertSame('', \HWE_Loyalty_Referrals::codeFor(self::NON_PARTICIPANT));
        $this->assertSame([], $this->wpdb()->referrals, 'No se escribe una fila para quien no participa.');
    }

    public function test_user_by_code_normalizes_and_rejects_malformed_codes_without_querying(): void
    {
        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRED);
        $this->assertSame(self::REFERRED, \HWE_Loyalty_Referrals::userByCode(strtolower('  ' . $code . '  ')));
        $queriesBefore = count($this->wpdb()->queries);

        $this->assertNull(\HWE_Loyalty_Referrals::userByCode('ABC'));
        $this->assertNull(\HWE_Loyalty_Referrals::userByCode('00000000'));
        $this->assertNull(\HWE_Loyalty_Referrals::userByCode('AAAAAAAAA'));
        $this->assertCount(
            $queriesBefore,
            $this->wpdb()->queries,
            'Un formato inválido no llega a consultar la tabla.'
        );
    }

    public function test_a_code_of_a_deleted_user_is_not_resolved(): void
    {
        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRED);
        unset($this->users[self::REFERRED]);

        $this->assertNull(\HWE_Loyalty_Referrals::userByCode($code));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    // ── Atribución ───────────────────────────────────────────────────────────

    public function test_attribution_is_born_pending_with_its_referrer(): void
    {
        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);

        $this->assertSame('attributed', \HWE_Loyalty_Referrals::attribute(self::REFERRED, $code));

        $row = $this->row(self::REFERRED);
        $this->assertSame(self::REFERRER, (int) $row['referrer_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['confirmed_at']);
        $this->assertNotEmpty($row['attributed_at']);
        $this->assertSame([], $this->entriesOf(self::REFERRER), 'Nacer pendiente no abona nada todavía.');
    }

    public function test_attribution_ignores_unknown_self_non_participant_repeated_and_disabled_codes_without_writing(): void
    {
        $own   = \HWE_Loyalty_Referrals::codeFor(self::REFERRED);
        $other = \HWE_Loyalty_Referrals::codeFor(self::OTHER);
        \HWE_Loyalty_Referrals::setCode(self::NON_PARTICIPANT, 'ADMNCDE2');
        $before = count($this->wpdb()->referrals);

        $this->assertSame('ignored:invalid_code', \HWE_Loyalty_Referrals::attribute(self::REFERRED, 'ZZZZZZZZ'));
        $this->assertSame('ignored:self', \HWE_Loyalty_Referrals::attribute(self::REFERRED, $own));
        $this->assertSame(
            'ignored:not_participant',
            \HWE_Loyalty_Referrals::attribute(self::REFERRED, 'ADMNCDE2')
        );
        $this->assertNull($this->row(self::REFERRED)['referrer_id'] ?? null);
        $this->assertCount($before, $this->wpdb()->referrals, 'Ningún ignorado escribe una atribución.');

        $this->assertSame('attributed', \HWE_Loyalty_Referrals::attribute(self::REFERRED, $other));
        $this->assertSame(
            'ignored:already_referred',
            \HWE_Loyalty_Referrals::attribute(self::REFERRED, $other)
        );

        $this->setLoyalty(['referrals_enabled' => '0']);
        $this->assertSame('ignored:disabled', \HWE_Loyalty_Referrals::attribute(self::OTHER, $own));

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringContainsString('[hwe] loyalty:', $line);
            $this->assertStringNotContainsString('@', $line, 'Los registros no llevan PII.');
        }
    }

    public function test_a_cycle_is_rejected(): void
    {
        $referrerCode = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);
        $referredCode = \HWE_Loyalty_Referrals::codeFor(self::REFERRED);

        $this->assertSame('attributed', \HWE_Loyalty_Referrals::attribute(self::REFERRED, $referrerCode));
        $this->assertSame('ignored:cycle', \HWE_Loyalty_Referrals::attribute(self::REFERRER, $referredCode));
        $this->assertNull($this->row(self::REFERRER)['referrer_id'] ?? null);
    }

    // ── Confirmación ─────────────────────────────────────────────────────────

    public function test_attribution_stays_pending_until_the_account_is_confirmed(): void
    {
        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);
        \HWE_Loyalty_Referrals::attribute(self::REFERRED, $code);

        $this->assertFalse(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $this->assertSame('pending', $this->row(self::REFERRED)['status']);
        $this->assertSame(self::REFERRER, \HWE_Loyalty_Referrals::referrerOf(self::REFERRED));
        $this->assertNull(
            \HWE_Loyalty_Referrals::referrerOf(self::REFERRED, true),
            'Con la atribución pendiente no es un referidor confirmado.'
        );

        $this->verifyEmail(self::REFERRED);

        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $row = $this->row(self::REFERRED);
        $this->assertSame('confirmed', $row['status']);
        $this->assertNotEmpty($row['confirmed_at']);
        $this->assertSame(self::REFERRER, \HWE_Loyalty_Referrals::referrerOf(self::REFERRED, true));

        $this->assertSame(
            'ignored:not_participant',
            \HWE_Loyalty_Referrals::attribute(self::NON_PARTICIPANT, $code),
            'Un usuario que no participa no puede ser referido.'
        );
    }

    public function test_a_private_login_account_confirms_whichever_condition_arrives_last(): void
    {
        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);
        \HWE_Loyalty_Referrals::attribute(self::REFERRED, $code);
        $this->pending[self::REFERRED] = true;

        // Orden A: llegó la aprobación antes que la verificación del email.
        $this->assertFalse(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $this->verifyEmail(self::REFERRED);
        $this->assertFalse(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED), 'Sigue pendiente de aprobación.');
        $this->pending[self::REFERRED] = false;
        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));

        // Orden B: la verificación llegó primero y la aprobación después.
        $this->pending[self::OTHER] = true;
        $otherCode = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);
        \HWE_Loyalty_Referrals::attribute(self::OTHER, $otherCode);
        $this->verifyEmail(self::OTHER);
        $this->assertFalse(\HWE_Loyalty_Referrals::confirmIfReady(self::OTHER));
        $this->pending[self::OTHER] = false;
        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::OTHER));

        $this->assertSame('confirmed', $this->row(self::OTHER)['status']);
    }

    public function test_an_attribution_that_arrives_after_the_verification_confirms_immediately(): void
    {
        // La cuenta ya estaba confirmada (email verificado) antes de conocer su referidor.
        $this->verifyEmail(self::REFERRED);
        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $this->assertSame('confirmed', $this->row(self::REFERRED)['status']);
        $this->assertSame(['registration'], $this->creditTypesOf(self::REFERRED));

        $code = \HWE_Loyalty_Referrals::codeFor(self::REFERRER);

        $this->assertSame('attributed', \HWE_Loyalty_Referrals::attribute(self::REFERRED, $code));

        $row = $this->row(self::REFERRED);
        $this->assertSame(self::REFERRER, (int) $row['referrer_id']);
        $this->assertSame('confirmed', $row['status'], 'Lo que llega último dispara la confirmación.');
        $this->assertNotEmpty($row['confirmed_at']);
        $this->assertSame(['referral_signup_l1'], $this->creditTypesOf(self::REFERRER));
        $this->assertSame(['registration'], $this->creditTypesOf(self::REFERRED), 'El alta no se duplica.');
    }

    public function test_an_account_created_by_an_administrator_counts_as_confirmed(): void
    {
        $this->admin = true;

        \HWE_Loyalty_Referrals::onUserRegistered(self::REFERRED);

        $this->assertNotEmpty($this->codeOf(self::REFERRED));
        $this->assertSame('confirmed', $this->row(self::REFERRED)['status']);
        $this->assertSame(['registration'], $this->creditTypesOf(self::REFERRED));
        $this->assertSame(10, (int) $this->entriesOf(self::REFERRED)[0]['points']);
    }

    public function test_a_normal_registration_stays_pending_and_pays_nothing(): void
    {
        \HWE_Loyalty_Referrals::onUserRegistered(self::REFERRED);

        $this->assertNotEmpty($this->codeOf(self::REFERRED), 'El código se asigna al crear la cuenta.');
        $this->assertSame('pending', $this->row(self::REFERRED)['status']);
        $this->assertSame([], $this->entriesOf(self::REFERRED));
    }

    public function test_confirmation_pays_level_one_level_two_and_registration_only_once(): void
    {
        // 7 ← 8 ← 9
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRER, self::GRANDPARENT);
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        $this->verifyEmail(self::REFERRED);

        Actions\expectDone('hwe_loyalty_attribution_confirmed')
            ->once()
            ->with(self::REFERRED, self::REFERRER);

        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));

        $this->assertSame(['referral_signup_l1'], $this->creditTypesOf(self::REFERRER));
        $this->assertSame(['signup:l1:' . self::REFERRED], $this->creditKeysOf(self::REFERRER));
        $this->assertSame(50, (int) $this->entriesOf(self::REFERRER)[0]['points']);

        $this->assertSame(['referral_signup_l2'], $this->creditTypesOf(self::GRANDPARENT));
        $this->assertSame(['signup:l2:' . self::REFERRED], $this->creditKeysOf(self::GRANDPARENT));
        $this->assertSame(20, (int) $this->entriesOf(self::GRANDPARENT)[0]['points']);

        $this->assertSame(['registration'], $this->creditTypesOf(self::REFERRED));
        $this->assertSame(['registration:' . self::REFERRED], $this->creditKeysOf(self::REFERRED));

        $this->assertFalse(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED), 'Ya estaba confirmada.');
        $this->assertCount(1, $this->entriesOf(self::REFERRER));
        $this->assertCount(1, $this->entriesOf(self::GRANDPARENT));
        $this->assertCount(1, $this->entriesOf(self::REFERRED));
    }

    public function test_a_failed_confirmation_write_pays_nothing(): void
    {
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        $this->verifyEmail(self::REFERRED);
        $this->wpdb()->failConfirmationUpdates = 1;

        $this->assertFalse(
            \HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED),
            'Si la confirmación no se pudo escribir, no se da por confirmada.'
        );

        $this->assertSame('pending', $this->row(self::REFERRED)['status']);
        $this->assertSame([], $this->entriesOf(self::REFERRER), 'No se paga el alta sin confirmar.');
        $this->assertSame([], $this->entriesOf(self::REFERRED), 'Tampoco el abono de registro.');
        $this->assertNotEmpty($this->logged);

        // El reintento, ya sin el fallo, confirma y paga una sola vez.
        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $this->assertSame('confirmed', $this->row(self::REFERRED)['status']);
        $this->assertCount(1, $this->entriesOf(self::REFERRER));
        $this->assertCount(1, $this->entriesOf(self::REFERRED));
    }

    public function test_registration_is_paid_even_without_a_referrer(): void
    {
        \HWE_Loyalty_Referrals::codeFor(self::REFERRED);
        $this->verifyEmail(self::REFERRED);

        Actions\expectDone('hwe_loyalty_attribution_confirmed')
            ->once()
            ->with(self::REFERRED, null);

        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));

        $this->assertSame(['registration'], $this->creditTypesOf(self::REFERRED));
        $this->assertSame(10, (int) $this->entriesOf(self::REFERRED)[0]['points']);
    }

    public function test_level_two_is_not_paid_when_the_setting_is_off_or_the_points_are_zero(): void
    {
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRER, self::GRANDPARENT);
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        $this->verifyEmail(self::REFERRED);
        $this->setLoyalty(['level2_enabled' => '0']);

        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));

        $this->assertSame([], $this->entriesOf(self::GRANDPARENT));

        // Con nivel 2 encendido pero importe 0 tampoco se escribe.
        $this->setLoyalty(['level2_enabled' => '1', 'referral_signup_points_l2' => '0']);
        $this->setLoyalty(['referral_signup_points_l1' => '0']);
        $this->pending[self::OTHER] = false;
        \HWE_Loyalty_Referrals::setReferrer(self::OTHER, self::REFERRER);
        $this->verifyEmail(self::OTHER);
        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::OTHER));
        $this->assertSame([], $this->entriesOf(self::GRANDPARENT));
    }

    public function test_a_deleted_referrer_is_never_credited_and_the_network_survives(): void
    {
        \HWE_Loyalty_Referrals::codeFor(self::REFERRER);
        \HWE_Loyalty_Referrals::attribute(self::REFERRED, $this->codeOf(self::REFERRER));
        unset($this->users[self::REFERRER]);
        $this->verifyEmail(self::REFERRED);

        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $this->assertSame([], $this->entriesOf(self::REFERRER), 'No se abona a un id inexistente.');
        $this->assertNull(\HWE_Loyalty_Referrals::referrerOf(self::REFERRED));
        $this->assertSame(['registration'], $this->creditTypesOf(self::REFERRED));

        // Un referido eliminado no rompe la red y no filtra datos de la cuenta.
        unset($this->users[self::REFERRED]);
        $network = \HWE_Loyalty_Referrals::network(self::REFERRER, 1, 1, 10);
        $this->assertSame(1, $network['total']);
        $this->assertSame('', $network['items'][0]['name']);
        $this->assertStringNotContainsString('@', json_encode($network) ?: '');
    }

    // ── Red ──────────────────────────────────────────────────────────────────

    public function test_network_lists_level_one_and_two_with_visible_names_and_generated_points(): void
    {
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRER, self::GRANDPARENT);
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        \HWE_Loyalty_Referrals::setReferrer(self::OTHER, self::REFERRER);

        // Puntos generados para el referidor por cada referido (comisión de ejemplo).
        \HWE_Loyalty_Ledger::credit(
            self::REFERRER,
            30,
            'referral_signup_l1',
            'test:commission:7',
            ['type' => 'user', 'id' => self::REFERRED]
        );
        \HWE_Loyalty_Ledger::credit(
            self::GRANDPARENT,
            15,
            'referral_commission_l2',
            'test:commission:7:l2',
            ['type' => 'user', 'id' => self::REFERRED]
        );

        $level1 = \HWE_Loyalty_Referrals::network(self::REFERRER, 1, 1, 10);
        $this->assertSame(2, $level1['total']);
        $names = array_column($level1['items'], 'name');
        $this->assertEqualsCanonicalizing(['Ana Visible', 'Dani Visible'], $names);
        $this->assertStringNotContainsString('@', json_encode($level1) ?: '');
        $this->assertStringNotContainsString('ana_login', json_encode($level1) ?: '');

        $byUser = array_column($level1['items'], null, 'user_id');
        $this->assertSame(30, $byUser[self::REFERRED]['points_generated']);
        $this->assertSame(0, $byUser[self::OTHER]['points_generated']);
        $this->assertSame('pending', $byUser[self::REFERRED]['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $byUser[self::REFERRED]['date']);

        $level2 = \HWE_Loyalty_Referrals::network(self::GRANDPARENT, 2, 1, 10);
        $this->assertSame(2, $level2['total']);
        $n2 = array_column($level2['items'], null, 'user_id');
        $this->assertSame(15, $n2[self::REFERRED]['points_generated']);
        $this->assertSame(0, $n2[self::OTHER]['points_generated']);
        $this->assertSame('Dani Visible', $n2[self::OTHER]['name']);
    }

    public function test_network_paginates_and_clamps_the_page_size(): void
    {
        for ($i = 11; $i <= 14; $i++) {
            $this->users[$i] = (object) [
                'ID' => $i,
                'roles' => ['customer'],
                'display_name' => 'Usuario ' . $i,
                'user_login' => 'u' . $i,
                'user_email' => 'u' . $i . '@example.com',
            ];
            \HWE_Loyalty_Referrals::setReferrer($i, self::REFERRER);
        }

        $page = \HWE_Loyalty_Referrals::network(self::REFERRER, 1, 2, 2);

        $this->assertSame(4, $page['total']);
        $this->assertCount(2, $page['items']);
        $this->assertSame(2, $page['per_page']);
        $this->assertSame(2, $page['page']);
    }

    public function test_network_totals_count_levels_pending_and_points_generated(): void
    {
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        \HWE_Loyalty_Referrals::setReferrer(self::OTHER, self::REFERRER);
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRER, self::GRANDPARENT);

        // La red de GRANDPARENT: N1 = REFERRER; N2 = REFERRED y OTHER. Los abonos con
        // referencia a un miembro de la red son lo «generado»; el cashback (pedido) no cuenta.
        \HWE_Loyalty_Ledger::credit(
            self::GRANDPARENT,
            15,
            'referral_commission_l2',
            'test:totals:1',
            ['type' => 'user', 'id' => self::REFERRED]
        );
        \HWE_Loyalty_Ledger::credit(
            self::GRANDPARENT,
            5,
            'referral_commission_l2',
            'test:totals:2',
            ['type' => 'user', 'id' => self::OTHER]
        );
        \HWE_Loyalty_Ledger::credit(
            self::GRANDPARENT,
            7,
            'cashback',
            'test:totals:3',
            ['type' => 'order', 'id' => 55]
        );
        \HWE_Loyalty_Ledger::credit(
            self::REFERRER,
            9,
            'cashback',
            'test:totals:4',
            ['type' => 'user', 'id' => self::REFERRED]
        );

        $this->assertSame(
            ['level1' => 1, 'level2' => 2, 'pending' => 3, 'earned' => 20],
            \HWE_Loyalty_Referrals::networkTotals(self::GRANDPARENT)
        );
        $this->assertSame(
            ['level1' => 0, 'level2' => 0, 'pending' => 0, 'earned' => 0],
            \HWE_Loyalty_Referrals::networkTotals(self::MISSING)
        );
    }

    public function test_network_points_generated_ignore_transfers_from_the_network(): void
    {
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRER, self::GRANDPARENT);

        \HWE_Loyalty_Ledger::credit(
            self::REFERRER,
            30,
            'referral_commission_l1',
            'test:generated:1',
            ['type' => 'user', 'id' => self::REFERRED]
        );
        // Una transferencia también referencia al usuario que la envía, pero no la «genera la
        // red»: no puede contar como comisión ni en la lista ni en los totales.
        \HWE_Loyalty_Ledger::credit(
            self::REFERRER,
            500,
            'transfer_in',
            'test:generated:2',
            ['type' => 'user', 'id' => self::REFERRED]
        );

        $level1 = \HWE_Loyalty_Referrals::network(self::REFERRER, 1, 1, 10);
        $byUser = array_column($level1['items'], null, 'user_id');
        $this->assertSame(30, $byUser[self::REFERRED]['points_generated']);
        $this->assertSame(30, \HWE_Loyalty_Referrals::networkTotals(self::REFERRER)['earned']);
    }

    // ── Administración ───────────────────────────────────────────────────────

    public function test_set_code_validates_the_format_and_uniqueness(): void
    {
        $this->assertTrue(\HWE_Loyalty_Referrals::setCode(self::REFERRED, 'gdfhjkmn'));
        $this->assertSame('GDFHJKMN', $this->codeOf(self::REFERRED), 'Se guarda en mayúsculas.');

        $this->assertFalse(\HWE_Loyalty_Referrals::setCode(self::REFERRER, 'GDFHJKMN'), 'El código es único.');
        $this->assertFalse(\HWE_Loyalty_Referrals::setCode(self::REFERRED, 'corto'));
        $this->assertFalse(\HWE_Loyalty_Referrals::setCode(self::MISSING, 'RTY23456'));

        $this->assertTrue(\HWE_Loyalty_Referrals::setCode(self::REFERRER, 'WTY23456'));
        $this->assertSame('WTY23456', $this->codeOf(self::REFERRER));
    }

    public function test_set_referrer_respects_self_and_cycles_and_records_the_change(): void
    {
        $this->assertTrue(\HWE_Loyalty_Referrals::setReferrer(self::REFERRER, self::OTHER));
        $this->assertFalse(\HWE_Loyalty_Referrals::setReferrer(self::OTHER, self::REFERRER), 'Ciclo.');
        $this->assertFalse(\HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRED), 'Autorreferido.');
        $this->assertFalse(\HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::MISSING));

        $this->assertTrue(\HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER));
        $this->assertSame(self::REFERRER, (int) $this->row(self::REFERRED)['referrer_id']);
        $this->assertSame([], $this->wpdb()->ledger, 'Cambiar la red no mueve puntos.');
        $this->assertArrayHasKey('_hwe_loyalty_referrer_change', $this->userMeta[self::REFERRED]);

        $this->assertTrue(\HWE_Loyalty_Referrals::setReferrer(self::REFERRED, null));
        $this->assertNull($this->row(self::REFERRED)['referrer_id']);
        $this->assertSame('pending', $this->row(self::REFERRED)['status']);
    }

    public function test_changing_a_confirmed_referrer_does_not_pay_the_signup_bonus_twice(): void
    {
        \HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::REFERRER);
        $this->verifyEmail(self::REFERRED);
        $this->assertTrue(\HWE_Loyalty_Referrals::confirmIfReady(self::REFERRED));
        $this->assertCount(1, $this->entriesOf(self::REFERRER));

        $this->assertTrue(\HWE_Loyalty_Referrals::setReferrer(self::REFERRED, self::OTHER));

        $this->assertCount(1, $this->entriesOf(self::REFERRER), 'Lo ya pagado no se move.');
        $this->assertSame([], $this->entriesOf(self::OTHER), 'La clave de idempotencia ya existe.');
        $this->assertSame('confirmed', $this->row(self::REFERRED)['status']);
    }

    // ── Hooks ────────────────────────────────────────────────────────────────

    public function test_register_hooks_account_creation_and_both_confirmation_signals(): void
    {
        Actions\expectAdded('user_register')
            ->once()
            ->with([\HWE_Loyalty_Referrals::class, 'onUserRegistered']);
        Actions\expectAdded('hwe_email_verified')
            ->once()
            ->with([\HWE_Loyalty_Referrals::class, 'confirmIfReady']);
        Actions\expectAdded('hwe_account_approved')
            ->once()
            ->with([\HWE_Loyalty_Referrals::class, 'confirmIfReady']);

        \HWE_Loyalty_Referrals::register();
    }
}

/**
 * Doble de `$wpdb` para referidos y para los abonos de alta.
 *
 * Mantiene las filas de referidos y el libro en memoria y entiende solo las sentencias que
 * emiten `HWE_Loyalty_Referrals` y `HWE_Loyalty_Ledger::credit`: buscar por usuario/código,
 * insertar, cambiar referidor/estado/código, contar y paginar la red N1/N2, y las lecturas y
 * escrituras mínimas del saldo que hace el libro. Cualquier otra consulta devuelve vacío.
 */
final class TestReferralsWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $referrals = [];
    /** @var array<int,array<string,mixed>> */
    public array $ledger = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];
    /** Fuerza que los primeros INSERT de referidos fallen (simula una colisión del índice). */
    public int $failReferralInserts = 0;
    /** Fuerza que los primeros UPDATE de confirmación fallen (simula un error de escritura). */
    public int $failConfirmationUpdates = 0;
    private int $nextReferralId = 1;
    private int $nextLedgerId = 1;

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
        $this->inserts[] = [$table, $data];

        if (str_ends_with($table, 'hwe_loyalty_referrals')) {
            if ($this->failReferralInserts > 0) {
                $this->failReferralInserts--;
                return false;
            }
            foreach ($this->referrals as $row) {
                if ((int) $row['user_id'] === (int) $data['user_id']) {
                    return false;
                }
                if ((string) $row['code'] === (string) $data['code']) {
                    return false;
                }
            }
            $id = $this->nextReferralId++;
            $this->insert_id = $id;
            $this->referrals[$id] = $data + [
                'referrer_id' => null,
                'confirmed_at' => null,
                'id' => $id,
            ];
            return 1;
        }

        if (str_ends_with($table, 'hwe_loyalty_ledger')) {
            foreach ($this->ledger as $row) {
                if ((string) $row['idempotency_key'] === (string) $data['idempotency_key']) {
                    return false;
                }
            }
            $id = $this->nextLedgerId++;
            $this->insert_id = $id;
            $this->ledger[$id] = $data + ['id' => $id];
            return 1;
        }

        $this->insert_id = $this->nextLedgerId++;
        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            $this->balances[$userId] ??= ['balance' => 0, 'earned' => 0, 'used' => 0];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[count($args) - 1] ?? 0);
            $row    = $this->balances[$userId] ?? ['balance' => 0, 'earned' => 0, 'used' => 0];
            if (str_contains($sql, 'balance = balance +')) {
                $row['balance'] += (int) ($args[0] ?? 0);
                $row['earned']  += (int) ($args[1] ?? 0);
            }
            $this->balances[$userId] = $row;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_referrals')) {
            $userId = (int) ($args[count($args) - 1] ?? 0);
            if (str_contains($sql, 'SET status = %s, confirmed_at = %s WHERE user_id = %d')) {
                if ($this->failConfirmationUpdates > 0) {
                    $this->failConfirmationUpdates--;
                    return false;
                }
                [$status, $confirmedAt, $id, $skip] = array_pad($args, 4, '');
                $id = (int) $id;
                if (isset($this->referrals[$this->referralIdForUser($id)])
                    && $this->referrals[$this->referralIdForUser($id)]['status'] !== $skip) {
                    $this->referrals[$this->referralIdForUser($id)]['status']       = (string) $status;
                    $this->referrals[$this->referralIdForUser($id)]['confirmed_at'] = $confirmedAt;
                    return 1;
                }
                return 0;
            }
            if (str_contains($sql, 'SET referrer_id = NULL')) {
                [$status, $attributedAt, $id] = $args;
                $referralId = $this->referralIdForUser((int) $id);
                if (null === $referralId) {
                    return 0;
                }
                $this->referrals[$referralId]['referrer_id']  = null;
                $this->referrals[$referralId]['status']       = (string) $status;
                $this->referrals[$referralId]['attributed_at'] = (string) $attributedAt;
                $this->referrals[$referralId]['confirmed_at'] = null;
                return 1;
            }
            if (str_contains($sql, 'SET referrer_id = %d') && str_contains($sql, 'confirmed_at = NULL WHERE')) {
                [$referrerId, $status, $attributedAt, $id] = $args;
                $referralId = $this->referralIdForUser((int) $id);
                if (null === $referralId || null !== $this->referrals[$referralId]['referrer_id']) {
                    return 0;
                }
                $this->referrals[$referralId]['referrer_id']   = (int) $referrerId;
                $this->referrals[$referralId]['status']        = (string) $status;
                $this->referrals[$referralId]['attributed_at'] = (string) $attributedAt;
                $this->referrals[$referralId]['confirmed_at']  = null;
                return 1;
            }
            if (str_contains($sql, 'SET referrer_id = %d')) {
                [$referrerId, $status, $attributedAt, $confirmedAt, $id] = $args;
                $referralId = $this->referralIdForUser((int) $id);
                if (null === $referralId) {
                    return 0;
                }
                $this->referrals[$referralId]['referrer_id']   = (int) $referrerId;
                $this->referrals[$referralId]['status']        = (string) $status;
                $this->referrals[$referralId]['attributed_at'] = (string) $attributedAt;
                $this->referrals[$referralId]['confirmed_at']  = $confirmedAt;
                return 1;
            }
            if (str_contains($sql, 'SET code = %s')) {
                [$code, $id] = $args;
                $referralId = $this->referralIdForUser((int) $id);
                if (null === $referralId) {
                    return 0;
                }
                $this->referrals[$referralId]['code'] = (string) $code;
                return 1;
            }
        }

        return 1;
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
            $userId  = (int) ($args[0] ?? 0);
            $refType = (string) ($args[1] ?? '');
            $members = array_map('strval', $this->networkMembers($userId));
            $types   = array_map('strval', array_slice($args, 4));
            $sum     = 0;
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId
                    && (int) $row['points'] > 0
                    && (string) ($row['ref_type'] ?? '') === $refType
                    && ([] === $types || in_array((string) $row['type'], $types, true))
                    && in_array((string) ($row['ref_id'] ?? ''), $members, true)) {
                    $sum += (int) $row['points'];
                }
            }
            return $sum;
        }

        if (str_contains($sql, 'COALESCE(SUM')) {
            $userId  = (int) ($args[0] ?? 0);
            $refType = (string) ($args[1] ?? '');
            $refId   = (string) ($args[2] ?? '');
            $types   = array_map('strval', array_slice($args, 3));
            $sum     = 0;
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId
                    && (int) $row['points'] > 0
                    && (string) ($row['ref_type'] ?? '') === $refType
                    && ([] === $types || in_array((string) $row['type'], $types, true))
                    && (string) ($row['ref_id'] ?? '') === $refId) {
                    $sum += (int) $row['points'];
                }
            }
            return $sum;
        }

        if (str_contains($sql, 'COUNT(*)')) {
            $userId = (int) ($args[0] ?? 0);
            $count  = 0;
            foreach ($this->referrals as $row) {
                $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
                if (str_contains($sql, 'INNER JOIN')) {
                    $parent = null === $referrer ? null : $this->referralForUser($referrer);
                    if (null !== $parent && null !== $parent['referrer_id'] && (int) $parent['referrer_id'] === $userId) {
                        $count++;
                    }
                } elseif ($referrer === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        if (str_contains($sql, 'idempotency_key')) {
            foreach ($this->ledger as $row) {
                if ((string) $row['idempotency_key'] === (string) ($args[0] ?? '')) {
                    return (int) $row['id'];
                }
            }
            return null;
        }

        return null;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->balances[$userId])
                ? (object) ($this->balances[$userId] + ['user_id' => $userId])
                : null;
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
        $userId = (int) ($args[0] ?? 0);
        $limit  = (int) ($args[1] ?? 10);
        $offset = (int) ($args[2] ?? 0);

        $rows = [];
        foreach ($this->referrals as $row) {
            $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
            if (str_contains($sql, 'INNER JOIN')) {
                $parent = null === $referrer ? null : $this->referralForUser($referrer);
                if (null === $parent || null === $parent['referrer_id'] || (int) $parent['referrer_id'] !== $userId) {
                    continue;
                }
            } elseif ($referrer !== $userId) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['attributed_at'], (int) $b['user_id']] <=> [$a['attributed_at'], (int) $a['user_id']];
        });

        return array_map(
            static fn (array $row): object => (object) $row,
            array_slice($rows, $offset, $limit)
        );
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    /** @return list<array{0:string,1:array<string,mixed>}> */
    public function insertedReferrals(): array
    {
        return array_values(array_filter(
            $this->inserts,
            static fn (array $insert): bool => str_ends_with($insert[0], 'hwe_loyalty_referrals')
        ));
    }

    public function referralForUser(int $userId): ?array
    {
        foreach ($this->referrals as $row) {
            if ((int) $row['user_id'] === $userId) {
                return $row;
            }
        }
        return null;
    }

    /** @return list<int> Miembros de la red directa y de segundo nivel. */
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

    /** Referidor de un usuario, para resolver el segundo nivel. */
    private function parentOf(?int $userId): ?int
    {
        if (null === $userId) {
            return null;
        }
        $row = $this->referralForUser($userId);
        return null === $row || null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
    }

    private function referralIdForUser(int $userId): ?int
    {
        foreach ($this->referrals as $id => $row) {
            if ((int) $row['user_id'] === $userId) {
                return (int) $id;
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
