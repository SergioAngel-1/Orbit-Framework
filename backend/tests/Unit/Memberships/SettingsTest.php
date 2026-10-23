<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\PublicConfigWalker;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-memberships` (módulo `memberships`), parte de ajustes: el grupo reservado
 * `memberships` del Control Center y la lectura saneada que consumirá el resto del módulo.
 *
 * Se prueba lo que decide el plugin y no la UI: qué campos pueblan el grupo y con qué defaults,
 * qué no sale por la API pública, cómo se recorta un valor absurdo antes de que llegue a la
 * lógica de membresías, y que sin Control Center o sin WooCommerce nada falla en fatal.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SettingsTest extends TestCase
{
    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config        = [];
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php'];
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
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    private function load(): void
    {
        $this->loadOwnFile('plugins/hwe-memberships/hwe-memberships.php');
    }

    /**
     * Los campos públicos y privados de spec §10.1, en el orden en que se declaran.
     *
     * @return list<string>
     */
    private function specFields(): array
    {
        return [
            'card_enabled',
            'card_verify_path',
            'member_number_prefix',
            'card_token_ttl_hours',
            'expiring_notice_days',
            'referral_gift_requires_email_verification',
        ];
    }

    /** @return list<string> */
    private function publicFields(): array
    {
        return ['card_enabled', 'card_verify_path', 'member_number_prefix'];
    }

    // ── Esquema del Control Center ──────────────────────────────────────────

    public function testTheFilterFillsTheReservedMembershipsGroupWithEverySpecField(): void
    {
        $this->load();

        Filters\expectApplied('hwe_control_center_schema')
            ->andReturnUsing([\HWE_Memberships_Settings::class, 'schema']);

        $children = Schema::get()['memberships']['children'];

        $this->assertSame($this->specFields(), array_keys($children));

        foreach ($this->specFields() as $key) {
            $this->assertArrayHasKey('label', $children[$key], $key . ' necesita etiqueta para el panel.');
        }

        $this->assertSame('boolean', $children['card_enabled']['type']);
        $this->assertSame('text', $children['card_verify_path']['type']);
        $this->assertSame('text', $children['member_number_prefix']['type']);
        $this->assertSame('number', $children['card_token_ttl_hours']['type']);
        $this->assertSame('number', $children['expiring_notice_days']['type']);
        $this->assertSame('boolean', $children['referral_gift_requires_email_verification']['type']);
    }

    public function testTheSchemaDefaultsAreTheOnesOfTheContract(): void
    {
        $this->load();

        Filters\expectApplied('hwe_control_center_schema')
            ->andReturnUsing([\HWE_Memberships_Settings::class, 'schema']);

        $children = Schema::get()['memberships']['children'];

        $this->assertSame(false, $children['card_enabled']['default']);
        $this->assertSame('/verify', $children['card_verify_path']['default']);
        $this->assertSame('', $children['member_number_prefix']['default']);
        $this->assertSame('24', $children['card_token_ttl_hours']['default']);
        $this->assertSame('7', $children['expiring_notice_days']['default']);
        $this->assertSame(true, $children['referral_gift_requires_email_verification']['default']);
        $this->assertSame(1, $children['card_token_ttl_hours']['min']);
        $this->assertSame(720, $children['card_token_ttl_hours']['max']);
        $this->assertSame(0, $children['expiring_notice_days']['min']);
    }

    public function testOnlyTheCardFieldsArePublishedInThePublicConfig(): void
    {
        $this->load();

        Filters\expectApplied('hwe_control_center_schema')
            ->andReturnUsing([\HWE_Memberships_Settings::class, 'schema']);

        $children = Schema::get()['memberships']['children'];
        foreach ($this->publicFields() as $key) {
            $this->assertTrue($children[$key]['public'] ?? false, $key . ' debe salir por la config pública.');
        }
        foreach (array_diff($this->specFields(), $this->publicFields()) as $key) {
            $this->assertEmpty($children[$key]['public'] ?? false, $key . ' es operativo: no se publica.');
        }

        $public = (new PublicConfigWalker())->walk(Schema::get(), \HWE_Memberships_Settings::get());

        $this->assertSame($this->publicFields(), array_keys($public['memberships']));
    }

    public function testRegisterAddsTheSchemaFilter(): void
    {
        $this->load();

        Filters\expectAdded('hwe_control_center_schema')
            ->once()
            ->with([\HWE_Memberships_Settings::class, 'schema']);

        \HWE_Memberships_Settings::register();
    }

    // ── Lectura saneada ─────────────────────────────────────────────────────

    public function testGetReturnsTheWholeSanitizedShapeWithTheContractDefaults(): void
    {
        $this->load();

        $this->assertSame(
            [
                'card_enabled'                          => false,
                'card_verify_path'                      => '/verify',
                'member_number_prefix'                  => '',
                'card_token_ttl_hours'                  => 24,
                'expiring_notice_days'                  => 7,
                'referral_gift_requires_email_verification' => true,
            ],
            \HWE_Memberships_Settings::get()
        );
    }

    public function testTheReadingSanitizesAbsurdValues(): void
    {
        $this->load();

        $this->config['memberships'] = [
            'card_enabled'                          => '1',
            'card_verify_path'                      => '  verify  ',
            'member_number_prefix'                  => '  SOC-  ',
            'card_token_ttl_hours'                  => '9999',
            'expiring_notice_days'                  => '-3',
            'referral_gift_requires_email_verification' => '0',
        ];

        $settings = \HWE_Memberships_Settings::get();

        $this->assertTrue($settings['card_enabled']);
        $this->assertSame('/verify', $settings['card_verify_path'], 'Una ruta sin `/` inicial se normaliza.');
        $this->assertSame('SOC-', $settings['member_number_prefix']);
        $this->assertSame(720, $settings['card_token_ttl_hours'], 'El TTL se recorta a su máximo.');
        $this->assertSame(0, $settings['expiring_notice_days'], 'Los días negativos caen a 0.');
        $this->assertFalse($settings['referral_gift_requires_email_verification']);
    }

    public function testTheTtlNeverGoesBelowOneHour(): void
    {
        $this->load();

        $this->config['memberships'] = ['card_token_ttl_hours' => '0'];

        $this->assertSame(1, \HWE_Memberships_Settings::get()['card_token_ttl_hours']);
    }

    public function testAnEmptyVerifyPathFallsBackToTheDefault(): void
    {
        $this->load();

        $this->config['memberships'] = ['card_verify_path' => '   '];

        $this->assertSame('/verify', \HWE_Memberships_Settings::get()['card_verify_path']);
    }

    public function testWithoutControlCenterTheDefaultsAreReturned(): void
    {
        $this->load();

        // Si el Control Center se leyera, este valor se recortaría a 720 y no veríamos el default.
        $this->config['memberships'] = ['card_token_ttl_hours' => '999'];

        // Simula que el mu-plugin del Control Center no está instalado: sin su autoloader,
        // `class_exists(Storage::class)` es false y la lectura cae a los defaults sin fatal.
        $loaders = spl_autoload_functions() ?: [];
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        try {
            $settings = \HWE_Memberships_Settings::get();
        } finally {
            foreach ($loaders as $loader) {
                spl_autoload_register($loader);
            }
        }

        $this->assertSame(24, $settings['card_token_ttl_hours']);
        $this->assertFalse($settings['card_enabled']);
        $this->assertSame([], $this->logged, 'La ausencia del Control Center no es un error del módulo.');
    }

    public function testGetNeverFatalsWhenTheStorageThrows(): void
    {
        $this->load();

        \Patchwork\redefine(
            \HWE\ControlCenter\Storage::class . '::get',
            static function (): mixed {
                throw new \RuntimeException('Control Center roto');
            }
        );

        $this->assertSame(24, \HWE_Memberships_Settings::get()['card_token_ttl_hours']);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    // ── Interruptores ───────────────────────────────────────────────────────

    public function testModuleActiveRequiresThePluginToBeActive(): void
    {
        $this->load();

        $this->assertTrue(\HWE_Memberships_Settings::moduleActive());

        $this->activePlugins = [];
        $this->assertFalse(
            \HWE_Memberships_Settings::moduleActive(),
            'Incluir el archivo a mano no activa el módulo.'
        );
    }

    public function testCardActiveRequiresTheModuleAndTheSwitch(): void
    {
        $this->load();

        $this->config['memberships'] = ['card_enabled' => true];
        $this->assertTrue(\HWE_Memberships_Settings::cardActive());

        $this->config['memberships']['card_enabled'] = false;
        $this->assertFalse(\HWE_Memberships_Settings::cardActive());

        $this->config['memberships']['card_enabled'] = true;
        $this->activePlugins = [];
        $this->assertFalse(\HWE_Memberships_Settings::cardActive(), 'Sin el módulo activo el carné falla cerrado.');
    }

    // ── Participación ──────────────────────────────────────────────────────

    public function testParticipantRolesDefaultsToCustomersAndSubscribers(): void
    {
        $this->load();

        $this->assertSame(['customer', 'subscriber'], \HWE_Memberships_Settings::participantRoles());
    }

    public function testParticipantRolesIsFilterable(): void
    {
        $this->load();

        Filters\expectApplied('hwe_memberships_participant_roles')
            ->once()
            ->andReturn(['vip', 'customer']);

        $this->assertSame(['vip', 'customer'], \HWE_Memberships_Settings::participantRoles());
    }

    public function testANonArrayFromTheRolesFilterIsIgnoredAndLogged(): void
    {
        $this->load();

        Filters\expectApplied('hwe_memberships_participant_roles')
            ->once()
            ->andReturn('vip');

        $this->assertSame(['customer', 'subscriber'], \HWE_Memberships_Settings::participantRoles());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('hwe_memberships_participant_roles', $this->logged[0]);
    }

    // ── Arranque ───────────────────────────────────────────────────────────

    public function testBootStopsWhenWooCommerceIsMissing(): void
    {
        $this->load();

        Filters\expectAdded('hwe_control_center_schema')->never();

        \HWE_Memberships::boot();

        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('WooCommerce', $this->logged[0]);
    }

    public function testBootRegistersTheSettingsWhenWooCommerceIsPresent(): void
    {
        if (!class_exists('WooCommerce')) {
            class_alias(\stdClass::class, 'WooCommerce');
        }

        $this->load();

        Filters\expectAdded('hwe_control_center_schema')
            ->once()
            ->with([\HWE_Memberships_Settings::class, 'schema']);

        \HWE_Memberships::boot();
    }
}
