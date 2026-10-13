<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminPage;
use HWE\ControlCenter\AdminSave;
use HWE\ControlCenter\BackupConfig;
use HWE\ControlCenter\Revalidation;
use HWE\ControlCenter\Schema;
use HWE\Tests\TestCase;

use function Patchwork\redefine;

/**
 * `AdminSave::handle()` y la acción `hwe_control_center_saved`.
 *
 * Antes, cada funcionalidad de una instancia que guardaba datos propios desde el panel
 * obligaba a que `AdminPage` leyera SU clave del POST y disparase SU acción con nombre
 * (`hwe_control_center_<cosa>_save`): el núcleo acababa lleno de nombres de instancia. Ahora
 * hay UNA acción genérica con la configuración pública ya saneada y el árbol `hwe_config`
 * crudo, y el guardado no lee ninguna clave del POST que no sea del schema.
 *
 * Además el guardado comprueba que el formulario llegó COMPLETO (todas las pestañas de
 * nivel superior con contenido): un POST parcial es un formulario roto o manipulado, nunca
 * una edición legítima. Los tests envían por eso posts completos (ver `completePost()`).
 *
 * `BackupConfig::write()` escribe `wp-content/hwe-backup-config.json` (carpeta que los
 * contenedores montan) y `Revalidation::trigger()` hace una petición HTTP: se sustituyen con
 * Patchwork para no tocar el entorno real y para registrar el ORDEN de las llamadas.
 */
