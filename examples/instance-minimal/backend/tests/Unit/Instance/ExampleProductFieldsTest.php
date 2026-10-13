<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Instance;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Los campos de producto del ejemplo llegan al registro de `hwe-product-fields` por el filtro
 * `hwe_product_fields`, y el traducible se registra en `hwe-content-i18n`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExampleProductFieldsTest extends TestCase
{
    public function testLosCamposDelEjemploSeDeclaranYElTraducibleLlegaAContentI18n(): void
    {
        Functions\stubs(['error_log' => static fn () => true, '__' => static fn ($v) => $v]);
        Functions\when('hwe_i18n_locales')->justReturn(['en']);
        Actions\expectAdded('add_meta_boxes_product')->once();
        Actions\expectAdded('save_post_product')->once();
        Actions\expectAdded('graphql_register_types')->once();

        // El archivo de instancia engancha su filtro: se captura el callback y se aplica al leer.
        $callback = null;
        Filters\expectAdded('hwe_product_fields')->once()->whenHappen(
            static function (callable $cb) use (&$callback): void {
                $callback = $cb;
            }
        );
        $this->loadOwnFile('mu-plugins/instance/50-example-product-field.php');
        Filters\expectApplied('hwe_product_fields')->andReturnUsing(
            static fn ($fields) => $callback($fields)
        );
        $this->loadOwnFile('mu-plugins/hwe-product-fields.php');

        $fields = hwe_product_fields();

        $this->assertSame(['example_usage', 'example_weight'], array_keys($fields));
        $this->assertTrue($fields['example_usage']['translatable']);
        $this->assertSame('number', $fields['example_weight']['type']);
        $this->assertSame('Ejemplo de la instancia', $fields['example_weight']['box']);

        $registry = hwe_product_fields_register_translatable(['product' => ['fields' => []]]);
        $this->assertSame(['example_usage'], array_keys($registry['product']['fields']));
    }
}
