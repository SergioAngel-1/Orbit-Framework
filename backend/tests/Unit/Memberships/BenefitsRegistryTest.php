<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_WP_CONTENT_DIR . '/plugins/hwe-memberships/includes/Benefits/Benefit.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-benefits-fakes.php';

/**
 * Tipo de prueba con todos los campos que declara el contrato: número, porcentaje, booleano,
 * selección, nivel, categorías, texto y texto traducible.
 */
final class BenefitsTestProbe extends \HWE_Memberships_Benefit
{
    public function key(): string
    {
        return 'probe';
    }

    public function fields(): array
    {
        return [
            'count'      => ['type' => self::FIELD_NUMBER, 'min' => 1, 'max' => 10, 'default' => 3],
            'percent'    => ['type' => self::FIELD_PERCENTAGE, 'default' => 0],
            'flag'       => ['type' => self::FIELD_BOOLEAN, 'default' => false],
            'mode'       => ['type' => self::FIELD_SELECT, 'options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a'],
            'level'      => ['type' => self::FIELD_LEVEL, 'default' => 0],
            'categories' => ['type' => self::FIELD_CATEGORIES, 'default' => []],
            'label'      => ['type' => self::FIELD_TEXT, 'default' => '', 'max' => 5],
            'title'      => ['type' => self::FIELD_TRANSLATABLE, 'default' => [], 'max' => 10],
        ];
    }

    public function publicData(int $levelId): array
    {
        return ['key' => $this->key()];
    }
}

/** Tipo de prueba con una dependencia configurable. */
final class BenefitsTestDependencyProbe extends \HWE_Memberships_Benefit
{
    public static ?string $dependency = null;

    public function key(): string
    {
        return 'dep_probe';
    }

    public function dependsOn(): ?string
    {
        return self::$dependency;
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => self::FIELD_TRANSLATABLE, 'default' => [], 'max' => 80],
        ];
    }

    public function publicData(int $levelId): array
    {
        return ['key' => $this->key(), 'title' => $this->configFor($levelId)['title'] ?? []];
    }
}

/** Declaración sin clave: debe descartarse. */
final class BenefitsTestNoKeyProbe extends \HWE_Memberships_Benefit
{
    public function key(): string
    {
        return '';
    }

    public function publicData(int $levelId): array
    {
        return [];
    }
}

/** Declaración que intenta pisar la clave del núcleo: debe descartarse. */
final class BenefitsTestCoreClashProbe extends \HWE_Memberships_Benefit
{
    public function key(): string
    {
        return 'info_perk';
    }

    public function publicData(int $levelId): array
    {
        return [];
    }
}

/** Declaración que intenta ocupar una clave reservada para una tarea futura. */
final class BenefitsTestReservedProbe extends \HWE_Memberships_Benefit
{
    public function key(): string
    {
        return 'member_discount';
    }

    public function publicData(int $levelId): array
    {
        return [];
    }
}

/** Tipo de prueba que cuenta cuántas veces se registran sus hooks. */
final class BenefitsTestHooksProbe extends \HWE_Memberships_Benefit
{
    public static int $registered = 0;
    public static ?string $dependency = null;

    public function key(): string
    {
        return 'hooks_probe';
    }

    public function dependsOn(): ?string
    {
        return self::$dependency;
    }

    public function publicData(int $levelId): array
    {
        return ['key' => $this->key()];
    }

    protected function registerHooks(): void
    {
        ++self::$registered;
    }
}

