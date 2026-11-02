<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\AdminUtilities;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Las URL de la medioteca conservan el esquema del sitio en las peticiones del BFF
 * (`hwe-media-scheme.php`).
 *
 * `wcFetch` manda `X-Forwarded-Proto: https` por la red interna HTTP porque WooCommerce
 * solo acepta las credenciales ck/cs con `is_ssl()`. Con esa cabecera, WordPress pasaba
 * las URL de los adjuntos a `https://` aunque el sitio fuera `http://`, y `next/image`
 * las rechazaba en local (búsqueda, colecciones, recientes, lista de deseos…).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MediaSchemeTest extends TestCase
{
    private const SECRET = 'secreto-interno-de-prueba-0123456789';

    /** @var array<string,string> */
    private array $options = [
        'siteurl' => 'http://localhost:8080',
        'home' => 'http://localhost:8080',
    ];

    /** @var array<string,callable> */
    private array $filters = [];

    private bool $ssl = true;
    private bool $admin = false;

    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        parent::setUp();

        $_SERVER = [];
        Functions\stubs(['wp_unslash' => static fn ($value) => $value]);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('is_ssl')->alias(fn (): bool => $this->ssl);
        Functions\when('is_admin')->alias(fn (): bool => $this->admin);
        Functions\when('error_log')->justReturn(true);

        foreach (['wp_get_attachment_url', 'wp_calculate_image_srcset', 'woocommerce_placeholder_img_src'] as $hook) {
            Filters\expectAdded($hook)->once()->whenHappen(function (callable $callback) use ($hook): void {
                $this->filters[$hook] = $callback;
            });
        }

        $this->loadOwnFile('mu-plugins/hwe-media-scheme.php');
    }

    private function fromBff(): void
    {
        $_SERVER['HTTP_X_HWE_INTERNAL_SECRET'] = self::SECRET;
    }

    // ── La regla pura ──────────────────────────────────────────────────────

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function urls(): iterable
    {
        $local = ['http://localhost:8080'];
        yield 'https del propio sitio http → http' => [
            'https://localhost:8080/wp-content/uploads/2026/10/foto.webp', $local,
            'http://localhost:8080/wp-content/uploads/2026/10/foto.webp',
        ];
        yield 'host en mayúsculas' => [
            'https://LOCALHOST:8080/wp-content/uploads/a.png', $local, 'http://LOCALHOST:8080/wp-content/uploads/a.png',
        ];
        yield 'otro host: tal cual' => [
            'https://cdn.example.com/wp-content/uploads/a.png', $local, 'https://cdn.example.com/wp-content/uploads/a.png',
        ];
        yield 'otro puerto: tal cual' => [
            'https://localhost:8443/wp-content/uploads/a.png', $local, 'https://localhost:8443/wp-content/uploads/a.png',
        ];
        yield 'producción https: no cambia' => [
            'https://cms.example.com/wp-content/uploads/a.png', ['https://cms.example.com'],
            'https://cms.example.com/wp-content/uploads/a.png',
        ];
        yield 'producción https: un http no se sube' => [
            'http://cms.example.com/wp-content/uploads/a.png', ['https://cms.example.com'],
            'http://cms.example.com/wp-content/uploads/a.png',
        ];
        yield 'panel en su host: casa con siteurl' => [
            'https://admin.example.test/wp-content/uploads/a.png', ['http://admin.example.test', 'http://example.test'],
            'http://admin.example.test/wp-content/uploads/a.png',
        ];
        yield 'relativa: tal cual' => ['/wp-content/uploads/a.png', $local, '/wp-content/uploads/a.png'];
        yield 'origen vacío o roto' => ['https://localhost:8080/a.png', ['', 'no es una url'], 'https://localhost:8080/a.png'];
    }

    /** @param list<string> $origins */
    #[DataProvider('urls')]
    public function testRestoresTheSchemeOfTheSiteOnlyForItsOwnHost(string $url, array $origins, string $expected): void
    {
        $this->assertSame($expected, hwe_media_scheme_restore($url, $origins));
    }

    // ── Los filtros ────────────────────────────────────────────────────────

    public function testAnAttachmentUrlRequestedByTheBffKeepsTheSchemeOfTheSite(): void
    {
        $this->fromBff();

        $this->assertSame(
            'http://localhost:8080/wp-content/uploads/a.png',
            ($this->filters['wp_get_attachment_url'])('https://localhost:8080/wp-content/uploads/a.png'),
        );
        $this->assertSame(
            'http://localhost:8080/wp-content/uploads/woocommerce-placeholder.webp',
            ($this->filters['woocommerce_placeholder_img_src'])('https://localhost:8080/wp-content/uploads/woocommerce-placeholder.webp'),
        );
    }

    public function testTheSrcsetFollowsTheSameRule(): void
    {
        $this->fromBff();

        $sources = ($this->filters['wp_calculate_image_srcset'])([
            300 => ['url' => 'https://localhost:8080/wp-content/uploads/a-300x300.png', 'descriptor' => 'w', 'value' => 300],
            768 => ['url' => 'https://cdn.example.com/a-768x768.png', 'descriptor' => 'w', 'value' => 768],
        ]);

        $this->assertSame('http://localhost:8080/wp-content/uploads/a-300x300.png', $sources[300]['url']);
        $this->assertSame('https://cdn.example.com/a-768x768.png', $sources[768]['url']);
        $this->assertFalse(($this->filters['wp_calculate_image_srcset'])(false));
    }

    public function testWithoutTheBffSecretNothingChanges(): void
    {
        // Un navegador en un panel servido por https con `home` http (staging mal configurado):
        // bajarle la medioteca a http le daría contenido mixto en el editor.
        $_SERVER['HTTP_X_HWE_INTERNAL_SECRET'] = 'otro';

        $this->assertSame(
            'https://localhost:8080/wp-content/uploads/a.png',
            ($this->filters['wp_get_attachment_url'])('https://localhost:8080/wp-content/uploads/a.png'),
        );
    }

    public function testInTheAdminOrOverPlainHttpNothingChanges(): void
    {
        $this->fromBff();
        $this->admin = true;
        $this->assertSame('https://localhost:8080/a.png', ($this->filters['wp_get_attachment_url'])('https://localhost:8080/a.png'));

        $this->admin = false;
        $this->ssl = false;
        $this->assertSame('https://localhost:8080/a.png', ($this->filters['wp_get_attachment_url'])('https://localhost:8080/a.png'));
    }

    public function testANonStringValueIsLeftAlone(): void
    {
        $this->fromBff();

        $this->assertFalse(($this->filters['wp_get_attachment_url'])(false));
    }
}
