<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\PublicConfigWalker;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * El contrato de schema entre el núcleo y una instancia.
 *
 * Una instancia añade sus ajustes al Control Center con el filtro `hwe_control_center_schema`,
 * pero SOLO dentro del grupo raíz `instance`. Lo demás protege al núcleo:
 *  · una clave raíz nueva se descarta (y se registra): si no, cada instancia inventaría sus
 *    propias pestañas y el panel y la API pública dejarían de tener una forma estable;
 *  · un grupo del núcleo que un filtro elimine se restaura: AdminPage indexa varias pestañas
 *    por nombre, y el frontend da por hecha la forma de la config;
 *  · un filtro que devuelve basura no rompe el panel.
 *
 * El registro de problemas se limita a una línea por problema y proceso (se evalúa en cada
 * petición, varias veces): de ahí el proceso por test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SchemaContractTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    /** Hace que el filtro `hwe_control_center_schema` devuelva lo que calcule $callback. */
    private function schemaFilter(callable $callback): void
    {
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing($callback);
    }

    /** Las claves raíz que el núcleo declara hoy (sin `instance`). */
    private const CORE_ROOTS = [
        'brand', 'social', 'legal', 'design', 'ecommerce', 'banners', 'payments',
        'integrations', 'backups', 'seo', 'shipping', 'geo',
    ];

    public function testTheCoreDeclaresAnEmptyInstanceGroup(): void
    {
        $schema = Schema::get();

        $this->assertArrayHasKey('instance', $schema);
        $this->assertSame('group', $schema['instance']['type']);
        $this->assertSame('Instancia', $schema['instance']['label']);
        $this->assertSame([], $schema['instance']['children']);
    }

    public function testTheCoreRootsAreUntouchedWithoutAnyFilter(): void
    {
        $this->assertSame(
            [...self::CORE_ROOTS, 'instance'],
            array_keys(Schema::get()),
            'Núcleo + instance, en ese orden (instance al final: es la pestaña de la instancia).'
        );
    }

    public function testTheFilterReceivesTheCoreSchemaAndItsChildrenInsideInstanceAreKept(): void
    {
        $this->schemaFilter(static function (array $schema): array {
            $schema['instance']['children']['example'] = [
                'type' => 'group',
                'label' => 'Ejemplo',
                'children' => [
                    'greeting' => ['type' => 'text', 'label' => 'Saludo', 'default' => 'Hola', 'public' => true],
                ],
            ];
            return $schema;
        });

        $schema = Schema::get();

        $this->assertSame('Hola', $schema['instance']['children']['example']['children']['greeting']['default']);
        $this->assertSame([], $this->logged, 'Un uso correcto del contrato no genera avisos.');
    }

    public function testInstanceFieldsAreExposedInThePublicConfigLikeAnyOtherGroup(): void
    {
        $this->schemaFilter(static function (array $schema): array {
            $schema['instance']['children']['example'] = [
                'type' => 'group',
                'label' => 'Ejemplo',
                'children' => [
                    'greeting' => ['type' => 'text', 'label' => 'Saludo', 'default' => 'Hola', 'public' => true],
                    'api_key' => ['type' => 'secret', 'label' => 'Clave', 'public' => true],
                    'interno' => ['type' => 'text', 'label' => 'Interno'],
                ],
            ];
            return $schema;
        });

        $public = (new PublicConfigWalker())->walk(Schema::get(), ['instance' => ['example' => ['greeting' => 'Buenas']]]);

        $this->assertSame(['example' => ['greeting' => 'Buenas']], $public['instance']);
        $this->assertStringNotContainsString('api_key', json_encode($public, JSON_THROW_ON_ERROR), 'Un secreto nunca sale por la API pública.');
        $this->assertStringNotContainsString('interno', json_encode($public, JSON_THROW_ON_ERROR), 'Lo que no es público tampoco.');
    }

    public function testAnEmptyInstanceGroupIsOmittedFromThePublicConfig(): void
    {
        // PHP serializa un array vacío como `[]` y no como `{}`: dejar `instance: []` en el JSON
        // haría que el frontend viera un array donde espera un objeto.
        $public = (new PublicConfigWalker())->walk(Schema::get(), []);

        $this->assertArrayNotHasKey('instance', $public);
    }

    public function testNoInternalMarkerEverReachesThePublicConfig(): void
    {
        // Un grupo raíz sin ningún campo público (aquí, solo secretos) devolvía el marcador
        // interno del walker, que acababa en el JSON como "\u0000hwe_skip\u0000".
        $this->schemaFilter(static function (array $schema): array {
            $schema['instance']['children']['solo_secretos'] = [
                'type' => 'group',
                'label' => 'Solo secretos',
                'children' => ['clave' => ['type' => 'secret', 'label' => 'Clave', 'public' => true]],
            ];
            return $schema;
        });

        $public = (new PublicConfigWalker())->walk(Schema::get(), []);

        $this->assertArrayNotHasKey('instance', $public);
        $this->assertStringNotContainsString('hwe_skip', json_encode($public, JSON_THROW_ON_ERROR));
    }

    public function testARootKeyOutsideTheCoreAndInstanceIsDiscardedAndLogged(): void
    {
        $this->schemaFilter(static function (array $schema): array {
            $schema['mi_pestana'] = ['type' => 'group', 'label' => 'Mía', 'children' => []];
            $schema['otra'] = ['type' => 'text', 'label' => 'Otra'];
            return $schema;
        });

        $schema = Schema::get();

        $this->assertArrayNotHasKey('mi_pestana', $schema);
        $this->assertArrayNotHasKey('otra', $schema);
        $this->assertSame([...self::CORE_ROOTS, 'instance'], array_keys($schema));
        $this->assertCount(2, $this->logged);
        $this->assertStringContainsString('[hwe] control-center:', $this->logged[0]);
        $this->assertStringContainsString('mi_pestana', $this->logged[0]);
        $this->assertStringContainsString('instance', $this->logged[0], 'Dice dónde deben ir los ajustes.');
        $this->assertStringContainsString('otra', $this->logged[1]);
    }

    public function testTheSameProblemIsLoggedOnlyOncePerProcess(): void
    {
        $this->schemaFilter(static function (array $schema): array {
            $schema['mi_pestana'] = ['type' => 'group', 'label' => 'Mía', 'children' => []];
            return $schema;
        });

        for ($i = 0; $i < 10; $i++) {
            Schema::get();
        }

        $this->assertCount(1, $this->logged);
    }

    public function testACoreGroupRemovedByAFilterIsRestoredAndLogged(): void
    {
        $this->schemaFilter(static function (array $schema): array {
            unset($schema['payments'], $schema['geo']);
            return $schema;
        });

        $schema = Schema::get();

        $this->assertSame([...self::CORE_ROOTS, 'instance'], array_keys($schema));
        $this->assertSame('group', $schema['payments']['type']);
        $this->assertCount(2, $this->logged);
        $this->assertStringContainsString('payments', $this->logged[0]);
        $this->assertStringContainsString('geo', $this->logged[1]);
    }

    public function testAFilterThatReturnsSomethingElseThanAnArrayFallsBackToTheCoreSchema(): void
    {
        $this->schemaFilter(static fn () => 'no soy un esquema');

        $schema = Schema::get();

        $this->assertSame([...self::CORE_ROOTS, 'instance'], array_keys($schema));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('hwe_control_center_schema', $this->logged[0]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function brokenInstanceNodes(): iterable
    {
        yield 'texto' => ['no soy un grupo'];
        yield 'campo en lugar de grupo' => [['type' => 'text', 'label' => 'Instancia']];
        yield 'grupo sin children' => [['type' => 'group', 'label' => 'Instancia']];
        yield 'children que no es array' => [['type' => 'group', 'label' => 'Instancia', 'children' => 'x']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenInstanceNodes')]
    public function testAnInstanceNodeThatIsNotAGroupIsReplacedByAnEmptyOne(mixed $broken): void
    {
        $this->schemaFilter(static function (array $schema) use ($broken): array {
            $schema['instance'] = $broken;
            return $schema;
        });

        $schema = Schema::get();

        $this->assertSame('group', $schema['instance']['type']);
        $this->assertSame([], $schema['instance']['children']);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('instance', $this->logged[0]);
    }

    public function testAFilterMayAddFieldsToACoreGroupBecauseOnlyTheRootIsPoliced(): void
    {
        $this->schemaFilter(static function (array $schema): array {
            $schema['brand']['children']['slogan_extra'] = ['type' => 'text', 'label' => 'Extra', 'public' => true];
            return $schema;
        });

        $schema = Schema::get();

        $this->assertArrayHasKey('slogan_extra', $schema['brand']['children']);
        $this->assertSame([], $this->logged);
    }
}
