<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-memberships`, requisitos de acceso y acceso anticipado (spec §6.1, §6.3 y §7.6).
 *
 * Se prueba la DECISIÓN, no la UI: qué rango efectivo tiene un producto al combinar su requisito
 * con toda la cadena de categorías, cuándo un socio (o un visitante) puede verlo, qué motivo
 * estable se devuelve cuando no puede, qué hace el filtro de requisitos (solo endurecer), cómo
 * falla cerrado un error de evaluación, qué pasa con el módulo apagado y que guardar un requisito
 * invalida la caché de la petición, revalida `products` y programa (o retira) el evento único de
 * la fecha de apertura. El personal de la tienda no tiene ningún atajo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccessTest extends TestCase
{
    private const USER_EARLY = 7;   // nivel con el beneficio early_access
    private const USER_PLAIN = 8;   // nivel alto, sin el beneficio
    private const USER_LOW   = 9;   // nivel por debajo del requisito, con el beneficio
    private const LEVEL_1    = 101;
    private const LEVEL_2    = 102;
    private const LEVEL_3    = 103;
    private const LEVEL_5    = 105;
    private const LEVEL_7    = 107;
    private const LEVEL_8    = 108;

    /** @var list<string> */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,array<string,mixed>> Metas de producto. */
    private array $postMeta = [];
    /** @var array<int,array<string,mixed>> Metas de término. */
    private array $termMeta = [];
    /** @var array<int,list<int>> Ancestros por término. */
    private array $ancestors = [];
    /** @var array<int,list<int>> Categorías por producto. */
    private array $productTerms = [];
    /** @var array<int,int> Evento pendiente por producto (timestamp). */
    private array $events = [];
    /** @var list<array{0:int,1:string,2:list<int>}> */
    private array $scheduleCalls = [];
    /** @var list<list<int>> */
    private array $clearCalls = [];
    /** @var list<array{url:string,args:array<string,mixed>}> */
    private array $remotePosts = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var callable|null */
    private $requirementFilter = null;
    private bool $canManageTerms = true;
    private bool $nonceIsValid = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activePlugins     = ['hwe-memberships/hwe-memberships.php'];
        $this->postMeta          = [];
        $this->termMeta          = [];
        $this->ancestors         = [];
        $this->productTerms      = [];
        $this->events            = [];
        $this->scheduleCalls     = [];
        $this->clearCalls        = [];
        $this->remotePosts       = [];
        $this->logged            = [];
        $this->requirementFilter = null;
        $this->canManageTerms    = true;
        $this->nonceIsValid      = true;
        $_POST                   = [];

        if (!defined('HWE_REVALIDATION_SECRET')) {
            define('HWE_REVALIDATION_SECRET', 'test-secret');
        }
        if (!defined('HEADLESS_FRONTEND_URL')) {
            define('HEADLESS_FRONTEND_URL', 'http://frontend.test');
        }

        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-benefits-fakes.php';
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Access.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Admin/TermFields.php');

        $this->seedLevels();

        \HWE_Memberships_Levels::$levels       = $this->levels();
        \HWE_Memberships_Levels::$configs      = $this->benefitConfigs();
        \HWE_Memberships_Levels::$throwOnAll   = false;
        \HWE_Memberships_Members::$states      = [
            self::USER_EARLY => $this->state(self::LEVEL_3, 3),
            self::USER_PLAIN => $this->state(self::LEVEL_8, 8),
            self::USER_LOW   => $this->state(self::LEVEL_2, 2),
        ];
        \HWE_Memberships_Access::flush();

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return 'active_plugins' === $name ? $this->activePlugins : $default;
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('get_term_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->termMeta[$id][$key] ?? ''
        );
        Functions\when('get_ancestors')->alias(
            fn (int $termId, string $taxonomy = '', string $context = 'taxonomy'): array => $this->ancestors[$termId] ?? []
        );
        Functions\when('wp_get_post_terms')->alias(
            fn (int $productId, string $taxonomy = '', array $args = []): array => $this->productTerms[$productId] ?? []
        );
        Functions\when('update_term_meta')->alias(function (int $termId, string $key, mixed $value): bool {
            $this->termMeta[$termId][$key] = $value;
            return true;
        });
        Functions\when('delete_term_meta')->alias(function (int $termId, string $key): bool {
            unset($this->termMeta[$termId][$key]);
            return true;
        });
        Functions\when('wp_next_scheduled')->alias(
            fn (string $hook, array $args = []) => \HWE_Memberships_Access::HOOK === $hook
                ? ($this->events[(int) ($args[0] ?? 0)] ?? false)
                : false
        );
        Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args = []): bool {
            $this->scheduleCalls[] = [$timestamp, $hook, array_map('intval', $args)];
            if (\HWE_Memberships_Access::HOOK === $hook) {
                $this->events[(int) ($args[0] ?? 0)] = $timestamp;
            }
            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook, array $args = []): bool {
            $ids = array_map('intval', $args);
            $this->clearCalls[] = $ids;
            if (\HWE_Memberships_Access::HOOK === $hook) {
                unset($this->events[(int) ($args[0] ?? 0)]);
            }
            return true;
        });
        Functions\when('wp_remote_post')->alias(function (string $url, array $args = []): array {
            $this->remotePosts[] = ['url' => $url, 'args' => $args];
            return [];
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_memberships_product_requirement' === $hook) {
                return null === $this->requirementFilter ? $value : ($this->requirementFilter)($value, $args[0] ?? 0);
            }
            if ('hwe_revalidation_tags' === $hook) {
                return ['products', 'memberships'];
            }
            return $value;
        });
        Functions\when('current_user_can')->alias(fn (string $capability): bool => 'manage_product_terms' === $capability
            ? $this->canManageTerms
            : false);
        Functions\when('wp_verify_nonce')->alias(fn ($nonce, string $action): bool => $this->nonceIsValid);
        Functions\when('wp_timezone')->alias(fn (): \DateTimeZone => new \DateTimeZone('UTC'));
        Functions\when('get_post_type')->alias(fn (int $id): string => 'product');
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('wp_nonce_field')->alias(function ($action = -1, $name = '_wpnonce', $referer = true, $display = true): string {
            $field = '<input type="hidden" name="' . (string) $name . '" value="nonce">';
            if ($display) {
                echo $field;
            }
            return $field;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'wp_unslash'          => static fn ($value) => $value,
            '__'                  => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => (string) $value,
            'esc_html'            => static fn ($value) => (string) $value,
            'esc_attr'            => static fn ($value) => (string) $value,
            'esc_attr__'          => static fn ($value) => (string) $value,
            'selected'            => static fn ($left, $right, $echo = true) => $left === $right ? ' selected' : '',
            'checked'             => static fn ($left, $right, $echo = true) => $left === $right ? ' checked' : '',
            'is_wp_error'         => static fn ($thing): bool => false,
        ]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['post']);
        \HWE_Memberships_Access::flush();
        parent::tearDown();
    }

    // ── Datos de prueba ─────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function levels(): array
    {
        $levels = [];
        foreach ([self::LEVEL_1 => 1, self::LEVEL_2 => 2, self::LEVEL_3 => 3, self::LEVEL_5 => 5, self::LEVEL_7 => 7, self::LEVEL_8 => 8] as $id => $rank) {
            $levels[$id] = [
                'id'        => $id,
                'name'      => 'Nivel ' . $rank,
                'slug'      => 'nivel-' . $rank,
                'rank'      => $rank,
                'published' => true,
            ];
        }
        return $levels;
    }

    /** @return array<int,array<string,mixed>> */
    private function benefitConfigs(): array
    {
        return [
            self::LEVEL_3 => ['early_access' => ['enabled' => true]],
            self::LEVEL_2 => ['early_access' => ['enabled' => true]],
        ];
    }

    private function seedLevels(): void
    {
        // Cadenas de categorías: 10 ← 20 ← 30. El rango de una hija nunca se reescribe al
        // cambiar el padre: se calcula al leer.
        $this->termMeta = [
            10 => [\HWE_Memberships_Access::MIN_RANK_META => '1'],
            20 => [\HWE_Memberships_Access::MIN_RANK_META => '5'],
            30 => [\HWE_Memberships_Access::MIN_RANK_META => '2'],
            40 => [],
        ];
        $this->ancestors = [
            10 => [],
            20 => [10],
            30 => [20, 10],
            40 => [],
        ];
        $this->productTerms = [
            501 => [30],
            502 => [],
            503 => [40],
            504 => [],
            505 => [],
            506 => [],
        ];
        $this->postMeta = [
            501 => [\HWE_Memberships_Access::MIN_RANK_META => '2'],
            502 => [\HWE_Memberships_Access::MIN_RANK_META => '8'],
            503 => [],
            504 => [\HWE_Memberships_Access::MIN_RANK_META => '3'],
            505 => [],
            506 => [\HWE_Memberships_Access::MIN_RANK_META => '2'],
        ];
    }

    /** @return array<string,mixed> */
    private function state(int $levelId, int $rank): array
    {
        return [
            'level_id'  => $levelId,
            'rank'      => $rank,
            'status'    => \HWE_Memberships_Members::STATUS_ACTIVE,
            'ends_at'   => null,
            'queue'     => [],
            'grant_key' => 'grant:' . $levelId,
        ];
    }

    private function future(int $seconds = 86400): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    private function past(int $seconds = 86400): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /** @return list<string> */
    private function revalidatedTags(): array
    {
        $tags = [];
        foreach ($this->remotePosts as $post) {
            $body = json_decode((string) ($post['args']['body'] ?? ''), true);
            if (is_array($body) && isset($body['tag'])) {
                $tags[] = (string) $body['tag'];
            }
        }
        return $tags;
    }

    // ── Herencia y combinación de requisitos ───────────────────────────────

    public function testCategoryRequirementUsesTheHighestRankOfTheWholeChain(): void
    {
        $requirement = \HWE_Memberships_Access::categoryRequirement(30);

        $this->assertSame(5, $requirement['min_rank'], 'Gana el mayor rango de la cadena.');
        $this->assertSame(self::LEVEL_5, $requirement['level_id']);
        $this->assertSame('nivel-5', $requirement['level_slug']);
        $this->assertSame('Nivel 5', $requirement['level_name']);
        $this->assertNull($requirement['opens_at']);
        $this->assertFalse($requirement['blocked']);

        // Cambiar el padre no reescribe la meta de la hija: se recalcula al leer.
        $this->termMeta[20][\HWE_Memberships_Access::MIN_RANK_META] = '7';
        \HWE_Memberships_Access::onTermChanged(20);

        $this->assertSame('2', $this->termMeta[30][\HWE_Memberships_Access::MIN_RANK_META]);
        $this->assertSame(7, \HWE_Memberships_Access::categoryRequirement(30)['min_rank']);
    }

    public function testProductRequirementTakesTheMaxOfTheProductAndItsCategories(): void
    {
        $this->assertSame(5, \HWE_Memberships_Access::requirementFor(501)['min_rank']);
        $this->assertSame(self::LEVEL_5, \HWE_Memberships_Access::requirementFor(501)['level_id']);

        // El producto manda cuando su rango es mayor.
        $this->assertSame(8, \HWE_Memberships_Access::requirementFor(502)['min_rank']);
        $this->assertSame(self::LEVEL_8, \HWE_Memberships_Access::requirementFor(502)['level_id']);

        // Sin requisito: ni nivel ni fecha.
        $requirement = \HWE_Memberships_Access::requirementFor(503);
        $this->assertSame(0, $requirement['min_rank']);
        $this->assertSame(0, $requirement['level_id']);
        $this->assertSame('', $requirement['level_slug']);
        $this->assertNull($requirement['opens_at']);
    }

    public function testRequirementMapsToTheNextPublishedLevelWhenTheExactRankDoesNotExist(): void
    {
        // Un rango 4 no tiene nivel: se usa el menor publicado con rango ≥ 4 (el 5).
        $this->postMeta[506][\HWE_Memberships_Access::MIN_RANK_META] = '4';

        $requirement = \HWE_Memberships_Access::requirementFor(506);

        $this->assertSame(4, $requirement['min_rank']);
        $this->assertSame(self::LEVEL_5, $requirement['level_id']);
        $this->assertSame('nivel-5', $requirement['level_slug']);
    }

    public function testAnOpeningDateOnlyTravelsWhileItIsInTheFuture(): void
    {
        $future = $this->future();
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = $future;

        $requirement = \HWE_Memberships_Access::requirementFor(506);
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s\Z', strtotime($future . ' UTC')),
            $requirement['opens_at']
        );

        \HWE_Memberships_Access::flush();
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = $this->past();

        $this->assertNull(
            \HWE_Memberships_Access::requirementFor(506)['opens_at'],
            'Pasada la fecha, el producto vuelve a su requisito normal.'
        );
    }

    // ── Acceso anticipado ──────────────────────────────────────────────────

    public function testBeforeTheOpeningDateOnlyTheLevelWithTheBenefitAndRankCanView(): void
    {
        $future = $this->future();
        $this->postMeta[504][\HWE_Memberships_Access::OPENS_AT_META] = $future;
        \HWE_Memberships_Access::flush();

        $this->assertTrue(
            \HWE_Memberships_Access::canView(self::USER_EARLY, 504),
            'Nivel con early_access y rango suficiente.'
        );
        $this->assertNull(\HWE_Memberships_Access::lockReason(self::USER_EARLY, 504));

        $this->assertFalse(
            \HWE_Memberships_Access::canView(self::USER_PLAIN, 504),
            'Rango alto pero sin el beneficio: no entra antes de la fecha.'
        );
        $reason = \HWE_Memberships_Access::lockReason(self::USER_PLAIN, 504);
        $this->assertNotNull($reason);
        $this->assertSame('membership_early_access', $reason['reason']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', strtotime($future . ' UTC')), $reason['opens_at']);
        $this->assertSame(3, $reason['min_rank']);
        $this->assertSame('nivel-3', $reason['level_slug']);

        $this->assertFalse(
            \HWE_Memberships_Access::canView(self::USER_LOW, 504),
            'Con el beneficio pero sin el rango requerido tampoco.'
        );
        $this->assertSame('membership_early_access', \HWE_Memberships_Access::lockReason(self::USER_LOW, 504)['reason']);

        $this->assertFalse(\HWE_Memberships_Access::canView(0, 504), 'Un visitante no entra antes de la fecha.');
        $this->assertSame('membership_early_access', \HWE_Memberships_Access::lockReason(0, 504)['reason']);
    }

    public function testAfterTheOpeningDateTheNormalRequirementApplies(): void
    {
        $this->postMeta[504][\HWE_Memberships_Access::OPENS_AT_META] = $this->past();
        \HWE_Memberships_Access::flush();

        $this->assertTrue(
            \HWE_Memberships_Access::canView(self::USER_PLAIN, 504),
            'Sin el beneficio, pero con el rango requerido: ya entra.'
        );
        $this->assertNull(\HWE_Memberships_Access::lockReason(self::USER_PLAIN, 504));

        $this->assertFalse(\HWE_Memberships_Access::canView(self::USER_LOW, 504));
        $reason = \HWE_Memberships_Access::lockReason(self::USER_LOW, 504);
        $this->assertSame('membership_required', $reason['reason']);
        $this->assertNull($reason['opens_at']);
        $this->assertSame(3, $reason['min_rank']);

        $this->assertFalse(\HWE_Memberships_Access::canView(0, 504));
        $this->assertSame('membership_required', \HWE_Memberships_Access::lockReason(0, 504)['reason']);
    }

    public function testAPublicProductWithAnOpeningDateAlsoHonoursEarlyAccess(): void
    {
        $this->postMeta[505][\HWE_Memberships_Access::OPENS_AT_META] = $this->future();
        \HWE_Memberships_Access::flush();

        $this->assertFalse(\HWE_Memberships_Access::canView(0, 505));
        $this->assertSame('membership_early_access', \HWE_Memberships_Access::lockReason(0, 505)['reason']);
        $this->assertTrue(\HWE_Memberships_Access::canView(self::USER_EARLY, 505));
        $this->assertFalse(
            \HWE_Memberships_Access::canView(self::USER_PLAIN, 505),
            'Sin el beneficio tampoco entra en un producto sin rango mínimo.'
        );

        \HWE_Memberships_Access::flush();
        $this->postMeta[505][\HWE_Memberships_Access::OPENS_AT_META] = $this->past();
        $this->assertTrue(\HWE_Memberships_Access::canView(0, 505));
    }

    // ── Filtro: solo puede endurecer ───────────────────────────────────────

    public function testTheRequirementFilterCanHardenButNeverLoosen(): void
    {
        $this->requirementFilter = static fn (array $requirement, int $productId): array => [
            'min_rank' => 2,
            'opens_at' => null,
        ];
        $this->assertSame(
            5,
            \HWE_Memberships_Access::requirementFor(501)['min_rank'],
            'Un rango menor que el calculado se ignora.'
        );

        \HWE_Memberships_Access::flush();
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = $this->future();
        $this->requirementFilter = static fn (array $requirement, int $productId): array => [
            'min_rank' => $requirement['min_rank'],
            'opens_at' => null,
        ];
        $this->assertNotNull(
            \HWE_Memberships_Access::requirementFor(506)['opens_at'],
            'El filtro no puede retirar una fecha de apertura.'
        );
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('se ignora', implode(' ', $this->logged));

        // Endurecer sí se acepta: más rango y una fecha posterior.
        \HWE_Memberships_Access::flush();
        $this->logged = [];
        $later       = gmdate('Y-m-d\TH:i:s\Z', time() + 2 * 86400);
        $this->requirementFilter = static fn (array $requirement, int $productId): array => [
            'min_rank' => 7,
            'opens_at' => $later,
        ];

        $requirement = \HWE_Memberships_Access::requirementFor(506);
        $this->assertSame(7, $requirement['min_rank']);
        $this->assertSame(self::LEVEL_7, $requirement['level_id']);
        $this->assertSame($later, $requirement['opens_at']);

        // Añadir una fecha donde no la había también es endurecer.
        \HWE_Memberships_Access::flush();
        unset($this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META]);
        $added = gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 86400);
        $this->requirementFilter = static fn (array $requirement, int $productId): array => [
            'opens_at' => $added,
        ];
        $this->assertSame($added, \HWE_Memberships_Access::requirementFor(506)['opens_at']);

        // Un filtro que devuelve basura no cambia nada y se registra.
        \HWE_Memberships_Access::flush();
        $this->logged            = [];
        $this->requirementFilter = static fn (array $requirement, int $productId): string => 'no-es-un-requisito';

        $this->assertSame(2, \HWE_Memberships_Access::requirementFor(506)['min_rank']);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    // ── Falla cerrada, módulo apagado y personal ───────────────────────────

    public function testAnEvaluationErrorBlocksAndIsLogged(): void
    {
        \HWE_Memberships_Levels::$throwOnAll = true;
        \HWE_Memberships_Access::flush();

        $requirement = \HWE_Memberships_Access::requirementFor(501);

        $this->assertTrue($requirement['blocked']);
        $this->assertFalse(\HWE_Memberships_Access::canView(self::USER_PLAIN, 501));
        $this->assertNotNull(\HWE_Memberships_Access::lockReason(self::USER_PLAIN, 501));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
        $this->assertStringContainsString('no se pudo evaluar', $this->logged[0]);
    }

    public function testWithTheModuleOffEverythingIsAccessibleAndKeepsNoRequirement(): void
    {
        $this->postMeta[501][\HWE_Memberships_Access::OPENS_AT_META] = $this->future();
        $this->activePlugins = [];
        \HWE_Memberships_Access::flush();

        $requirement = \HWE_Memberships_Access::requirementFor(501);
        $this->assertSame(0, $requirement['min_rank']);
        $this->assertNull($requirement['opens_at']);
        $this->assertFalse($requirement['blocked']);
        $this->assertSame(0, \HWE_Memberships_Access::categoryRequirement(30)['min_rank']);
        $this->assertTrue(\HWE_Memberships_Access::canView(0, 501));
        $this->assertTrue(\HWE_Memberships_Access::canView(self::USER_LOW, 501));
        $this->assertNull(\HWE_Memberships_Access::lockReason(0, 501));

        $this->assertSame(
            '5',
            $this->termMeta[20][\HWE_Memberships_Access::MIN_RANK_META],
            'Los datos guardados se conservan para cuando vuelva a encenderse.'
        );
    }

    public function testStoreStaffGetsNoBypassInTheStorefront(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('user_can')->justReturn(true);

        $requirement = \HWE_Memberships_Access::requirementFor(501);
        // El usuario de prueba no tiene nivel, aunque WordPress diga que puede gestionar todo.
        \HWE_Memberships_Members::$states[self::USER_LOW] = $this->state(0, 0);
        \HWE_Memberships_Access::flush();

        $this->assertSame(5, $requirement['min_rank']);
        $this->assertFalse(\HWE_Memberships_Access::canView(self::USER_LOW, 501));
        $this->assertSame('membership_required', \HWE_Memberships_Access::lockReason(self::USER_LOW, 501)['reason']);
    }

    // ── Invalidación y revalidación ────────────────────────────────────────

    public function testSavingAProductCategoryOrLevelInvalidatesTheCacheAndRevalidatesProducts(): void
    {
        $this->assertSame(2, \HWE_Memberships_Access::requirementFor(506)['min_rank']);

        $this->postMeta[506][\HWE_Memberships_Access::MIN_RANK_META] = '7';
        $_POST = ['hwe_membership_min_rank' => '7'];
        \HWE_Memberships_Access::onProductSaved(506);

        $this->assertSame(7, \HWE_Memberships_Access::requirementFor(506)['min_rank']);
        $this->assertContains('products', $this->revalidatedTags());

        // Un cambio de meta por REST/importación también invalida y revalida.
        $this->postMeta[506][\HWE_Memberships_Access::MIN_RANK_META] = '9';
        \HWE_Memberships_Access::onProductMetaChanged(1, 506, \HWE_Memberships_Access::MIN_RANK_META, '9');
        $this->assertSame(
            9,
            \HWE_Memberships_Access::requirementFor(506)['min_rank'],
            'El rango 9 no tiene nivel: se resuelve al menor publicado con rango ≥ 9 y, si no hay, bloquea.'
        );

        // Cambiar una categoría también invalida el requisito de sus productos.
        $this->remotePosts = [];
        $this->termMeta[20][\HWE_Memberships_Access::MIN_RANK_META] = '8';
        \HWE_Memberships_Access::onTermChanged(20);

        $this->assertSame(8, \HWE_Memberships_Access::requirementFor(501)['min_rank']);
        $this->assertContains('products', $this->revalidatedTags());

        // Un nivel cambia a qué nivel apunta un rango: se invalidan los dos tags.
        $this->remotePosts = [];
        \HWE_Memberships_Levels::$levels[self::LEVEL_8]['published'] = false;
        \HWE_Memberships_Access::onLevelSaved(self::LEVEL_8);

        $tags = $this->revalidatedTags();
        $this->assertContains('memberships', $tags);
        $this->assertContains('products', $tags);

        $requirement = \HWE_Memberships_Access::requirementFor(506);
        $this->assertSame(9, $requirement['min_rank']);
        $this->assertSame(
            0,
            $requirement['level_id'],
            'Al despublicar el nivel 8, el rango 9 ya no tiene nivel publicado y bloquea a todos.'
        );
        \HWE_Memberships_Members::$states[77] = $this->state(0, 10);
        $this->assertFalse(
            \HWE_Memberships_Access::canView(77, 506),
            'Sin nivel publicado que represente el rango, ni un rango mayor abre el producto.'
        );
    }

    public function testAnOpeningDateSchedulesASingleEventAndIsRescheduledOrRemoved(): void
    {
        $first = $this->future();
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = $first;

        \HWE_Memberships_Access::onProductSaved(506);

        $this->assertCount(1, $this->scheduleCalls);
        $this->assertSame(strtotime($first . ' UTC'), $this->scheduleCalls[0][0]);
        $this->assertSame([506], $this->scheduleCalls[0][2]);

        // Guardar otra vez sin cambiar la fecha no duplica el evento.
        \HWE_Memberships_Access::onProductSaved(506);
        $this->assertCount(1, $this->scheduleCalls);

        // Cambiar la fecha retira el anterior y programa uno nuevo.
        $second = $this->future(2 * 86400);
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = $second;
        \HWE_Memberships_Access::onProductSaved(506);

        $this->assertCount(2, $this->scheduleCalls);
        $this->assertSame([506], $this->clearCalls[0]);
        $this->assertSame(strtotime($second . ' UTC'), $this->events[506]);

        // Borrar la fecha retira el evento y no programa nada.
        unset($this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META]);
        \HWE_Memberships_Access::onProductSaved(506);

        $this->assertArrayNotHasKey(506, $this->events);
        $this->assertCount(2, $this->scheduleCalls);
    }

    public function testTheOpeningEventRevalidatesProductsWhenItFires(): void
    {
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = $this->future();
        \HWE_Memberships_Access::onProductSaved(506);
        $this->remotePosts = [];

        \HWE_Memberships_Access::onEarlyAccessOpen(506);

        $this->assertContains('products', $this->revalidatedTags());
    }

    public function testRegisterDeclaresTheTagsTheEventAndTheInvalidationHooks(): void
    {
        Filters\expectAdded('hwe_revalidation_tags')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'tags']);
        Actions\expectAdded(\HWE_Memberships_Access::HOOK)
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onEarlyAccessOpen'], 10, 1);
        Actions\expectAdded('woocommerce_update_product')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onProductSaved'], 20, 1);
        Actions\expectAdded('woocommerce_new_product')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onProductSaved'], 20, 1);
        Actions\expectAdded('added_post_meta')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onProductMetaChanged'], 20, 4);
        Actions\expectAdded('updated_post_meta')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onProductMetaChanged'], 20, 4);
        Actions\expectAdded('deleted_post_meta')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onProductMetaChanged'], 20, 4);
        Actions\expectAdded('save_post_' . \HWE_Memberships_Levels::POST_TYPE)
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onLevelSaved'], 20, 1);
        Actions\expectAdded('created_product_cat')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onTermChanged'], 20, 1);
        Actions\expectAdded('edited_product_cat')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onTermChanged'], 20, 1);
        Actions\expectAdded('delete_product_cat')
            ->once()
            ->with([\HWE_Memberships_Access::class, 'onTermChanged'], 20, 1);

        \HWE_Memberships_Access::register();

        $this->assertSame(
            ['products', 'otro', 'memberships'],
            \HWE_Memberships_Access::tags(['products', 'otro']),
            'Los tags se declaran sin duplicados.'
        );
    }

    // ── Campos de wp-admin ─────────────────────────────────────────────────

    public function testTheCategoryFieldSavesWithCapabilityAndNonceAndShowsTheInheritedRank(): void
    {
        $_POST = [
            \HWE_Memberships_Admin_TermFields::NONCE_FIELD => 'nonce',
            'hwe_membership_min_rank'                       => '4',
        ];
        \HWE_Memberships_Admin_TermFields::saveTerm(30);

        $this->assertSame(
            4,
            $this->termMeta[30][\HWE_Memberships_Access::MIN_RANK_META]
        );

        // Sin capacidad no se escribe.
        $this->canManageTerms = false;
        $_POST['hwe_membership_min_rank'] = '6';
        \HWE_Memberships_Admin_TermFields::saveTerm(30);
        $this->assertSame(4, $this->termMeta[30][\HWE_Memberships_Access::MIN_RANK_META]);

        // Sin nonce tampoco.
        $this->canManageTerms = true;
        $this->nonceIsValid   = false;
        \HWE_Memberships_Admin_TermFields::saveTerm(30);
        $this->assertSame(4, $this->termMeta[30][\HWE_Memberships_Access::MIN_RANK_META]);

        // La edición de una hija avisa de que hereda un rango mayor.
        $this->termMeta[30][\HWE_Memberships_Access::MIN_RANK_META] = 2;
        $term = new \WP_Term();
        $term->term_id = 30;

        ob_start();
        \HWE_Memberships_Admin_TermFields::renderEditFields($term);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('hwe_membership_min_rank', $html);
        $this->assertStringContainsString('Hereda', $html);
        $this->assertStringContainsString('5', $html);

        // Guardar 0 borra el requisito del término.
        $_POST = [
            \HWE_Memberships_Admin_TermFields::NONCE_FIELD => 'nonce',
            'hwe_membership_min_rank'                       => '0',
        ];
        $this->nonceIsValid = true;
        \HWE_Memberships_Admin_TermFields::saveTerm(30);
        $this->assertArrayNotHasKey(\HWE_Memberships_Access::MIN_RANK_META, $this->termMeta[30]);
    }

    public function testTheProductPanelAndSaveStoreTheRequirement(): void
    {
        $_POST = [
            'hwe_membership_min_rank' => '5',
            'hwe_membership_opens_at' => '2030-01-01T10:00',
        ];
        $product = new TestAccessProduct(506);
        \HWE_Memberships_Products::saveProduct($product);
        $this->postMeta[506] = $product->meta;

        $this->assertSame(5, $this->postMeta[506][\HWE_Memberships_Access::MIN_RANK_META]);
        $this->assertSame('2030-01-01 10:00:00', $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META]);

        // Una fecha inválida no se guarda y no deja un requisito a medias.
        $_POST = [
            'hwe_membership_min_rank' => '5',
            'hwe_membership_opens_at' => 'no-es-una-fecha',
        ];
        $product = new TestAccessProduct(506);
        $product->meta = $this->postMeta[506];
        \HWE_Memberships_Products::saveProduct($product);
        $this->postMeta[506] = $product->meta;

        $this->assertArrayNotHasKey(\HWE_Memberships_Access::OPENS_AT_META, $this->postMeta[506]);
        $this->assertNotEmpty($this->logged);

        // El panel muestra los dos campos con el valor actual.
        $GLOBALS['post'] = (object) ['ID' => 506];
        $this->postMeta[506][\HWE_Memberships_Access::OPENS_AT_META] = '2030-01-01 10:00:00';

        ob_start();
        \HWE_Memberships_Products::renderPanel();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="hwe_membership_min_rank"', $html);
        $this->assertStringContainsString('name="hwe_membership_opens_at"', $html);
        $this->assertStringContainsString('2030-01-01T10:00', $html);
    }
}

/** Producto mínimo para probar el guardado de los campos de requisito. */
final class TestAccessProduct
{
    /** @var array<string,mixed> */
    public array $meta = [];

    public function __construct(private int $id, private string $status = 'publish')
    {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function set_status(string $status): void
    {
        $this->status = $status;
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data(string $key): void
    {
        unset($this->meta[$key]);
    }

    public function set_virtual(bool $virtual): void
    {
    }
}
