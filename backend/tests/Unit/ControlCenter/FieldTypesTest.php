<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminSave;
use HWE\ControlCenter\FieldTypes\BooleanType;
use HWE\ControlCenter\FieldTypes\ColorType;
use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\FieldTypes\SecretType;
use HWE\ControlCenter\FieldTypes\SelectType;
use HWE\ControlCenter\FieldTypes\TextType;
use HWE\ControlCenter\FieldTypes\UnknownFieldType;
use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\Tests\TestCase;

/**
 * El registro de tipos de campo (`FieldTypes\Registry`).
 *
 * Cada tipo sabe pintar su control, sanear lo recibido y declarar dónde se persiste
 * (`config`/`secret`/`external`). Los tipos del núcleo viajan por el mismo filtro que los
 * de una instancia (`hwe_control_center_field_types`): el filtro puede añadir ids, pero no
 * sobrescribir ni eliminar los del núcleo, y un tipo desconocido se pinta deshabilitado en
 * vez de romper el panel.
 */
class ShoutyUpperType implements FieldType {

    public function id(): string {
        return 'shouty_upper_26';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        return '<input type="text" class="shouty" value="' . esc_attr((string) ($value ?? '')) . '">';
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return strtoupper(sanitize_text_field((string) ($raw ?? '')));
    }
}

class ExternalStubType implements FieldType {

    public function id(): string {
        return 'external_stub_26';
    }

    public function persistence(): string {
        return self::EXTERNAL;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        return '<input type="text" value="">';
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return $raw;
    }
}

