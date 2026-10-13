<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Instance;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Landings\Registry;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La landing con diseño propio del ejemplo llega al registro de `hwe-landings` por el filtro
 * `hwe_landings_static_entries`, y manda sobre una del panel con el mismo identificador.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExampleLandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)), '-'),
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'plugin_dir_url' => static fn () => '',
        ]);
        Functions\when('get_option')->alias(static fn (string $key, $default = false) => 'hwe_config' === $key
            ? ['landings' => ['registry' => "example-guide | Intrusa | person\notra | Otra | article"]]
            : $default);
        $this->loadOwnFile('plugins/hwe-landings/hwe-landings.php');

        // El archivo de instancia engancha su filtro: se captura el callback y se aplica al leer.
        $callback = null;
        Filters\expectAdded('hwe_landings_static_entries')->once()->whenHappen(
            static function (callable $cb) use (&$callback): void {
                $callback = $cb;
            }
        );
        $this->loadOwnFile('mu-plugins/instance/40-example-landing.php');
        Filters\expectApplied('hwe_landings_static_entries')->andReturnUsing(
            static fn (array $entries): array => $callback($entries)
        );
    }

    public function testLaEstaticaDelEjemploMandaSobreLaDelPanel(): void
    {
        $all = Registry::all();

        $this->assertFalse($all['example-guide']['dynamic']);
        $this->assertSame('Guía de ejemplo', $all['example-guide']['label']);
        $this->assertSame('article', $all['example-guide']['jsonld']);
        $this->assertTrue($all['otra']['dynamic']);
    }
}
