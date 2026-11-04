<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminSkin;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La marca de la tienda en el panel: el color de la instancia solo llega al panel si pasa AA
 * contra su fondo (si no, cae a tinta o blanco), y sin marca propia se usa la reserva de Orbit.
 *
 * Cada test en su proceso: `hwe-admin-branding.php` define funciones globales.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminSkinTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('error_log')->justReturn(true);
        Functions\stubs([
            'content_url' => static fn ($path = '') => 'https://cms.example.test/wp-content/' . ltrim((string) $path, '/'),
            'wp_get_upload_dir' => static fn () => ['baseurl' => 'https://media.example.test/wp-content/uploads'],
            'home_url' => static fn ($p = '') => 'https://cms.example.test' . $p,
            'get_bloginfo' => static fn ($k) => 'name' === $k ? 'HWE-Orbit' : '',
        ]);
    }

    private function loadBranding(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-admin-branding.php');
    }

    /** @return array<string,string> variable → valor del CSS en línea */
    private function vars(): array
    {
        $css = AdminSkin::css();
        $this->assertStringStartsWith('.hwe-wrap{', $css);
        preg_match_all('/(--hwe-[a-z-]+):(#[0-9a-f]{6});/', $css, $m, PREG_SET_ORDER);
        $vars = [];
        foreach ($m as $row) {
            $vars[$row[1]] = $row[2];
        }
        return $vars;
    }

    public function testWithoutAnInstanceBrandItUsesTheOrbitReserve(): void
    {
        $this->loadBranding();

        $identity = AdminSkin::identity();
        $this->assertFalse($identity['branded']);
        $this->assertSame('HWE Orbit', $identity['name']);
        $this->assertStringEndsWith('hwe-admin-branding/orbit-symbol.svg', $identity['logo']);

        $vars = $this->vars();
        $this->assertSame('#f2c200', $vars['--hwe-accent']);
        $this->assertSame('#111111', $vars['--hwe-on-accent'], 'Tinta sobre cadmio: el blanco no llega a 4,5:1.');
        $this->assertSame('#111111', $vars['--hwe-accent-ink'], 'El cadmio no se puede usar como texto o borde sobre papel.');
        $this->assertSame('#111111', $vars['--hwe-ring'], 'El anillo de foco necesita 3:1 contra la página y el papel.');
    }

    public function testTheInstanceBrandColorIsUsedWhenItIsReadable(): void
    {
        $this->options['hwe_config'] = ['brand' => ['name' => 'Sol', 'logo' => 'https://cdn.example.test/sol.svg'], 'design' => ['colors' => ['brand' => '#16679d', 'brand_dark' => '#0e4a74']]];
        $this->loadBranding();

        $this->assertSame(['name' => 'Sol', 'logo' => 'https://cdn.example.test/sol.svg', 'branded' => true], AdminSkin::identity());
        $vars = $this->vars();
        $this->assertSame('#16679d', $vars['--hwe-accent']);
        $this->assertSame('#0e4a74', $vars['--hwe-accent-hover']);
        $this->assertSame('#ffffff', $vars['--hwe-on-accent']);
        $this->assertSame('#16679d', $vars['--hwe-accent-ink']);
        $this->assertSame('#16679d', $vars['--hwe-ring']);
    }

    /**
     * Un comerciante puede elegir cualquier color: texto, borde y foco de TODA marca pasan su umbral.
     *
     * @return array<string,array{string}>
     */
    public static function brandColors(): array
    {
        return [
            'amarillo pálido' => ['#fff3a0'],
            'blanco' => ['#ffffff'],
            'negro' => ['#000000'],
            'gris medio' => ['#808080'],
            'azul' => ['#16679d'],
            'cadmio' => ['#f2c200'],
            'rosa chicle' => ['#ff9ec4'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brandColors')]
    public function testEveryBrandColorKeepsItsContrastFloors(string $color): void
    {
        $this->options['hwe_config'] = ['brand' => ['name' => 'Marca', 'logo' => ''], 'design' => ['colors' => ['brand' => $color, 'brand_dark' => $color, 'secondary' => $color]]];
        $this->loadBranding();

        $v = $this->vars();
        $this->assertGreaterThanOrEqual(4.5, hwe_admin_branding_contrast($v['--hwe-on-accent'], $v['--hwe-accent']), 'Texto del botón sobre el acento.');
        $this->assertGreaterThanOrEqual(4.5, hwe_admin_branding_contrast($v['--hwe-on-accent-hover'], $v['--hwe-accent-hover']), 'Texto del botón en hover.');
        $this->assertGreaterThanOrEqual(4.5, hwe_admin_branding_contrast($v['--hwe-accent-ink'], '#ffffff'), 'Marca como texto sobre papel.');
        $this->assertGreaterThanOrEqual(3.0, hwe_admin_branding_contrast($v['--hwe-ring'], '#ffffff'), 'Anillo de foco sobre papel.');
        $this->assertGreaterThanOrEqual(3.0, hwe_admin_branding_contrast($v['--hwe-ring'], '#e9e9e6'), 'Anillo de foco sobre la página.');
    }

    public function testAnInstanceCanTurnTheBrandingOff(): void
    {
        Filters\expectApplied('hwe_admin_branding')->andReturn(false);
        $this->loadBranding();

        $this->assertSame('', AdminSkin::css(), 'Sin marca, el CSS usa el acento de wp-admin.');
        $this->assertSame('HWE Orbit', AdminSkin::identity()['name']);
    }

    public function testWithoutTheBrandingPluginThePanelStillRenders(): void
    {
        $this->assertSame('', AdminSkin::css());
        $this->assertSame(['name' => 'HWE Orbit', 'logo' => '', 'branded' => false], AdminSkin::identity());
    }
}
