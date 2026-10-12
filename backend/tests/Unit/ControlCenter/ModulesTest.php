<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Modules;
use HWE\Tests\TestCase;

/**
 * `Modules`: catálogo de módulos opcionales (id → slug de plugin).
 *
 * La fuente de verdad es si el plugin está activo, no la config almacenada: `wp hwe setup`
 * activa los listados en `modules` y desactiva los demás del catálogo, y la config pública
 * expone el mapa calculado al vuelo. Sin la clave `modules`, el setup no toca ningún plugin.
 */
final class ModulesTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];
    /** @var list<string> */
    private array $activated = [];
    /** @var list<string> */
    private array $deactivated = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return $name === 'active_plugins' ? $GLOBALS['hwe_test_active_plugins'] ?? [] : $default;
        });
        Functions\when('activate_plugin')->alias(function (string $file): mixed {
            $this->activated[] = $file;
            return null;
        });
        Functions\when('deactivate_plugins')->alias(function (string $file): void {
            $this->deactivated[] = $file;
        });
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hwe_test_active_plugins']);
        parent::tearDown();
    }

    /** @param list<string> $files Archivos de plugin activos (`slug/slug.php`). */
    private function withActivePlugins(string ...$files): void
    {
        $GLOBALS['hwe_test_active_plugins'] = $files;
    }

    // ── catalog ─────────────────────────────────────────────────────────────

    public function testTheCatalogHasTheNineIds(): void
    {
        $catalog = Modules::catalog();

        $this->assertSame(
            ['banners', 'shipping', 'product-landing', 'landings', 'consent-log', 'site-reviews', 'coupon-rules', 'customer-identity', 'external-integration'],
            array_keys($catalog)
        );
        $this->assertSame('hwe-banners', $catalog['banners']);
        $this->assertSame('hwe-site-reviews', $catalog['site-reviews']);
    }

    public function testTheFilterCanAddAnInstanceModule(): void
    {
        Filters\expectApplied('hwe_modules_catalog')->once()->andReturnUsing(
            static fn (array $core): array => [...$core, 'mi-modulo' => 'hwe-instance-mi-modulo']
        );

        $this->assertSame('hwe-instance-mi-modulo', Modules::catalog()['mi-modulo']);
    }

    public function testARemovedCoreIdIsRestoredAndLogged(): void
    {
        Filters\expectApplied('hwe_modules_catalog')->once()->andReturnUsing(
            static function (array $core): array {
                unset($core['banners']);
                return $core;
            }
        );

        $this->assertSame('hwe-banners', Modules::catalog()['banners']);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('banners', $this->logged[0]);
    }

    public function testANonArrayFilterResultFallsBackToCore(): void
    {
        Filters\expectApplied('hwe_modules_catalog')->once()->andReturn('no-es-un-array');

        $this->assertArrayHasKey('banners', Modules::catalog());
        $this->assertCount(1, $this->logged);
    }

    // ── isActive / activeMap ──────────────────────────────────────────────────

    public function testIsActiveReflectsTheActivePluginsOption(): void
    {
        $this->withActivePlugins('hwe-banners/hwe-banners.php');

        $this->assertTrue(Modules::isActive('banners'));
        $this->assertFalse(Modules::isActive('shipping'));
    }

    public function testAnUnknownIdIsNeverActive(): void
    {
        $this->withActivePlugins('hwe-banners/hwe-banners.php');

        $this->assertFalse(Modules::isActive('no-existe'));
        $this->assertArrayNotHasKey('no-existe', Modules::activeMap());
    }

    public function testActiveMapCoversTheWholeCatalog(): void
    {
        $this->withActivePlugins('hwe-banners/hwe-banners.php');

        $map = Modules::activeMap();

        $this->assertCount(9, $map);
        $this->assertTrue($map['banners']);
        $this->assertFalse($map['shipping']);
    }

    // ── applySetup ──────────────────────────────────────────────────────────

    public function testApplySetupActivatesTheWantedAndDeactivatesTheRest(): void
    {
        $this->withActivePlugins('hwe-banners/hwe-banners.php', 'hwe-shipping/hwe-shipping.php');

        // `shipping` no está instalado como plugin real, pero `activate_plugin` está simulado:
        // aquí se comprueba el mecanismo, no WordPress. Solo `banners` se quiere.
        $result = Modules::applySetup(['banners']);

        $this->assertSame([], $result['activated']);
        $this->assertSame(['hwe-shipping/hwe-shipping.php'], $this->deactivated);
        $this->assertSame(['shipping'], $result['deactivated']);
    }

    public function testApplySetupActivatesAMissingModule(): void
    {
        $this->withActivePlugins();

        $result = Modules::applySetup(['banners']);

        $this->assertSame(['hwe-banners/hwe-banners.php'], $this->activated);
        $this->assertSame(['banners'], $result['activated']);
        $this->assertSame([], $result['deactivated']);
    }

    public function testUnknownIdsAreIgnoredAndLogged(): void
    {
        $this->withActivePlugins();

        $result = Modules::applySetup(['banners', 'modulo-fantasma-23']);

        $this->assertSame(['banners'], $result['activated']);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('modulo-fantasma-23', $this->logged[0]);
    }

    public function testAFailedActivationDoesNotAbortTheSetup(): void
    {
        $this->withActivePlugins();
        $error = new class {
            public function get_error_message(): string {
                return 'el plugin no está instalado';
            }
        };
        Functions\when('activate_plugin')->alias(function (string $file) use ($error): mixed {
            // Solo falla `banners` (no instalado en este escenario); `shipping` sí se activa.
            if ($file === 'hwe-banners/hwe-banners.php') {
                $this->activated[] = $file;
                return $error;
            }
            $this->activated[] = $file;
            return null;
        });
        Functions\when('is_wp_error')->alias(static fn (mixed $t): bool => $t === $error);

        $result = Modules::applySetup(['banners', 'shipping']);

        $this->assertSame(['shipping'], $result['activated']);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('banners', $this->logged[0]);
    }
}
