<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de ajustes: el grupo reservado `loyalty` del
 * Control Center y la lectura saneada que consumen el resto de las tareas del módulo.
 *
 * Se prueba lo que decide el plugin y no la UI: qué campos pueblan el grupo y con qué defaults,
 * qué no sale por la API pública y cómo se recorta un valor absurdo antes de que llegue a la
 * lógica del libro (foco de revisión 5). «Puntos activos» exige además el módulo activo: el
 * plugin cargado es la señal y, si el Control Center está disponible, se confirma con
 * `Modules::isActive('loyalty')`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SettingsTest extends TestCase
{
    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config        = [];
        $this->activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
        $this->users         = [
            7 => (object) ['ID' => 7, 'roles' => ['customer']],
            8 => (object) ['ID' => 8, 'roles' => ['administrator']],
            9 => (object) ['ID' => 9, 'roles' => ['subscriber']],
        ];
        $this->logged        = [];

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ($name === 'hwe_config') {
                return $this->config;
            }
            if ($name === 'active_plugins') {
                return $this->activePlugins;
            }
            return $default;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
            'get_userdata'        => fn (int $id) => $this->users[$id] ?? false,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    private function load(): void
    {
        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    /**
     * Los campos de spec §9.1, en el orden en que se declaran (el panel los pinta así).
     *
     * @return list<string>
     */
    private function specFields(): array
    {
        return [
            'points_enabled',
            'referrals_enabled',
            'currency_name',
            'currency_name_plural',
            'currency_icon',
            'point_value',
            'min_redeem',
            'max_redeem_per_order',
            'full_payment_enabled',
            'min_cash_due',
            'expiry_days',
            'cashback_enabled',
            'cashback_percent',
            'events',
            'referral_signup_points_l1',
            'referral_signup_points_l2',
            'commission_first_percent',
            'commission_next_percent',
            'level2_enabled',
            'commission_level2_percent',
            'commission_duration_days',
            'transfers_enabled',
            'transfer_min',
            'transfer_fee_percent',
            'transfer_daily_cap',
            'allowed_roles',
        ];
    }

    // ── Esquema del Control Center ──────────────────────────────────────────

    public function testTheFilterFillsTheReservedLoyaltyGroupWithEverySpecField(): void
    {
        $this->load();

        Filters\expectApplied('hwe_control_center_schema')
            ->andReturnUsing([\HWE_Loyalty_Settings::class, 'schema']);

        $children = Schema::get()['loyalty']['children'];

        $this->assertSame($this->specFields(), array_keys($children));

        foreach ($this->specFields() as $key) {
            $this->assertArrayHasKey('label', $children[$key], $key . ' necesita etiqueta para el panel.');
        }

        $this->assertSame('image', $children['currency_icon']['type']);
        $this->assertSame('', $children['currency_icon']['default']);
        $this->assertSame(
            ['registration', 'review', 'birthday'],
            array_keys($children['events']['children']),
            'events es un grupo con los tres eventos de spec §9.1.'
        );
    }

    public function testTheSchemaDefaultsAreTheOnesOfTheContract(): void
    {
        $this->load();

        Filters\expectApplied('hwe_control_center_schema')
            ->andReturnUsing([\HWE_Loyalty_Settings::class, 'schema']);

        $children = Schema::get()['loyalty']['children'];

        $this->assertSame(false, $children['points_enabled']['default']);
        $this->assertSame(false, $children['referrals_enabled']['default']);
        $this->assertSame('punto', $children['currency_name']['default']);
        $this->assertSame('puntos', $children['currency_name_plural']['default']);
        $this->assertSame('0.01', $children['point_value']['default']);
        $this->assertSame('100', $children['min_redeem']['default']);
        $this->assertSame('0', $children['max_redeem_per_order']['default']);
        $this->assertSame(false, $children['full_payment_enabled']['default']);
        $this->assertSame('0.01', $children['min_cash_due']['default']);
        $this->assertSame('0', $children['expiry_days']['default']);
        $this->assertSame(false, $children['cashback_enabled']['default']);
        $this->assertSame('0', $children['cashback_percent']['default']);
        $this->assertSame('0', $children['events']['children']['registration']['default']);
        $this->assertSame('0', $children['events']['children']['review']['default']);
        $this->assertSame('0', $children['events']['children']['birthday']['default']);
        $this->assertSame('0', $children['referral_signup_points_l1']['default']);
        $this->assertSame('0', $children['referral_signup_points_l2']['default']);
        $this->assertSame('0', $children['commission_first_percent']['default']);
        $this->assertSame('0', $children['commission_next_percent']['default']);
        $this->assertSame(false, $children['level2_enabled']['default']);
        $this->assertSame('0', $children['commission_level2_percent']['default']);
        $this->assertSame('0', $children['commission_duration_days']['default']);
        $this->assertSame(false, $children['transfers_enabled']['default']);
        $this->assertSame('100', $children['transfer_min']['default']);
        $this->assertSame('0', $children['transfer_fee_percent']['default']);
        $this->assertSame('0', $children['transfer_daily_cap']['default']);
        $this->assertSame('customer, subscriber', $children['allowed_roles']['default']);
    }

    public function testEveryFieldIsPublicExceptAllowedRoles(): void
    {
        $this->load();

        Filters\expectApplied('hwe_control_center_schema')
            ->andReturnUsing([\HWE_Loyalty_Settings::class, 'schema']);

        $children = Schema::get()['loyalty']['children'];

        foreach ($this->specFields() as $key) {
            if ($key === 'allowed_roles') {
                continue;
            }
            $nodes = $key === 'events' ? $children[$key]['children'] : [$key => $children[$key]];
            foreach ($nodes as $name => $node) {
                $this->assertTrue($node['public'] ?? false, $name . ' debe salir por la config pública.');
            }
        }

        $this->assertEmpty(
            $children['allowed_roles']['public'] ?? false,
            'allowed_roles es un ajuste operativo: no se publica.'
        );
    }

    public function testOptionalFeaturesAreBornDisabled(): void
    {
        $this->load();

        foreach (['cashback_enabled', 'full_payment_enabled', 'transfers_enabled', 'level2_enabled'] as $key) {
            $settings = \HWE_Loyalty_Settings::get();
            $this->assertFalse($settings[$key], $key . ' nace apagado.');
        }
    }

    public function testRegisterAddsTheSchemaFilter(): void
    {
        $this->load();

        Filters\expectAdded('hwe_control_center_schema')
            ->once()
            ->with([\HWE_Loyalty_Settings::class, 'schema']);

        \HWE_Loyalty_Settings::register();
    }

    public function testBootStopsWhenWooCommerceIsMissing(): void
    {
        $this->load();

        \HWE_Loyalty::boot();

        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('WooCommerce', $this->logged[0]);
    }

    // ── Lectura saneada ─────────────────────────────────────────────────────

    public function testTheReadingSanitizesAbsurdValues(): void
    {
        $this->load();

        $this->config['loyalty'] = [
            'points_enabled'            => '1',
            'point_value'               => '-1',
            'min_cash_due'              => '-4.5',
            'expiry_days'               => '-30',
            'min_redeem'                => '-5',
            'max_redeem_per_order'      => '12.9',
            'cashback_percent'          => '150',
            'commission_first_percent'  => '130',
            'commission_next_percent'   => '-5',
            'commission_level2_percent' => '101',
            'transfer_fee_percent'      => '250',
            'transfer_min'              => '-2',
            'transfer_daily_cap'        => '-1',
            'commission_duration_days'  => '-9',
            'referral_signup_points_l1' => '-7',
            'events'                    => [
                'registration' => '-1',
                'review'       => '3',
                'birthday'     => '1.9',
            ],
        ];

        $settings = \HWE_Loyalty_Settings::get();

        $this->assertSame(0.0, $settings['point_value']);
        $this->assertSame(0.01, $settings['min_cash_due'], 'Un mínimo en dinero ≤ 0 cae a un mínimo positivo.');
        $this->assertSame(0, $settings['expiry_days']);
        $this->assertSame(0, $settings['min_redeem']);
        $this->assertSame(12, $settings['max_redeem_per_order']);
        $this->assertSame(100, $settings['cashback_percent']);
        $this->assertSame(100, $settings['commission_first_percent']);
        $this->assertSame(0, $settings['commission_next_percent']);
        $this->assertSame(100, $settings['commission_level2_percent']);
        $this->assertSame(100, $settings['transfer_fee_percent']);
        $this->assertSame(0, $settings['transfer_min']);
        $this->assertSame(0, $settings['transfer_daily_cap']);
        $this->assertSame(0, $settings['commission_duration_days']);
        $this->assertSame(0, $settings['referral_signup_points_l1']);
        $this->assertSame(0, $settings['events']['registration']);
        $this->assertSame(3, $settings['events']['review']);
        $this->assertSame(1, $settings['events']['birthday']);
    }

    public function testBooleanTextIsNormalized(): void
    {
        $this->load();

        $this->config['loyalty'] = [
            'points_enabled'      => 'true',
            'referrals_enabled'   => '0',
            'cashback_enabled'    => 'on',
            'full_payment_enabled' => 'false',
            'transfers_enabled'   => 1,
            'level2_enabled'      => 0,
        ];

        $settings = \HWE_Loyalty_Settings::get();

        $this->assertTrue($settings['points_enabled']);
        $this->assertFalse($settings['referrals_enabled']);
        $this->assertTrue($settings['cashback_enabled']);
        $this->assertFalse($settings['full_payment_enabled']);
        $this->assertTrue($settings['transfers_enabled']);
        $this->assertFalse($settings['level2_enabled']);
    }

    public function testEmptyCurrencyNamesFallBackToTheGenericUnitName(): void
    {
        $this->load();

        $this->config['loyalty'] = [
            'currency_name'        => '   ',
            'currency_name_plural' => '',
        ];

        $settings = \HWE_Loyalty_Settings::get();

        $this->assertSame('punto', $settings['currency_name']);
        $this->assertSame('puntos', $settings['currency_name_plural']);
    }

    public function testGetReturnsTheWholeSanitizedShape(): void
    {
        $this->load();

        $settings = \HWE_Loyalty_Settings::get();

        $this->assertSame(
            [
                'points_enabled',
                'referrals_enabled',
                'currency_name',
                'currency_name_plural',
                'currency_icon',
                'point_value',
                'min_redeem',
                'max_redeem_per_order',
                'full_payment_enabled',
                'min_cash_due',
                'expiry_days',
                'cashback_enabled',
                'cashback_percent',
                'events',
                'referral_signup_points_l1',
                'referral_signup_points_l2',
                'commission_first_percent',
                'commission_next_percent',
                'level2_enabled',
                'commission_level2_percent',
                'commission_duration_days',
                'transfers_enabled',
                'transfer_min',
                'transfer_fee_percent',
                'transfer_daily_cap',
                'allowed_roles',
            ],
            array_keys($settings)
        );
        $this->assertSame(['customer', 'subscriber'], $settings['allowed_roles']);
    }

    // ── Interruptores de la funcionalidad ───────────────────────────────────

    public function testPointsRequireTheModuleToBeActive(): void
    {
        $this->load();

        $this->config['loyalty'] = ['points_enabled' => true];
        $this->assertTrue(\HWE_Loyalty_Settings::pointsActive());

        $this->activePlugins = [];
        $this->assertFalse(
            \HWE_Loyalty_Settings::pointsActive(),
            'Sin el módulo activo, los puntos fallan cerrados aunque el ajuste esté encendido.'
        );
    }

    public function testReferralsAndTransfersRequirePoints(): void
    {
        $this->load();

        $this->config['loyalty'] = [
            'points_enabled'    => false,
            'referrals_enabled' => true,
            'transfers_enabled' => true,
        ];

        $this->assertFalse(\HWE_Loyalty_Settings::referralsActive());
        $this->assertFalse(\HWE_Loyalty_Settings::transfersActive());

        $this->config['loyalty']['points_enabled'] = true;
        $this->assertTrue(\HWE_Loyalty_Settings::referralsActive());
        $this->assertTrue(\HWE_Loyalty_Settings::transfersActive());
    }

    public function testRedeemRequiresPointsAndAPositivePointValue(): void
    {
        $this->load();

        $this->config['loyalty'] = [
            'points_enabled' => true,
            'point_value'    => '0',
        ];
        $this->assertFalse(\HWE_Loyalty_Settings::redeemActive());

        $this->config['loyalty']['point_value'] = '-0.5';
        $this->assertFalse(\HWE_Loyalty_Settings::redeemActive());

        $this->config['loyalty']['point_value'] = '0.01';
        $this->assertTrue(\HWE_Loyalty_Settings::redeemActive());

        $this->config['loyalty']['points_enabled'] = false;
        $this->assertFalse(\HWE_Loyalty_Settings::redeemActive());
    }

    // ── Participación ──────────────────────────────────────────────────────

    public function testAllowedRolesDefaultsToCustomersAndSubscribers(): void
    {
        $this->load();

        $this->assertSame(['customer', 'subscriber'], \HWE_Loyalty_Settings::allowedRoles());
    }

    public function testAllowedRolesIsFilterable(): void
    {
        $this->load();

        Filters\expectApplied('hwe_loyalty_participant_roles')
            ->once()
            ->andReturn(['vip', 'customer']);

        $this->assertSame(['vip', 'customer'], \HWE_Loyalty_Settings::allowedRoles());
    }

    public function testANonArrayFromTheRolesFilterIsIgnoredAndLogged(): void
    {
        $this->load();

        Filters\expectApplied('hwe_loyalty_participant_roles')
            ->once()
            ->andReturn('vip');

        $this->assertSame(['customer', 'subscriber'], \HWE_Loyalty_Settings::allowedRoles());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('hwe_loyalty_participant_roles', $this->logged[0]);
    }

    public function testCanParticipateChecksAnExistingUserWithAnAllowedRole(): void
    {
        $this->load();

        $this->config['loyalty'] = ['points_enabled' => true];

        $this->assertTrue(\HWE_Loyalty_Settings::canParticipate(7), 'customer participa.');
        $this->assertTrue(\HWE_Loyalty_Settings::canParticipate(9), 'subscriber participa.');
        $this->assertFalse(\HWE_Loyalty_Settings::canParticipate(8), 'administrator no está en los roles por defecto.');
        $this->assertFalse(\HWE_Loyalty_Settings::canParticipate(404), 'Un usuario inexistente no participa.');
        $this->assertFalse(\HWE_Loyalty_Settings::canParticipate(0), 'Sin usuario no hay participación.');

        $this->config['loyalty']['points_enabled'] = false;
        $this->assertFalse(
            \HWE_Loyalty_Settings::canParticipate(7),
            'Con los puntos apagados nadie participa del programa.'
        );
    }
}
