<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Solo cuatro familias de rutas son exclusivas del BFF. Cerrar de más rompería el
 * panel de administración (WooCommerce Admin usa `wc/v3`, `wc-admin`, `wc-analytics`).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RoutesTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        define('REST_REQUEST', true);
        parent::setUp();
    }

    /** @return iterable<string, array{string}> */
    public static function openRoutes(): iterable
    {
        yield 'config pública' => ['/hwe/v1/config'];
        yield 'WooCommerce REST (autenticada por claves)' => ['/wc/v3/orders'];
        yield 'WooCommerce Admin' => ['/wc-admin/options'];
        yield 'WooCommerce Analytics' => ['/wc-analytics/reports'];
        yield 'REST de WordPress' => ['/wp/v2/users'];
        yield 'batch de WordPress (cada sub-petición se filtra por separado)' => ['/batch/v1'];
        yield 'banners' => ['/hwe-banners/v1/banners/home'];
        yield 'una ruta que solo se parece' => ['/hwe/v1/authority'];
    }

    #[DataProvider('openRoutes')]
    public function testOtherRoutesAreNotTouchedEvenWithoutTheSecret(string $route): void
    {
        $this->assertNull($this->dispatch($route));
    }

    public function testAnAlreadyResolvedResultIsNotOverwritten(): void
    {
        $existing = new \WP_Error('otro', 'otro error');

        $result = ($this->restFilter)($existing, new \stdClass(), new \WP_REST_Request([], [], '/wc/store/v1/cart'));

        $this->assertSame($existing, $result);
    }

    /** @return iterable<string, array{string}> */
    public static function loyaltyRoutes(): iterable
    {
        yield 'referidos: atribuir' => ['/hwe/v1/loyalty/attribute'];
        yield 'referidos: validar código' => ['/hwe/v1/loyalty/validate-code'];
        yield 'referidos: código del usuario' => ['/hwe/v1/loyalty/referral-code'];
    }

    #[DataProvider('loyaltyRoutes')]
    public function testLoyaltyRoutesAreBffOnly(string $route): void
    {
        $result = $this->dispatch($route);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Sin el secreto interno la ruta no existe.');
        $this->assertSame('rest_no_route', $result->get_error_code());
        $this->assertSame(['status' => 404], $result->get_error_data());
    }

    public function testARequestWpGraphqlDoesNotConsiderItsOwnIsNotTouched(): void
    {
        // Sin secreto: aun así no se interviene si WPGraphQL ya dijo que no es GraphQL.
        $this->assertFalse(($this->graphqlFilter)(false));
    }
}
