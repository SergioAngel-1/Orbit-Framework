<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\GraphqlProtection;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La introspección revela el esquema completo de la API; en producción tiene que estar
 * apagada.
 *
 * Antes se comprobaba `defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production'`:
 * si la constante NO está definida —el despliegue más común si se olvida fijarla— la
 * condición era falsa y la introspección quedaba ACTIVA, justo cuando WordPress considera
 * que el entorno es «production» por defecto. Ahora se pregunta a `wp_get_environment_type()`,
 * que aplica ese valor por defecto, y se falla cerrado.
 *
 * Las constantes son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IntrospectionTest extends TestCase
{
    /** @var callable|null */
    private $introspectionFilter = null;

    private function loadWithEnvironment(string $environmentType): void
    {
        Functions\when('wp_get_environment_type')->justReturn($environmentType);
        Filters\expectAdded('graphql_introspection_enabled')
            ->once()
            ->whenHappen(function (callable $callback): void {
                $this->introspectionFilter = $callback;
            });

        $this->loadOwnFile('mu-plugins/graphql-protection.php');
        $this->assertNotNull($this->introspectionFilter, 'El filtro de introspección no se registró.');
    }

    public function testProductionWithoutTheConstantDisablesIntrospection(): void
    {
        // WordPress devuelve «production» por defecto cuando WP_ENVIRONMENT_TYPE no existe.
        $this->assertFalse(defined('WP_ENVIRONMENT_TYPE'));
        $this->loadWithEnvironment('production');

        $this->assertFalse(($this->introspectionFilter)(true));
    }

    public function testProductionWithTheConstantDisablesIntrospection(): void
    {
        define('WP_ENVIRONMENT_TYPE', 'production');
        $this->loadWithEnvironment('production');

        $this->assertFalse(($this->introspectionFilter)(true));
    }

    /** @return iterable<string, array{string}> */
    public static function nonProductionEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'development' => ['development'];
        yield 'staging' => ['staging'];
    }

    #[DataProvider('nonProductionEnvironments')]
    public function testAnExplicitNonProductionEnvironmentLeavesIntrospectionAsIs(string $environment): void
    {
        define('WP_ENVIRONMENT_TYPE', $environment);
        $this->loadWithEnvironment($environment);

        $this->assertTrue(($this->introspectionFilter)(true));
        $this->assertFalse(($this->introspectionFilter)(false), 'No debe activarla si otro filtro la apagó.');
    }

    public function testTheLegacyConstantIsDefinedOffInProductionEvenWithoutWpEnvironmentType(): void
    {
        // Para WPGraphQL < 1.14, que lee la constante en lugar del filtro.
        $this->assertFalse(defined('GRAPHQL_INTROSPECTION_ENABLED'));
        $this->loadWithEnvironment('production');

        $this->assertTrue(defined('GRAPHQL_INTROSPECTION_ENABLED'));
        $this->assertFalse(GRAPHQL_INTROSPECTION_ENABLED);
    }

    public function testTheLegacyConstantIsNotDefinedOutsideProduction(): void
    {
        define('WP_ENVIRONMENT_TYPE', 'local');
        $this->loadWithEnvironment('local');

        $this->assertFalse(defined('GRAPHQL_INTROSPECTION_ENABLED'));
    }
}
