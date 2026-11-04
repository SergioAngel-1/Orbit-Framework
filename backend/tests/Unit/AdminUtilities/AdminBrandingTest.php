<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\AdminUtilities;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Marca de HWE Orbit en el panel: esquema de color por defecto y login.
 *
 * Cada test en su proceso: el archivo define funciones y una constante globales.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminBrandingTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\stubs([
            'esc_html' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_html__' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'content_url' => static fn ($path = '') => 'https://cms.example.test/wp-content/' . ltrim((string) $path, '/'),
            'esc_url' => static fn ($v) => str_replace('&', '&#038;', (string) $v),
            'esc_url_raw' => static fn ($v) => (string) $v,
            'wp_get_upload_dir' => static fn () => ['baseurl' => 'https://media.example.test/wp-content/uploads'],
            'home_url' => static fn ($p = '') => 'https://cms.example.test' . $p,
            'get_bloginfo' => static fn ($k) => 'name' === $k ? 'HWE-Orbit' : '',
        ]);
        $this->loadOwnFile('mu-plugins/hwe-admin-branding.php');
    }

    public function testOrbitReplacesOnlyTheWordPressDefaultScheme(): void
    {
        $this->assertSame('hwe-orbit', hwe_admin_branding_default_scheme('fresh'));
        $this->assertSame('hwe-orbit', hwe_admin_branding_default_scheme(''));
        $this->assertSame('hwe-orbit', hwe_admin_branding_default_scheme(false));
        $this->assertSame('midnight', hwe_admin_branding_default_scheme('midnight'), 'Una elección propia se respeta.');
    }

    public function testRegistersTheSchemeWithItsCompiledStylesheet(): void
    {
        Functions\expect('wp_admin_css_color')
            ->once()
            ->with(
                'hwe-orbit',
                'HWE Orbit',
                'https://cms.example.test/wp-content/mu-plugins/hwe-admin-branding/colors.css',
                \Mockery::type('array'),
                \Mockery::type('array')
            );

        hwe_admin_branding_register_scheme();
        $this->assertFileExists(dirname(__DIR__, 3) . '/wp-content/mu-plugins/hwe-admin-branding/colors.css');
    }

    public function testLoginUsesTheOrbitSymbolAndTheSiteName(): void
    {
        Functions\expect('wp_add_inline_style')
            ->once()
            ->with('login', \Mockery::on(static fn ($css) => str_contains($css, 'hwe-admin-branding/orbit-symbol.svg')
                && str_contains($css, '#f2c200')));

        hwe_admin_branding_login_enqueue();
        $this->assertSame('https://cms.example.test/', hwe_admin_branding_login_header_url('https://wordpress.org/'));
        $this->assertSame('HWE-Orbit', hwe_admin_branding_login_header_text('Powered by WordPress'));
    }

    public function testAnInstanceCanTurnTheBrandingOff(): void
    {
        Filters\expectApplied('hwe_admin_branding')->andReturn(false);
        Functions\expect('wp_admin_css_color')->never();
        Functions\expect('wp_add_inline_style')->never();

        hwe_admin_branding_register_scheme();
        hwe_admin_branding_login_enqueue();
        $this->assertSame('fresh', hwe_admin_branding_default_scheme('fresh'));
        $this->assertSame('https://wordpress.org/', hwe_admin_branding_login_header_url('https://wordpress.org/'));
    }

    private function loginCss(): string
    {
        $css = null;
        Functions\expect('wp_add_inline_style')->once()->andReturnUsing(static function ($handle, $style) use (&$css) {
            $css = $style;
            return true;
        });
        hwe_admin_branding_login_enqueue();
        return (string) $css;
    }

    private function credit(): string
    {
        ob_start();
        hwe_admin_branding_login_credit();
        return (string) ob_get_clean();
    }

    public function testTheSchemaDefaultNameKeepsTheOrbitLogin(): void
    {
        // `wp hwe setup` guarda los valores por defecto: el nombre de serie no es una marca.
        $this->options['hwe_config'] = ['brand' => ['name' => 'HWE-Orbit', 'logo' => ''], 'design' => ['colors' => ['brand' => '#16679d']]];

        $css = $this->loginCss();

        $this->assertStringContainsString('body.login { background: #f2c200; }', $css);
        $this->assertStringContainsString('hwe-admin-branding/orbit-symbol.svg', $css);
        $this->assertSame('', $this->credit());
        $this->assertSame('HWE-Orbit', hwe_admin_branding_login_header_text('Powered by WordPress'));
    }

    public function testTheLoginUsesTheInstanceNameAndColorsAndCreditsOrbitSmall(): void
    {
        $this->options['hwe_config'] = [
            'brand' => ['name' => 'Tienda <Sol> & Co', 'logo' => ''],
            'design' => ['colors' => [
                'brand' => '#16679D', 'brand_dark' => '#0F172A', 'secondary' => '#0F766E',
                'surface' => '#F8FAFC', 'background' => '#FFFFFF', 'foreground' => '#0F172A',
            ]],
        ];

        $css = $this->loginCss();

        $this->assertStringNotContainsString('#f2c200', $css);
        $this->assertStringContainsString('body.login { background: #f8fafc; color: #0f172a; }', $css);
        $this->assertStringContainsString('background: #16679d;', $css, 'Botón en el color de marca.');
        $this->assertStringContainsString('color: #ffffff;', $css, 'Texto blanco sobre #16679d (5,9:1).');
        $this->assertStringContainsString('outline: 2px solid #16679d;', $css, 'Foco visible en la marca, que llega a 3:1.');
        $this->assertStringContainsString('text-indent: 0;', $css, 'Sin logo, el nombre se ve como texto.');
        $this->assertSame('Tienda &lt;Sol&gt; &amp; Co', hwe_admin_branding_login_header_text('Powered by WordPress'), 'wp-login.php lo imprime sin escapar.');

        $credit = $this->credit();
        $this->assertStringContainsString('Con HWE Orbit', $credit);
        $this->assertStringContainsString('aria-hidden="true"', $credit);
        $this->assertStringContainsString('hwe-admin-branding/orbit-symbol.svg', $css, 'El símbolo de Orbit queda solo en el crédito.');
    }

    public function testTheInstanceLogoReplacesTheSymbol(): void
    {
        $this->options['hwe_config'] = ['brand' => ['name' => '', 'logo' => '/wp-content/uploads/2026/11/logo.svg']];

        $css = $this->loginCss();

        $this->assertStringContainsString('url("https://media.example.test/wp-content/uploads/2026/11/logo.svg")', $css, 'Sobre la URL de uploads, no sobre home_url().');
        $this->assertStringContainsString('background-size: contain;', $css);
        $this->assertSame('HWE-Orbit', hwe_admin_branding_login_header_text(''), 'Sin nombre propio, el del sitio.');
    }

    public function testUnreadableOrInvalidColorsFallBackToAccessibleOnes(): void
    {
        $this->options['hwe_config'] = [
            'brand' => ['name' => 'Amarillo'],
            'design' => ['colors' => [
                'brand' => '#f2c200', 'brand_dark' => '#f2c200', 'secondary' => '#fff200',
                'surface' => 'red;}body{display:none', 'background' => '#ffffff', 'foreground' => '#cccccc',
            ]],
        ];

        $css = $this->loginCss();

        $this->assertStringNotContainsString('display:none', $css, 'Un color que no es hex cae al del esquema.');
        $this->assertStringContainsString('body.login { background: #e9e9e6; color: #111111; }', $css, 'Texto gris claro sobre blanco no llega a 4,5:1: tinta.');
        $this->assertStringContainsString('color: #111111;', $css, 'Tinta sobre el botón amarillo.');
        $this->assertStringContainsString('outline: 2px solid #111111;', $css, 'Ni la marca ni el secundario llegan a 3:1: el foco va en el texto.');
    }

    public function testContrastFollowsWcag(): void
    {
        $this->assertEqualsWithDelta(21.0, hwe_admin_branding_contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, hwe_admin_branding_contrast('#16679d', '#16679d'), 0.01);
        $this->assertSame('#16679d', hwe_admin_branding_readable('#16679d', '#ffffff'));
        $this->assertSame('#ffffff', hwe_admin_branding_readable('#777777', '#111111'));
    }

    public function testAnAbsoluteLogoKeepsItsQueryString(): void
    {
        $this->options['hwe_config'] = ['brand' => ['name' => 'Sol', 'logo' => 'https://cdn.example.test/logo.png?v=2&w=240']];

        $css = $this->loginCss();

        $this->assertStringContainsString('url("https://cdn.example.test/logo.png?v=2&w=240")', $css, 'Sin &#038; dentro de la hoja de estilos.');
    }

    public function testADarkPaletteKeepsNoticesAndFocusReadable(): void
    {
        $this->options['hwe_config'] = [
            'brand' => ['name' => 'Noche'],
            'design' => ['colors' => [
                'brand' => '#1e293b', 'brand_dark' => '#0f172a', 'secondary' => '#334155',
                'surface' => '#0b1220', 'background' => '#111827', 'foreground' => '#e5e7eb',
            ]],
        ];

        $css = $this->loginCss();

        $this->assertStringContainsString('body.login { background: #0b1220; color: #e5e7eb; }', $css);
        $this->assertStringContainsString('.login .message, .login .notice, .login .success { background: #111827; color: #e5e7eb;', $css, 'Los avisos no quedan con texto claro sobre el blanco del core.');
        $this->assertStringContainsString('outline: 2px solid #e5e7eb;', $css, 'Ni la marca ni el secundario llegan a 3:1 en oscuro: el foco va en el texto.');
        $this->assertGreaterThan(
            strpos($css, 'border-left-color: #1e293b;'),
            strpos($css, '.login .notice-error { border-left-color: #cc1818; }'),
            'El rojo de error del core va después de la regla de marca.'
        );
        $this->assertStringContainsString('.login .success { border-left-color: #4ab866; }', $css);
    }

    public function testTheFocusRingFallbackClearsBothBackgrounds(): void
    {
        // Texto oscuro legible sobre el formulario blanco, pero no sobre una página negra.
        $ring = hwe_admin_branding_focus_ring(['#ffff00', '#111111'], '#000000', '#ffffff');

        $this->assertSame('#767676', $ring);
        $this->assertGreaterThanOrEqual(3, hwe_admin_branding_contrast($ring, '#000000'));
        $this->assertGreaterThanOrEqual(3, hwe_admin_branding_contrast($ring, '#ffffff'));
    }

    public function testTheSiteNameFallbackIsEscapedToo(): void
    {
        Functions\when('get_bloginfo')->justReturn('A & B <i>');

        $this->assertSame('A &amp; B &lt;i&gt;', hwe_admin_branding_login_header_text(''));
    }
}