final class AdminPageSavedTest extends TestCase
{
    /** @var list<string> orden en que ocurren los efectos */
    private array $events = [];
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];

        Functions\stubs([
            'wp_unslash' => static fn ($v) => $v,
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_email' => static fn ($v) => (string) $v,
            'esc_url_raw' => static fn ($v) => (string) $v,
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
        ]);
        // SecretsStorage avisa (con razón) de que el test no define clave de cifrado: no es el tema aquí.
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_verify_nonce')->alias(static fn ($nonce) => $nonce === 'valido');
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->events[] = 'storage:' . $key;
            $this->options[$key] = $value;
            return true;
        });

        redefine(BackupConfig::class . '::write', function (array $cfg): void {
            $this->events[] = 'backup';
        });
        redefine(Revalidation::class . '::trigger', function (): void {
            $this->events[] = 'revalidate';
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /** Ejecuta el guardado con el POST dado y devuelve el aviso HTML. */
    private function save(array $post): string
    {
        $_POST = $post;
        return AdminSave::handle();
    }

    private function postWithNonce(array $config, array $extra = []): array
    {
        return ['hwe_nonce' => 'valido', 'hwe_config' => $config] + $extra;
    }

    /**
     * Completa el POST con todas las pestañas de nivel superior (vacías salvo lo que el
     * test quiera fijar): un guardado real de wp-admin SIEMPRE envía el formulario entero,
     * y el guardado rechaza lo incompleto.
     */
    private function completePost(array $config, array $extra = []): array
    {
        $full = [];
        foreach (Schema::get() as $key => $node) {
            if (is_array($node) && !empty($node['children'] ?? [])) {
                $full[$key] = [];
            }
        }
        foreach ($config as $key => $value) {
            $full[$key] = $value;
        }
        return $this->postWithNonce($full, $extra);
    }

    public function testItFiresTheSavedActionWithThePublicDataAndTheRawConfigTree(): void
    {
        $captured = null;
        Actions\expectDone('hwe_control_center_saved')
            ->once()
            ->whenHappen(function (...$args) use (&$captured): void {
                $captured = $args;
            });

        $this->save($this->completePost(['brand' => ['name' => '<b>Tienda</b>']]));

        $this->assertNotNull($captured, 'La acción no se disparó.');
        [$publicData, $rawPost] = $captured;
        $this->assertSame('Tienda', $publicData['brand']['name'], 'El primer argumento son los datos YA saneados.');
        $this->assertSame('<b>Tienda</b>', $rawPost['brand']['name'], 'El segundo, el árbol hwe_config tal como llegó.');
    }

    public function testTheInstanceSettingsArePartOfTheSanitizedDataTheActionReceives(): void
    {
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(static function (array $schema): array {
            $schema['instance']['children']['example'] = [
                'type' => 'group',
                'label' => 'Ejemplo',
                'children' => ['greeting' => ['type' => 'text', 'label' => 'Saludo', 'public' => true]],
            ];
            return $schema;
        });
        $captured = null;
        Actions\expectDone('hwe_control_center_saved')->once()->whenHappen(function (...$args) use (&$captured): void {
            $captured = $args;
        });

        $this->save($this->completePost(['instance' => ['example' => ['greeting' => 'Hola <script>x</script>']]]));

        $this->assertSame('Hola x', $captured[0]['instance']['example']['greeting']);
        $this->assertSame('Hola <script>x</script>', $captured[1]['instance']['example']['greeting']);
    }

    public function testItFiresAfterSavingAndBeforeRevalidating(): void
    {
        Actions\expectDone('hwe_control_center_saved')->once()->whenHappen(function (): void {
            $this->events[] = 'saved';
        });

        $this->save($this->completePost(['brand' => ['name' => 'Tienda']]));

        $storage = array_search('storage:hwe_config', $this->events, true);
        $saved = array_search('saved', $this->events, true);
        $revalidate = array_search('revalidate', $this->events, true);
        $this->assertNotFalse($storage);
        $this->assertNotFalse($saved);
        $this->assertNotFalse($revalidate);
        $this->assertLessThan($saved, $storage, 'Los handlers ven la config ya guardada.');
        $this->assertLessThan($revalidate, $saved, 'Los handlers (que pueden cambiar datos propios) van ANTES de revalidar el frontend.');
    }

    public function testAnInvalidNonceFiresNothingAndSavesNothing(): void
    {
        Actions\expectDone('hwe_control_center_saved')->never();

        $notice = $this->save(['hwe_nonce' => 'falso', 'hwe_config' => ['brand' => ['name' => 'X']]]);

        $this->assertStringContainsString('notice-error', $notice);
        $this->assertSame([], $this->events);
    }

    public function testThePanelDoesNotReadAnyPostKeyOutsideTheSchema(): void
    {
        // Las acciones con nombre de instancia leían claves como `hwe_example_copy`. Ya no:
        // lo que no cuelga de `hwe_config` no llega a nadie.
        $captured = null;
        Actions\expectDone('hwe_control_center_saved')->once()->whenHappen(function (...$args) use (&$captured): void {
            $captured = $args;
        });

        $this->save($this->completePost(['brand' => ['name' => 'T']], [
            'hwe_example_copy' => ['x' => 'secreto'],
            'otra_clave' => 'valor',
        ]));

        $this->assertStringNotContainsString('secreto', json_encode($captured, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('otra_clave', json_encode($captured, JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyPostIsRejectedAsIncomplete(): void
    {
        Actions\expectDone('hwe_control_center_saved')->never();

        $notice = $this->save(['hwe_nonce' => 'valido']);

        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('incompleto', $notice);
        $this->assertSame([], $this->events, 'Un POST vacío no guarda nada.');
    }

    public function testAPostMissingOneTabIsRejectedWithoutSaving(): void
    {
        Actions\expectDone('hwe_control_center_saved')->never();

        $post = $this->completePost(['brand' => ['name' => 'T']]);
        unset($post['hwe_config']['seo']);
        $notice = $this->save($post);

        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('seo', $notice);
        $this->assertSame([], $this->events);
    }

    public function testSavingMergesInsteadOfReplacing(): void
    {
        Actions\expectDone('hwe_control_center_saved')->once();
        $this->options['hwe_config'] = [
            'brand' => ['name' => 'Anterior'],
            'legado' => ['a' => 'b'],
        ];

        $this->save($this->completePost(['brand' => ['name' => 'Nueva']]));

        // merge(), no setAll(): lo saneado que queda vacío se omite y lo almacenado que el
        // saneado no menciona se conserva; setAll() lo borraría de wp_options.
        $this->assertSame('Nueva', $this->options['hwe_config']['brand']['name']);
        $this->assertSame(['a' => 'b'], $this->options['hwe_config']['legado']);
    }

    // ── La pestaña «Instancia» ───────────────────────────────────────────────

    public function testTheInstanceTabIsHiddenWhileItHasNoFields(): void
    {
        $tabs = AdminPage::buildTabs(
            ['brand' => ['label' => 'Marca', 'children' => ['x' => []]], 'instance' => ['label' => 'Instancia', 'children' => []]],
            ['brand' => '<p>marca</p>', 'instance' => '']
        );

        $this->assertSame(['brand'], array_keys($tabs));
    }

    public function testTheInstanceTabAppearsAsSoonAsTheInstanceAddsFields(): void
    {
        $tabs = AdminPage::buildTabs(
            [
                'brand' => ['label' => 'Marca', 'children' => ['x' => []]],
                'instance' => ['label' => 'Instancia', 'children' => ['example' => ['type' => 'group']]],
            ],
            ['brand' => '<p>marca</p>', 'instance' => '<p>mía</p>']
        );

        $this->assertSame(['brand', 'instance'], array_keys($tabs));
        $this->assertSame('Instancia', $tabs['instance']['label']);
        $this->assertSame('<p>mía</p>', $tabs['instance']['content']);
        $this->assertNotSame('', $tabs['instance']['icon']);
    }

    // ── La pestaña «Envío» (reservada al módulo `shipping`) ──────────────────

    public function testTheShippingTabIsHiddenWhileTheModuleHasNotAddedFields(): void
    {
        $tabs = AdminPage::buildTabs(
            ['brand' => ['label' => 'Marca', 'children' => ['x' => []]], 'shipping' => ['label' => 'Envío', 'children' => []]],
            ['brand' => '<p>marca</p>', 'shipping' => '']
        );

        $this->assertSame(['brand'], array_keys($tabs));
    }

    public function testTheShippingTabAppearsWhenAModuleAddsFields(): void
    {
        $tabs = AdminPage::buildTabs(
            ['shipping' => ['label' => 'Envío', 'children' => ['zone' => ['type' => 'text']]]],
            ['shipping' => '<p>envío</p>']
        );

        $this->assertSame(['shipping'], array_keys($tabs));
        $this->assertSame('dashicons-store', $tabs['shipping']['icon']);
    }
}