final class FieldTypesTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_email' => static fn ($v) => (string) $v,
            'esc_url_raw' => static fn ($v) => (string) $v,
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            'esc_textarea' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    // ── Saneado por tipo ────────────────────────────────────────────────────

    public function testTextSanitizesLikeWordPress(): void {
        $type = new TextType();

        $this->assertSame('Hola', $type->sanitize([], '  <b>Hola</b>  '));
        $this->assertSame('', $type->sanitize([], null));
        $this->assertSame(FieldType::CONFIG, $type->persistence());
    }

    public function testTextJoinsAListOfScalarsInsteadOfStoringArray(): void {
        $type = new TextType();

        // `i18n.locales: ["en", "fr"]` en instance.config.json: una lista es una forma
        // natural de escribirlo, y `(string) array` guardaría la palabra «Array».
        $this->assertSame('en, fr', $type->sanitize([], ['en', 'fr']));
        $this->assertSame('en', $type->sanitize([], ['en', ['x'], '']));
        $this->assertSame('', $type->sanitize([], [['x'], new \stdClass()]));
    }

    public function testColorOnlyAcceptsHex(): void {
        $type = new ColorType();

        $this->assertSame('#ff0000', $type->sanitize([], '#ff0000'));
        $this->assertSame('', $type->sanitize([], 'red'));
        $this->assertSame('', $type->sanitize([], '#fff'));
        $this->assertSame('', $type->sanitize([], null));
    }

    public function testBooleanTreatsZeroAsOff(): void {
        $type = new BooleanType();

        $this->assertTrue($type->sanitize([], '1'));
        $this->assertFalse($type->sanitize([], '0'));
        $this->assertFalse($type->sanitize([], ''));
        $this->assertFalse($type->sanitize([], null));
    }

    public function testSelectFallsBackToDefault(): void {
        $type  = new SelectType();
        $field = ['options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a'];

        $this->assertSame('b', $type->sanitize($field, 'b'));
        $this->assertSame('a', $type->sanitize($field, 'c'));
        $this->assertSame('a', $type->sanitize($field, null));
    }

    public function testSecretIsConfigNeverPublic(): void {
        $type = new SecretType();

        $this->assertSame(FieldType::SECRET, $type->persistence());
        $this->assertSame('clave', $type->sanitize([], 'clave'));
    }

    // ── Render por tipo ─────────────────────────────────────────────────────

    public function testTextRendersAnInputWithName(): void {
        $html = (new TextType())->render([], 'hwe_config_brand_name', 'hwe_config[brand][name]', 'Mi marca', ['brand', 'name']);

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('name="hwe_config[brand][name]"', $html);
        $this->assertStringContainsString('Mi marca', $html);
    }

    public function testSecretRendersAnEmptyPasswordWithPlaceholder(): void {
        $without = (new SecretType())->render([], 'id', 'name', 'valor-oculto', ['g', 'clave']);
        // El mapa de secretos del panel es de EXISTENCIA (hojas booleanas): un secreto
        // guardado se muestra como «guardado» sin revelar su valor.
        $with = (new SecretType())->render([], 'id', 'name', null, ['g', 'clave'], ['secrets' => ['g' => ['clave' => true]]]);

        foreach ([$without, $with] as $html) {
            $this->assertStringContainsString('type="password"', $html);
            $this->assertStringNotContainsString('valor-oculto', $html, 'El secreto nunca se pinta.');
        }
        $this->assertStringContainsString('Introducir valor', $without);
        $this->assertStringContainsString('conservar', $with);
    }

    public function testUnknownTypeRendersDisabledAndIsLogged(): void {
        $html = Registry::get('tipo_que_no_existe_26')->render(
            ['label' => 'Campo raro'], 'id', 'name', 'guardado', ['g', 'raro']
        );

        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('guardado', $html, 'El valor se muestra aunque no se edite.');
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('tipo_que_no_existe_26', $this->logged[0]);
    }

    public function testUnknownTypeSkipsPersistenceWhenAbsent(): void {
        $type = new UnknownFieldType('otro_raro_26');

        $this->assertSame(FieldType::SKIP, $type->sanitize([], null));
        $this->assertSame('algo', $type->sanitize([], 'algo'));
    }

    // ── Registro y contrato ─────────────────────────────────────────────────

    public function testTheCoreRegistersItsEightTypes(): void {
        $types = Registry::all();

        foreach (['text', 'textarea', 'email', 'url', 'color', 'boolean', 'select', 'secret'] as $id) {
            $this->assertArrayHasKey($id, $types);
            $this->assertSame($id, $types[$id]->id());
        }
        $this->assertSame([], $this->logged, 'Sin filtros, el registro no genera avisos.');
    }

    public function testTheFilterCanAddAnInstanceType(): void {
        Filters\expectApplied('hwe_control_center_field_types')->andReturnUsing(
            static fn (array $types): array => [...$types, 'shouty_upper_26' => new ShoutyUpperType()]
        );

        $type = Registry::get('shouty_upper_26');

        $this->assertSame('SHOUTY', $type->sanitize([], 'shouty'));
        $this->assertStringContainsString('class="shouty"', $type->render([], 'id', 'name', 'x', []));
        $this->assertSame([], $this->logged, 'Un uso correcto del contrato no genera avisos.');
    }

    public function testOverridingACoreTypeIsDiscardedAndLogged(): void {
        $fakeText = new class implements FieldType {
            public function id(): string {
                return 'text';
            }

            public function persistence(): string {
                return self::CONFIG;
            }

            public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
                return 'falso';
            }

            public function sanitize(array $field, mixed $raw): mixed {
                return 'falso';
            }
        };
        Filters\expectApplied('hwe_control_center_field_types')->andReturnUsing(
            static fn (array $types): array => [...$types, 'text' => $fakeText]
        );

        $this->assertInstanceOf(TextType::class, Registry::get('text'));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('text', $this->logged[0]);
    }

    public function testANonArrayFilterResultFallsBackToCore(): void {
        Filters\expectApplied('hwe_control_center_field_types')->andReturn('basura');

        $this->assertInstanceOf(TextType::class, Registry::get('text'));
        $this->assertCount(1, $this->logged);
    }

    // ── sanitizeAndSplit: normal y sparse ───────────────────────────────────

    private function miniSchema(): array {
        return [
            'brand' => [
                'type' => 'group',
                'label' => 'Marca',
                'children' => [
                    'name' => ['type' => 'text', 'label' => 'Nombre', 'default' => '', 'public' => true],
                    'show' => ['type' => 'boolean', 'label' => 'Mostrar', 'default' => false, 'public' => true],
                ],
            ],
            'api' => [
                'type' => 'group',
                'label' => 'API',
                'children' => [
                    'key' => ['type' => 'secret', 'label' => 'Clave'],
                ],
            ],
        ];
    }

    public function testSanitizeAndSplitSeparatesPublicFromSecrets(): void {
        [$public, $secrets] = AdminSave::sanitizeAndSplit($this->miniSchema(), [
            'brand' => ['name' => '  <b>T</b>  ', 'show' => '1'],
            'api' => ['key' => 'secreto'],
        ]);

        $this->assertSame(['brand' => ['name' => 'T', 'show' => true]], $public);
        $this->assertSame(['api' => ['key' => 'secreto']], $secrets);
    }

    public function testSparseModeOmitsMissingKeysInsteadOfBlankingThem(): void {
        [$public, $secrets] = AdminSave::sanitizeAndSplit($this->miniSchema(), [
            'brand' => ['name' => 'Solo esto'],
        ], [], true);

        // `show` y todo `api` no llegaron: en modo sparse no se tocan (un JSON parcial de
        // `wp hwe setup` no borra el resto de la config).
        $this->assertSame(['brand' => ['name' => 'Solo esto']], $public);
        $this->assertSame([], $secrets);
    }

    public function testNormalModeBlanksMissingKeysLikeTheFullAdminForm(): void {
        [$public, $secrets] = AdminSave::sanitizeAndSplit($this->miniSchema(), [
            'brand' => ['name' => 'T'],
        ]);

        // El formulario de admin siempre envía el árbol completo; lo que falta se sanea a
        // vacío, como hacía el código anterior a esta tarea (el booleano ausente es `false`
        // y el secreto vacío no se guarda).
        $this->assertSame(['brand' => ['name' => 'T', 'show' => false]], $public);
        $this->assertSame([], $secrets);
    }

    public function testExternalTypesAreNotPersistedHere(): void {
        Filters\expectApplied('hwe_control_center_field_types')->andReturnUsing(
            static fn (array $types): array => [...$types, 'external_stub_26' => new ExternalStubType()]
        );

        [$public, $secrets] = AdminSave::sanitizeAndSplit([
            'mod' => [
                'type' => 'group',
                'label' => 'Módulo',
                'children' => [
                    'items' => ['type' => 'external_stub_26', 'label' => 'Ítems'],
                    'name' => ['type' => 'text', 'label' => 'Nombre', 'default' => ''],
                ],
            ],
        ], ['mod' => ['items' => [['a' => 1]], 'name' => 'N']]);

        // `items` lo persiste su módulo en `hwe_control_center_saved`; aquí no sale en ningún cubo.
        $this->assertSame(['mod' => ['name' => 'N']], $public);
        $this->assertSame([], $secrets);
    }

    // ── El walker delega en el registro ─────────────────────────────────────

    public function testTheFormWalkerDelegatesToTheRegistry(): void {
        $sections = (new AdminFormWalker())->walk($this->miniSchema(), [
            'brand' => ['name' => 'T', 'show' => true],
        ]);

        $this->assertStringContainsString('name="hwe_config[brand][name]"', $sections['brand']);
        $this->assertStringContainsString('type="checkbox"', $sections['brand']);
        $this->assertStringContainsString('type="password"', $sections['api']);
    }

    public function testTheFormWalkerRendersUnknownTypesDisabled(): void {
        $sections = (new AdminFormWalker())->walk([
            'mod' => [
                'type' => 'group',
                'label' => 'Módulo',
                'children' => [
                    'items' => ['type' => 'desaparecido_26', 'label' => 'Ítems', 'default' => 'v'],
                ],
            ],
        ], []);

        $this->assertStringContainsString('disabled', $sections['mod']);
        $this->assertNotEmpty($this->logged);
    }
}