/**
 * Plugin `hwe-memberships` (módulo `memberships`), registro de tipos de beneficio y ventaja
 * informativa (spec §7.1 y §7.7).
 *
 * Se prueba el CONTRATO, no la UI: el registro carga los tipos del núcleo y acepta tipos de
 * instancia por el filtro; una declaración mal formada o que pisa una clave del núcleo se
 * descarta y se registra; el saneado por nivel usa los campos declarados y recorta o rechaza
 * lo que queda fuera de rango; un tipo con la dependencia apagada no existe para el cliente
 * pero conserva su configuración; un socio congelado no tiene ningún beneficio activo; y la
 * ventaja informativa devuelve sus textos por idioma, nunca una frase compuesta.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BenefitsRegistryTest extends TestCase
{
    private const USER  = 7;
    private const LEVEL = 101;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<string,mixed> Entradas que devuelve el filtro de tipos. */
    private array $filtered = [];
    private bool $filterReturnsScalar = false;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config             = [
            'brand' => ['locale' => 'es'],
            'i18n'  => ['locales' => 'en'],
        ];
        $this->activePlugins      = ['hwe-memberships/hwe-memberships.php'];
        $this->filtered           = [];
        $this->filterReturnsScalar = false;
        $this->logged             = [];
        \HWE_Memberships_Levels::$configs = [];
        \HWE_Memberships_Members::$states = [];
        BenefitsTestDependencyProbe::$dependency = null;
        BenefitsTestHooksProbe::$dependency      = null;
        BenefitsTestHooksProbe::$registered      = 0;

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_memberships_benefit_types' !== $hook) {
                return $value;
            }
            if ($this->filterReturnsScalar) {
                return 'no-es-un-mapa';
            }
            return array_merge((array) $value, $this->filtered);
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            '__'                  => static fn ($value) => (string) $value,
        ]);

        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/InfoPerk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
    }

    // ── Registro ────────────────────────────────────────────────────────────

    public function testTheRegistryLoadsTheCoreTypeAndResolvesItByKey(): void
    {
        $all = \HWE_Memberships_Benefits::all();

        $this->assertArrayHasKey('info_perk', $all);
        $this->assertInstanceOf(\HWE_Memberships_Benefit_Info_Perk::class, $all['info_perk']);
        $this->assertInstanceOf(
            \HWE_Memberships_Benefit_Info_Perk::class,
            \HWE_Memberships_Benefits::get('info_perk')
        );
        $this->assertNull(\HWE_Memberships_Benefits::get('nope'));
        $this->assertNull(\HWE_Memberships_Benefits::get(''), 'Una clave vacía no resuelve nada.');
        $this->assertNull(\HWE_Memberships_Benefits::get('clave con espacios'));
    }

    public function testTheFilterAddsAnInstanceTypeAndDiscardsMalformedDeclarations(): void
    {
        $this->filtered = [
            'probe'           => new BenefitsTestProbe(),
            'std'             => new \stdClass(),
            'no_key'          => new BenefitsTestNoKeyProbe(),
            'info_perk'       => new BenefitsTestCoreClashProbe(),
            'member_discount' => new BenefitsTestReservedProbe(),
        ];

        $all = \HWE_Memberships_Benefits::all();

        $this->assertArrayHasKey('probe', $all);
        $this->assertInstanceOf(BenefitsTestProbe::class, $all['probe']);
        $this->assertArrayNotHasKey('std', $all, 'Una entrada sin clase base se descarta.');
        $this->assertArrayNotHasKey('no_key', $all, 'Una entrada sin clave se descarta.');
        $this->assertArrayNotHasKey('member_discount', $all, 'Una clave reservada para el núcleo no se puede ocupar.');
        $this->assertInstanceOf(
            \HWE_Memberships_Benefit_Info_Perk::class,
            $all['info_perk'],
            'Una clave del núcleo no se puede pisar.'
        );
        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringContainsString('[hwe] memberships:', $line);
        }
        $this->assertStringContainsString('núcleo', implode(' ', $this->logged));
        $this->assertStringContainsString('reservada', implode(' ', $this->logged));
    }

    public function testAFilterThatDoesNotReturnAMapKeepsTheCoreAndIsLogged(): void
    {
        $this->filterReturnsScalar = true;

        $all = \HWE_Memberships_Benefits::all();

        $this->assertSame(['info_perk'], array_keys($all));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('mapa', implode(' ', $this->logged));
    }

    // ── Saneado por nivel ───────────────────────────────────────────────────

    public function testSanitizeLevelConfigClampsOrRejectsValuesAccordingToTheFields(): void
    {
        $this->filtered = ['probe' => new BenefitsTestProbe()];

        $clean = \HWE_Memberships_Benefits::sanitizeLevelConfig([
            'probe' => [
                'enabled'    => '1',
                'count'      => '99',
                'percent'    => '-5',
                'flag'       => '0',
                'mode'       => 'zzz',
                'level'      => '-2',
                'categories' => [3, '7', 'x', 0, 3],
                'label'      => '  hello world  ',
                'title'      => ['es' => '  Hola  ', 'en' => 'Hello', 'fr' => 'Salut', 'bad_locale' => 'X'],
                'unknown'    => 'se descarta',
            ],
            'info_perk' => [
                'enabled' => false,
                'title'   => ['es' => 'Ventaja'],
                'text'    => ['es' => 'Texto'],
            ],
            'tipo_futuro' => ['enabled' => true, 'foo' => 'bar'],
        ]);

        $probe = $clean['probe'];
        $this->assertTrue($probe['enabled']);
        $this->assertSame(10, $probe['count'], 'Un número por encima del máximo se recorta.');
        $this->assertSame(0, $probe['percent'], 'Un porcentaje negativo se recorta a 0.');
        $this->assertFalse($probe['flag']);
        $this->assertSame('a', $probe['mode'], 'Una opción fuera de la lista cae al default.');
        $this->assertSame(0, $probe['level'], 'Un id de nivel negativo se rechaza.');
        $this->assertSame([3, 7], $probe['categories'], 'Solo ids de categoría positivos y únicos.');
        $this->assertSame('hello', $probe['label'], 'El texto se recorta al máximo declarado.');
        $this->assertSame(
            ['es' => 'Hola', 'en' => 'Hello'],
            $probe['title'],
            'Solo se guardan los idiomas configurados y con valor.'
        );
        $this->assertArrayNotHasKey('unknown', $probe, 'Un campo no declarado no se guarda.');

        $this->assertFalse($clean['info_perk']['enabled']);
        $this->assertSame(['es' => 'Ventaja'], $clean['info_perk']['title']);
        $this->assertSame(['es' => 'Texto'], $clean['info_perk']['text']);

        $this->assertSame(
            ['enabled' => true, 'foo' => 'bar'],
            $clean['tipo_futuro'],
            'Un tipo que este despliegue no conoce se conserva tal cual.'
        );
        $this->assertNotEmpty($this->logged, 'Un valor rechazado deja constancia.');
    }

    public function testSanitizeRejectsANonArrayTypeConfigWithADisabledDefault(): void
    {
        $this->filtered = ['probe' => new BenefitsTestProbe()];

        $clean = \HWE_Memberships_Benefits::sanitizeLevelConfig(['probe' => 'no-es-un-array']);

        $this->assertFalse($clean['probe']['enabled']);
        $this->assertSame(3, $clean['probe']['count']);
        $this->assertSame([], $clean['probe']['categories']);
        $this->assertNotEmpty($this->logged);
    }

    // ── Dependencias y estado ───────────────────────────────────────────────

    public function testADisabledDependencyHidesTheBenefitButKeepsItsConfiguration(): void
    {
        BenefitsTestDependencyProbe::$dependency = 'loyalty:points';
        $this->filtered = ['dep_probe' => new BenefitsTestDependencyProbe()];
        \HWE_Memberships_Levels::$configs[self::LEVEL] = [
            'dep_probe' => [
                'enabled' => true,
                'title'   => ['es' => 'Atención prioritaria'],
            ],
        ];
        \HWE_Memberships_Members::$states[self::USER] = [
            'level_id'  => self::LEVEL,
            'rank'      => 5,
            'status'    => \HWE_Memberships_Members::STATUS_ACTIVE,
            'ends_at'   => null,
            'queue'     => [],
            'grant_key' => 'grant:1',
        ];
        $GLOBALS['hwe_test_loyalty_points'] = false;
        Functions\when('hwe_loyalty_available')->alias(
            static fn (string $feature): bool => 'points' === $feature
                && (bool) ($GLOBALS['hwe_test_loyalty_points'] ?? false)
        );

        $probe = \HWE_Memberships_Benefits::get('dep_probe');
        $this->assertNotNull($probe);
        $this->assertFalse($probe->available(), 'La dependencia apagada no está disponible.');
        $this->assertFalse($probe->isActiveFor(self::USER));
        $this->assertArrayNotHasKey('dep_probe', \HWE_Memberships_Benefits::activeFor(self::USER));

        $clean = \HWE_Memberships_Benefits::sanitizeLevelConfig([
            'dep_probe' => ['enabled' => true, 'title' => ['es' => 'Atención prioritaria']],
        ]);
        $this->assertSame(
            ['es' => 'Atención prioritaria'],
            $clean['dep_probe']['title'],
            'La configuración del tipo dependiente se conserva aunque el módulo esté apagado.'
        );

        $GLOBALS['hwe_test_loyalty_points'] = true;
        $this->assertTrue($probe->available());
        $this->assertTrue($probe->isActiveFor(self::USER));
        $active = \HWE_Memberships_Benefits::activeFor(self::USER);
        $this->assertArrayHasKey('dep_probe', $active);
        $this->assertSame(self::LEVEL, $active['dep_probe']['level_id']);
        $this->assertSame('dep_probe', $active['dep_probe']['key']);
        $this->assertSame(['key' => 'dep_probe', 'title' => ['es' => 'Atención prioritaria']], $active['dep_probe']['public']);
    }

    public function testAFrozenMemberHasNoActiveBenefits(): void
    {
        \HWE_Memberships_Levels::$configs[self::LEVEL] = [
            'info_perk' => [
                'enabled' => true,
                'title'   => ['es' => 'Atención prioritaria'],
                'text'    => ['es' => 'Escríbenos'],
            ],
        ];
        \HWE_Memberships_Members::$states[self::USER] = [
            'level_id'  => 0,
            'rank'      => 0,
            'status'    => \HWE_Memberships_Members::STATUS_FROZEN,
            'ends_at'   => null,
            'queue'     => [],
            'grant_key' => '',
        ];

        $perk = \HWE_Memberships_Benefits::get('info_perk');
        $this->assertNotNull($perk);
        $this->assertFalse($perk->isActiveFor(self::USER));
        $this->assertSame([], \HWE_Memberships_Benefits::activeFor(self::USER));
        $this->assertTrue($perk->stateFor(self::USER)['frozen']);
        $this->assertFalse($perk->stateFor(self::USER)['active']);
    }

    // ── Ventaja informativa ─────────────────────────────────────────────────

    public function testInfoPerkReturnsItsTextsByLocaleWithoutComposingPhrases(): void
    {
        \HWE_Memberships_Levels::$configs[self::LEVEL] = [
            'info_perk' => [
                'enabled' => true,
                'title'   => ['es' => 'Atención prioritaria', 'en' => 'Priority support'],
                'text'    => ['es' => 'Escríbenos por el canal de socios', 'en' => 'Write to us in the member channel'],
            ],
        ];

        $perk = \HWE_Memberships_Benefits::get('info_perk');
        $this->assertNotNull($perk);

        $data = $perk->publicData(self::LEVEL);
        $this->assertSame('info_perk', $data['key']);
        $this->assertSame(
            ['es' => 'Atención prioritaria', 'en' => 'Priority support'],
            $data['title']
        );
        $this->assertSame(
            ['es' => 'Escríbenos por el canal de socios', 'en' => 'Write to us in the member channel'],
            $data['text']
        );
        $this->assertArrayNotHasKey('title_es', $data, 'Los textos viajan por idioma, no como campos aplanados.');
        $this->assertIsArray($data['title']);
        $this->assertIsArray($data['text']);
    }

    // ── Registro de hooks ───────────────────────────────────────────────────

    public function testRegisterOnlyRunsHooksWhenTheModuleIsActiveAndTheDependencyIsAvailable(): void
    {
        BenefitsTestHooksProbe::$dependency = 'loyalty:points';
        $this->filtered = ['hooks_probe' => new BenefitsTestHooksProbe()];
        $GLOBALS['hwe_test_loyalty_points'] = false;
        Functions\when('hwe_loyalty_available')->alias(
            static fn (string $feature): bool => 'points' === $feature
                && (bool) ($GLOBALS['hwe_test_loyalty_points'] ?? false)
        );

        \HWE_Memberships_Benefits::register();
        $this->assertSame(0, BenefitsTestHooksProbe::$registered, 'Con la dependencia apagada no se engancha nada.');

        $GLOBALS['hwe_test_loyalty_points'] = true;
        \HWE_Memberships_Benefits::register();
        $this->assertSame(1, BenefitsTestHooksProbe::$registered);

        $this->activePlugins = [];
        \HWE_Memberships_Benefits::register();
        $this->assertSame(1, BenefitsTestHooksProbe::$registered, 'Con el módulo apagado tampoco.');
    }
}
