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
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'content_url' => static fn ($path = '') => 'https://cms.example.test/wp-content/' . ltrim((string) $path, '/'),
            'esc_url' => static fn ($v) => (string) $v,
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
}
