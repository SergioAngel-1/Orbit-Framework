<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Instance;

use Brain\Monkey\Functions;
use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\Revalidation;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\DefaultsWalker;
use HWE\ControlCenter\Walkers\PublicConfigWalker;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * El ejemplo vivo (`examples/instance-minimal/`) funciona de punta a punta.
 *
 * Este test vive EN EL EJEMPLO y solo corre en el job «contrato» de CI, que superpone el
 * ejemplo al repo. Carga los archivos de `mu-plugins/instance/` del ejemplo como lo haría
 * `hwe-instance-loader.php` y comprueba que:
 *  · el campo `instance.example.greeting` aparece en el esquema efectivo;
 *  · sale por la config pública como cualquier otro grupo;
 *  · el tag `example` se puede revalidar.
 *
 * Nota sobre Brain Monkey: `apply_filters` no ejecuta los callbacks registrados con
 * `add_filter`, así que el test compone a mano lo que WordPress compondría solo: el
 * esquema del núcleo pasa por la función nombrada del ejemplo y el resultado por el
 * contrato (`Schema::get()` lo haría en producción). Por eso los archivos del ejemplo
 * exponen funciones nombradas en vez de closures anónimas.
 *
 * Si este test falla, el contrato núcleo ↔ instancia se ha roto.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExamplePublicConfigTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'esc_attr' => static fn ($v) => (string) $v,
        ]);
        // El esquema resuelve opciones contra Woo: sin WP, vacías.
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $default);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        // Los archivos del ejemplo, como los cargaría el cargador (en orden alfabético).
        $this->loadOwnFile('mu-plugins/instance/10-example-schema.php');
        $this->loadOwnFile('mu-plugins/instance/20-example-field-type.php');
        $this->loadOwnFile('mu-plugins/instance/30-example-revalidation.php');
    }

    public function testTheExampleFieldIsInTheEffectiveSchema(): void
    {
        $schema = hwe_example_add_fields(Schema::core());

        $this->assertSame(
            'Hola',
            $schema['instance']['children']['example']['children']['greeting']['default']
        );
        $this->assertSame([], $this->logged, 'Un uso correcto del contrato no genera avisos.');
    }

    public function testTheExampleFieldIsExposedInThePublicConfig(): void
    {
        $schema   = hwe_example_add_fields(Schema::core());
        $defaults = (new DefaultsWalker())->walk($schema);
        $merged   = array_replace_recursive($defaults, ['instance' => ['example' => ['greeting' => 'Buenas']]]);
        $public   = (new PublicConfigWalker())->walk($schema, $merged);

        $this->assertSame(['example' => ['greeting' => 'Buenas']], $public['instance']);
    }

    public function testTheExampleTagCanBeRevalidated(): void
    {
        $this->assertContains('example', hwe_example_tags(Revalidation::allowedTags()));
    }

    public function testTheExampleFieldTypeRendersAndSanitizes(): void
    {
        $types = hwe_example_field_types(Registry::all());

        $this->assertArrayHasKey('example_upper', $types);
        $type = $types['example_upper'];
        $this->assertSame('config', $type->persistence());
        $this->assertSame('HOLA', $type->sanitize([], 'hola'));
        $this->assertStringContainsString('hwe-example-upper', $type->render([], 'id', 'name', 'x', []));
    }
}
