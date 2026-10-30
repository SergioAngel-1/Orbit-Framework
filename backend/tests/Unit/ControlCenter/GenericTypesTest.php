<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\FieldTypes\RichTextType;
use HWE\ControlCenter\Walkers\AdminFormWalker;
use HWE\Tests\TestCase;

/**
 * Los tipos genéricos nuevos (`image`, `file`, `number`, `richtext`, `link`, `slug`,
 * `lines`, `pairs`): el render y el saneado de cada tipo, más el CSS/JS que necesitan
 * (`hwe-media`, `hwe-rows-editor`). `richtext` sanea con `wp_kses` y una lista conservadora.
 */
final class GenericTypesTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];
    /** @var list<array{string, array}> */
    private array $ksesCalls = [];
    /** @var array<string,mixed> */
    private array $editorSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $v)), '-'),
            'esc_url_raw' => static fn ($v) => (string) $v,
            'esc_url' => static fn ($v) => (string) $v,
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            'esc_textarea' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
            'wp_parse_url' => static fn ($url, $component = -1) => parse_url((string) $url, $component),
        ]);
        Functions\when('wp_kses')->alias(function (string $string, array $allowed): string {
            $this->ksesCalls[] = [$string, $allowed];
            return $string;
        });
        Functions\when('wp_editor')->alias(function (string $content, string $id, array $settings = []): void {
            $this->editorSettings = $settings;
            echo '<textarea name="' . ($settings['textarea_name'] ?? '') . '">' . $content . '</textarea>';
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    public function testTheCoreRegistersAllSixteenTypes(): void {
        $types = Registry::all();

        foreach (['text', 'textarea', 'email', 'url', 'color', 'boolean', 'select', 'secret', 'image', 'file', 'number', 'richtext', 'link', 'slug', 'lines', 'pairs'] as $id) {
            $this->assertArrayHasKey($id, $types, "Falta el tipo «{$id}» en el registro.");
            $this->assertSame($id, $types[$id]->id());
        }
        $this->assertSame([], $this->logged, 'Sin filtros, el registro no genera avisos.');
    }

    // ── image / file ────────────────────────────────────────────────────────

    public function testImageSanitizeMakesUploadsUrlsRelative(): void {
        $type = Registry::get('image');

        $this->assertSame(
            '/wp-content/uploads/2024/foto.jpg',
            $type->sanitize([], 'http://localhost:8080/wp-content/uploads/2024/foto.jpg')
        );
        $this->assertSame(
            '/wp-content/uploads/d.pdf?a=1#x',
            $type->sanitize([], 'https://cdn.example/wp-content/uploads/d.pdf?a=1#x')
        );
        $this->assertSame('https://cdn.example/foto.jpg', $type->sanitize([], 'https://cdn.example/foto.jpg'));
        $this->assertSame('', $type->sanitize([], ''));
        $this->assertSame('config', $type->persistence());
    }

    public function testFileSanitizesLikeImage(): void {
        $type = Registry::get('file');

        $this->assertSame(
            '/wp-content/uploads/doc.pdf',
            $type->sanitize([], 'http://localhost:8080/wp-content/uploads/doc.pdf')
        );
    }

    public function testImageRendersAPickerWithPreview(): void {
        $html = Registry::get('image')->render([], 'id', 'hwe_config[g][img]', '/wp-content/uploads/f.jpg', ['g', 'img']);

        $this->assertStringContainsString('hwe-media', $html);
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('<img src="/wp-content/uploads/f.jpg"', $html);
    }

    public function testFileRendersAcceptAndFilenamePreview(): void {
        $html = Registry::get('file')->render(
            ['accept' => 'pdf,zip'], 'id', 'name', 'https://cdn.example/a/doc.pdf', ['g', 'f']
        );

        $this->assertStringContainsString('data-accept="pdf,zip"', $html);
        $this->assertStringContainsString('<code>doc.pdf</code>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    // ── number ──────────────────────────────────────────────────────────────

    public function testNumberClampsAndFallsBackToDefault(): void {
        $type  = Registry::get('number');
        $field = ['min' => 1, 'max' => 10, 'default' => '5'];

        $this->assertSame('7', $type->sanitize($field, '7'));
        $this->assertSame('10', $type->sanitize($field, '99'));
        $this->assertSame('1', $type->sanitize($field, '-3'));
        $this->assertSame('5', $type->sanitize($field, 'no-numérico'));
        $this->assertSame('5', $type->sanitize($field, null));
        $this->assertSame('', $type->sanitize([], 'tampoco'));
    }

    public function testNumberRendersMinAndMax(): void {
        $html = Registry::get('number')->render(['min' => 1, 'max' => 10], 'id', 'name', '3', []);

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringContainsString('min="1"', $html);
        $this->assertStringContainsString('max="10"', $html);
    }

    // ── richtext ────────────────────────────────────────────────────────────

    public function testRichtextSanitizesWithAConservativeAllowlist(): void {
        $type = Registry::get('richtext');

        $this->assertSame('<p>Hola</p>', $type->sanitize([], '<p>Hola</p>'));
        $this->assertCount(1, $this->ksesCalls);
        [$input, $allowed] = $this->ksesCalls[0];

        $this->assertSame('<p>Hola</p>', $input);
        $this->assertSame(array_keys(RichTextType::ALLOWED_HTML), array_keys($allowed));
        foreach (['script', 'iframe', 'form', 'h1', 'img', 'div'] as $tag) {
            $this->assertArrayNotHasKey($tag, $allowed, "«{$tag}» no entra en la lista conservadora.");
        }
        $this->assertArrayHasKey('href', $allowed['a']);
    }

    public function testRichtextAllowsTablesWithStructuralAttributesOnly(): void {
        $allowed = RichTextType::ALLOWED_HTML;

        foreach (['table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'] as $tag) {
            $this->assertArrayHasKey($tag, $allowed, "«{$tag}» debe poder guardarse.");
        }
        $this->assertSame(['scope', 'colspan', 'rowspan'], array_keys($allowed['th']));
        $this->assertSame(['colspan', 'rowspan'], array_keys($allowed['td']));
        // Nada de estilos ni manejadores: `wp_kses` quita todo atributo que no esté en la
        // lista, y una tabla no necesita más que su estructura.
        foreach (['table', 'caption', 'thead', 'tbody', 'tfoot', 'tr'] as $tag) {
            $this->assertSame([], $allowed[$tag]);
        }
    }

    public function testRichtextRendersWithoutTinyMCE(): void {
        $html = Registry::get('richtext')->render([], 'hwe_config_g_txt', 'hwe_config[g][txt]', '<p>x</p>', []);

        $this->assertStringContainsString('name="hwe_config[g][txt]"', $html);
        $this->assertFalse($this->editorSettings['tinymce']);
        $this->assertFalse($this->editorSettings['media_buttons']);
    }

    // ── link ────────────────────────────────────────────────────────────────

    public function testLinkRendersAButtonAndPersistsNothing(): void {
        $type = Registry::get('link');

        $html = $type->render(['url' => 'https://example.com/x', 'button_label' => 'Abrir X'], 'id', 'name', null, []);
        $this->assertStringContainsString('href="https://example.com/x"', $html);
        $this->assertStringContainsString('Abrir X', $html);

        $this->assertStringContainsString('—', $type->render([], 'id', 'name', null, []));
        $this->assertSame('', $type->sanitize([], ['cualquier' => 'cosa']));
    }

    // ── slug ────────────────────────────────────────────────────────────────

    public function testSlugKeepsOnlyUrlSafeCharacters(): void {
        $type = Registry::get('slug');

        $this->assertSame('mi-ruta', $type->sanitize([], 'Mi Ruta'));
        $this->assertSame('a-b', $type->sanitize([], 'a/b'));
    }

    // ── lines / pairs ───────────────────────────────────────────────────────

    public function testLinesRendersOneRowPerEntry(): void {
        $html = Registry::get('lines')->render(
            ['placeholder' => 'Una por línea'], 'id', 'hwe_config[g][l]', "uno\ndos", ['g', 'l']
        );

        $this->assertStringContainsString('data-kind="lines"', $html);
        $this->assertStringContainsString('name="hwe_config[g][l]"', $html);
        $list = (string) preg_replace('/.*<ol class="hwe-rows-editor-rows">/s', '', $html);
        $list = (string) preg_replace('/<\/ol>.*/s', '', $list);
        $this->assertSame(2, substr_count($list, '<li class="hwe-rows-editor-row">'), 'Una fila por línea no vacía.');
    }

    public function testPairsRendersValueAndLabelInputs(): void {
        $html = Registry::get('pairs')->render([], 'id', 'name', "120 horas | de formación\nsolo-valor", []);

        $this->assertStringContainsString('data-kind="pairs"', $html);
        $this->assertStringContainsString('value="120 horas"', $html);
        $this->assertStringContainsString('value="de formación"', $html);
    }

    public function testLinesAndPairsPersistTheMultilineString(): void {
        $this->assertSame("a\nb", Registry::get('lines')->sanitize([], "a\nb"));
        $this->assertSame('a | b', Registry::get('pairs')->sanitize([], 'a | b'));
    }

    // ── assets y walker ─────────────────────────────────────────────────────

    public function testAssetsContainWhatTheNewTypesNeed(): void {
        $css = (string) file_get_contents(HWE_WP_CONTENT_DIR . '/mu-plugins/hwe-control-center/assets/admin.css');
        $js  = (string) file_get_contents(HWE_WP_CONTENT_DIR . '/mu-plugins/hwe-control-center/assets/admin.js');

        $this->assertStringContainsString('.hwe-media', $css);
        $this->assertStringContainsString('.hwe-rows-editor', $css);
        $this->assertStringContainsString('initMediaPickers', $js);
        $this->assertStringContainsString('initRowsEditors', $js);
    }

    public function testTheFormWalkerRendersTheNewTypes(): void {
        $sections = (new AdminFormWalker())->walk([
            'g' => [
                'type' => 'group',
                'label' => 'G',
                'children' => [
                    'img' => ['type' => 'image', 'label' => 'Imagen', 'default' => ''],
                    'n' => ['type' => 'number', 'label' => 'N', 'default' => '1'],
                    'l' => ['type' => 'lines', 'label' => 'Líneas', 'default' => ''],
                    's' => ['type' => 'slug', 'label' => 'Slug', 'default' => ''],
                ],
            ],
        ], []);

        $this->assertStringContainsString('hwe-media', $sections['g']);
        $this->assertStringContainsString('type="number"', $sections['g']);
        $this->assertStringContainsString('data-kind="lines"', $sections['g']);
    }
}
