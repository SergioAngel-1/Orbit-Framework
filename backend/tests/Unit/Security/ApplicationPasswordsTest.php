<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Security;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Las Application Passwords están apagadas para todos salvo que el módulo
 * `external-integration` las encienda (`hwe_application_passwords_enabled`), y entonces solo
 * lo que WordPress ya considerara disponible.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApplicationPasswordsTest extends TestCase
{
    /** @var callable|null */
    private $callback = null;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs(['__' => static fn ($v) => $v]);
        Filters\expectAdded('wp_is_application_passwords_available')
            ->once()
            ->whenHappen(function (callable $cb): void {
                $this->callback = $cb;
            });
        $this->loadOwnFile('mu-plugins/security.php');
    }

    public function testSinElModuloEstanApagadas(): void
    {
        $this->assertFalse(($this->callback)(true));
    }

    public function testConElModuloSeRespetaLaDisponibilidadDeWordPress(): void
    {
        Filters\expectApplied('hwe_application_passwords_enabled')->andReturn(true);

        $this->assertTrue(($this->callback)(true));
        $this->assertFalse(($this->callback)(false));
    }
}
