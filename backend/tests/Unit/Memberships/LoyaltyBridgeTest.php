<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-memberships`, puente con `loyalty` sin el plugin cargado y con el contrato
 * publicado (`hwe_loyalty_referrer_of`).
 *
 * La comisión extra y el regalo de referido ya no leen la meta `_hwe_membership_referrer`: el
 * referidor sale del contrato de `loyalty` en cada cálculo. Aquí se fija que sin el plugin no
 * hay referidor ni errores, que la meta no se consulta y que una respuesta inválida o una
 * excepción del contrato se resuelven como «sin referidor» (falla cerrada) con un log técnico.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoyaltyBridgeTest extends TestCase
{
    private const USER = 21;
    private const REFERRER = 20;
    private const MISSING = 404;

    /** @var list<string> */
    private array $logged = [];
    /** @var mixed Respuesta simulada del contrato de `loyalty`. */
    private mixed $contractAnswer = null;
    private bool $contractThrows = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged         = [];
        $this->contractAnswer = null;
        $this->contractThrows = false;

        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-memberships/includes/LoyaltyBridge.php');
    }

    public function testWithoutTheLoyaltyPluginThereIsNoReferrerAndNoError(): void
    {
        $this->assertFalse(function_exists('hwe_loyalty_referrer_of'));
        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));
        $this->assertSame([], $this->logged, 'Con loyalty apagado no se registra ningún error.');
    }

    public function testTheReferrerComesFromTheLoyaltyContractAndNotFromAUserMeta(): void
    {
        $this->contractAnswer = self::REFERRER;
        Functions\when('get_userdata')->alias(
            fn (int $id) => self::REFERRER === $id || self::USER === $id ? (object) ['ID' => $id] : false
        );
        Functions\when('hwe_loyalty_referrer_of')->alias(fn (): mixed => $this->contractAnswer);
        Functions\expect('get_user_meta')->never();

        $this->assertSame(self::REFERRER, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));
    }

    public function testASelfReferenceAMissingUserOrABadAnswerIsIgnored(): void
    {
        Functions\when('get_userdata')->alias(
            fn (int $id) => self::USER === $id ? (object) ['ID' => $id] : false
        );
        Functions\when('hwe_loyalty_referrer_of')->alias(fn (): mixed => $this->contractAnswer);

        $this->contractAnswer = self::USER;
        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));

        $this->contractAnswer = self::MISSING;
        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));

        $this->contractAnswer = null;
        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));

        $this->contractAnswer = 'no-es-un-id';
        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));
    }

    public function testAContractFailureIsLoggedAndTreatedAsNoReferrer(): void
    {
        $this->contractThrows = true;
        Functions\when('hwe_loyalty_referrer_of')->alias(function (): ?int {
            if ($this->contractThrows) {
                throw new \RuntimeException('fallo simulado');
            }
            return null;
        });

        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::USER));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
        $this->assertStringContainsString('fallo simulado', $this->logged[0]);
    }
}
