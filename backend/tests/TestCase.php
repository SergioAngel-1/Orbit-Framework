<?php

declare(strict_types=1);

namespace HWE\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base de los tests del backend: levanta y desmonta Brain Monkey (las funciones
 * y hooks de WordPress simulados) y cuenta las expectativas de Mockery.
 *
 * Ver tests/bootstrap.php para el patrón de carga de archivos bajo prueba.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Carga un archivo propio (mu-plugin, plugin o tema) relativo a wp-content/.
     * Hazlo tras declarar las expectativas de hooks: ver tests/bootstrap.php.
     */
    protected function loadOwnFile(string $relativeToWpContent): void
    {
        $path = HWE_WP_CONTENT_DIR . '/' . ltrim($relativeToWpContent, '/');
        $this->assertFileExists($path);
        require_once $path;
    }
}
