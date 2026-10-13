<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\AdminUtilities;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Saneado de SVG subidos (`hwe-svg-uploads.php`).
 *
 * Un SVG se sirve desde el dominio de WordPress: un script que sobreviva es XSS contra
 * quien lo abra (también un administrador). El saneado usa lista de PERMITIDOS para los
 * enlaces y cubre los bypass clásicos de una lista de prohibidos: esquemas con espacios
 * o tabuladores dentro, animaciones SMIL que reescriben `href`, entidades.
 */
final class SvgUploadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('__')->returnArg();
        $this->loadOwnFile('mu-plugins/hwe-svg-uploads.php');
    }

    private function clean(string $inner, string $rootAttrs = ''): string
    {
        $out = hwe_svg_sanitize_string('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"' . $rootAttrs . '>' . $inner . '</svg>');
        $this->assertNotNull($out);
        return (string) $out;
    }

    /** @return iterable<string, array{string, string}> */
    public static function dangerous(): iterable {
        yield 'script' => ['<script>alert(1)</script>', 'alert'];
        yield 'manejador onload' => ['<circle onload="alert(1)" r="1"/>', 'onload'];
        yield 'href javascript' => ['<a href="javascript:alert(1)"><text>x</text></a>', 'javascript'];
        yield 'xlink:href javascript' => ['<a xlink:href="javascript:alert(1)"><text>x</text></a>', 'javascript'];
        yield 'esquema con tabulador' => ['<a href="java&#x09;script:alert(1)"><text>x</text></a>', 'script:'];
        yield 'esquema con salto de línea' => ['<a href=" java&#10;script:alert(1)"><text>x</text></a>', 'script:'];
        yield 'set que reescribe href' => ['<a href="#ok"><set attributeName="href" to="javascript:alert(1)"/><text>x</text></a>', 'javascript'];
        yield 'animate que reescribe xlink:href' => ['<a xlink:href="#ok"><animate attributeName="xlink:href" values="javascript:alert(1)"/></a>', 'javascript'];
        yield 'foreignObject' => ['<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>', 'foreignObject'];
        yield 'svg anidado en data' => ['<image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="/>', 'data:image/svg'];
        yield 'style con javascript' => ['<rect style="fill:url(javascript:alert(1))"/>', 'javascript'];
        yield 'esquema desconocido' => ['<a href="vbscript:msgbox(1)"><text>x</text></a>', 'vbscript'];
    }

    #[DataProvider('dangerous')]
    public function testDangerousConstructsAreRemoved(string $inner, string $needle): void {
        $this->assertStringNotContainsStringIgnoringCase($needle, $this->clean($inner));
    }

    public function testSafeContentSurvives(): void {
        $out = $this->clean(
            '<a href="#seccion"><text>a</text></a><a href="/ruta?x=1"><text>b</text></a>'
            . '<a href="https://example.test/p"><text>c</text></a>'
            . '<image href="data:image/png;base64,iVBORw0KGgo="/>'
            . '<animate attributeName="opacity" values="0;1" dur="1s"/>'
            . '<circle r="4" fill="#ff0000"/>'
        );

        $this->assertStringContainsString('href="#seccion"', $out);
        $this->assertStringContainsString('href="/ruta?x=1"', $out);
        $this->assertStringContainsString('href="https://example.test/p"', $out);
        $this->assertStringContainsString('data:image/png', $out);
        $this->assertStringContainsString('attributeName="opacity"', $out, 'Una animación que no toca enlaces se conserva.');
        $this->assertStringContainsString('<circle', $out);
    }

    public function testEntitiesAndNonSvgDocumentsAreRejected(): void {
        $this->assertNull(hwe_svg_sanitize_string('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'));
        $this->assertNull(hwe_svg_sanitize_string('<html><body>no</body></html>'));
        $this->assertNull(hwe_svg_sanitize_string('no es xml'));
    }

    public function testDimensionsComeFromTheSvgWithAFallbackAndACap(): void {
        $file = tempnam(sys_get_temp_dir(), 'hwe-svg-');
        file_put_contents($file, '<svg viewBox="0 0 120 80"/>');
        $this->assertSame([120, 80], hwe_svg_read_dimensions($file));

        file_put_contents($file, '<svg width="99999" height="10"/>');
        $this->assertSame([4096, 10], hwe_svg_read_dimensions($file));

        file_put_contents($file, '<svg/>');
        $this->assertSame([512, 512], hwe_svg_read_dimensions($file));
        unlink($file);
    }
}
