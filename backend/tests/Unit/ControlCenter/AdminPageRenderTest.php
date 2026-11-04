<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminPage;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * El marco de la página «HWE Config»: navegación en tres capas, un panel por pestaña, barra de
 * guardado y formularios de acciones FUERA del formulario principal.
 *
 * Es una prueba de marcado, no visual: cómo se ve (colores, 390 y 1280 px) se mira en el navegador.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminPageRenderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hwe-page-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
        define('HWE_BACKUPS_DIR', $this->dir);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];

        Functions\stubs([
            'esc_attr' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_html' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_textarea' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_url' => static fn ($v) => (string) $v,
            'esc_html__' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_attr__' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_html_e' => static function ($v): void { echo htmlspecialchars((string) $v, ENT_QUOTES); },
            'esc_attr_e' => static function ($v): void { echo htmlspecialchars((string) $v, ENT_QUOTES); },
            '__' => static fn ($v) => $v,
            'wp_unslash' => static fn ($v) => $v,
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim($v) : '',
            'current_user_can' => static fn () => true,
            'admin_url' => static fn ($p = '') => 'https://tienda.example/wp-admin/' . ltrim((string) $p, '/'),
            'rest_url' => static fn ($p = '') => 'https://tienda.example/wp-json/' . ltrim((string) $p, '/'),
            'wp_create_nonce' => static fn ($a = '') => 'nonce-' . $a,
            'add_query_arg' => static fn (...$a) => 'https://tienda.example/wp-admin/admin-post.php?x=1',
            'size_format' => static fn ($v) => (string) $v . ' B',
            'wp_date' => static fn ($f, $t) => date((string) $f, (int) $t),
            'wp_get_current_user' => static fn () => (object) ['user_email' => 'dueno@tienda.example'],
            'is_plugin_active' => static fn () => true,
            'selected' => static fn () => '',
            'checked' => static fn () => '',
        ]);
        Functions\when('wp_nonce_field')->alias(static function (): void {
            echo '<input type="hidden" name="hwe_nonce" value="n">';
        });
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $default);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            is_file($path) && unlink($path);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function page(): string
    {
        ob_start();
        AdminPage::renderPage();
        return (string) ob_get_clean();
    }

    public function testTheNavigationHasTheThreeLayersAndTheModuleSubheading(): void
    {
        $html = $this->page();

        preg_match_all('/<h2 class="hwe-nav__layer">([^<]+)<\/h2>/', $html, $layers);
        $this->assertSame(['Negocio', 'Marca y visibilidad', 'Puesta en marcha'], $layers[1]);
        $this->assertStringContainsString('<h3 class="hwe-nav__sub">Módulos</h3>', $html);

        preg_match_all('/data-hwe-tab="([a-z]+)"/', $html, $tabs);
        $this->assertSame(
            ['store', 'payments', 'shipping', 'support', 'banners', 'brand', 'search', 'site', 'email', 'marketing', 'services', 'backups'],
            $tabs[1],
            'Sin módulos con campos ni campos de instancia solo salen las pestañas del núcleo (y Banners, que lleva su interruptor).'
        );
        $this->assertSame(count($tabs[1]), preg_match_all('/<section class="hwe-panel"/', $html), 'Un panel por pestaña.');
    }

    public function testOnlyTheOpenPanelIsVisibleAndTheNavMarksIt(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match_all('/aria-current="true"/', $html));
        $this->assertStringContainsString('data-hwe-tab="store" aria-current="true"', $html);
        $this->assertSame(11, preg_match_all('/<section class="hwe-panel"[^>]* hidden>/', $html));
    }

    public function testAnActionRedirectOpensItsOwnTab(): void
    {
        $_GET = ['hwe_smtp_test' => 'sent', 'hwe_smtp_test_tpl' => 'basic'];
        $html = $this->page();

        $this->assertStringContainsString('data-hwe-initial-tab="email"', $html);
        $this->assertStringContainsString('data-hwe-tab="email" aria-current="true"', $html);
        $this->assertStringContainsString('notice-success', $html);
    }

    public function testThereIsExactlyOnePrimaryButtonAndItSavesTheMainForm(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match_all('/button-primary/', $html));
        $this->assertStringContainsString('class="button button-primary hwe-savebar__btn"', $html);
        $this->assertStringContainsString('data-clean=', $html, 'La barra de guardado lleva sus textos para admin.js.');
    }

    public function testTheSaveBarIsTheLastChildOfTheMainColumnSoCssCanPlaceItBesideOrAboveTheFields(): void
    {
        $html = $this->page();

        $main = strpos($html, '<div class="hwe-main">');
        $bar = strpos($html, '<div class="hwe-savebar">');
        $layoutEnd = strpos($html, '</form>', $bar);
        $this->assertNotFalse($main);
        $this->assertGreaterThan($main, $bar, 'La barra va dentro de .hwe-main...');
        $this->assertSame(0, preg_match('/<section class="hwe-panel"/', substr($html, $bar, $layoutEnd - $bar)), '...tras todos los paneles (el CSS la sube con `order`).');
    }

    public function testEveryTitledSectionFoldsAndStartsOpenWhileNestedBlocksStartClosed(): void
    {
        $html = $this->page();

        preg_match_all('/<details class="(hwe-section[^"]*)" data-hwe-fold( open)?>/', $html, $sections, PREG_SET_ORDER);
        $this->assertGreaterThan(15, count($sections), 'Cada sección con título es un <details>.');
        foreach ($sections as $section) {
            $this->assertSame(' open', $section[2], 'Las secciones de primer nivel arrancan abiertas.');
        }
        $this->assertSame(
            count($sections),
            preg_match_all('/<summary class="hwe-section__head"><h3 id="hwe-panel-[a-z]+-s\d+">/', $html),
            'Su cabecera pulsable es el <summary> con el título.'
        );

        preg_match_all('/<details class="hwe-subgroup" data-hwe-fold([^>]*)>/', $html, $groups);
        $this->assertGreaterThan(8, count($groups[0]), 'Sedes y credenciales de pasarela son bloques anidados.');
        foreach ($groups[1] as $attrs) {
            $this->assertStringNotContainsString(' open', $attrs, 'Los subgrupos arrancan plegados.');
        }
        $this->assertStringNotContainsString('<h4 class="hwe-subgroup-title">', preg_replace('/<summary.*?<\/summary>/s', '', $html), 'El título de un bloque anidado vive en su <summary>.');
    }

    public function testFoldAllButtonSitsUnderSaveAndStartsHiddenUntilTheScriptRuns(): void
    {
        $html = $this->page();

        $save = strpos($html, 'class="button button-primary hwe-savebar__btn"');
        $fold = strpos($html, 'data-hwe-fold-all');
        $this->assertNotFalse($fold);
        $this->assertGreaterThan($save, $fold, 'Va debajo de «Guardar configuración».');
        $this->assertSame(1, preg_match_all('/data-hwe-fold-all/', $html), 'Uno solo para todo el panel: actúa sobre la pestaña activa.');
        $this->assertStringContainsString('data-open="Abrir todo" data-close="Colapsar todo" hidden>Abrir todo</button>', $html);
        $this->assertSame(1, preg_match_all('/button-primary/', $html), 'Sigue habiendo un único botón primario.');
    }

    public function testActionFormsLiveOutsideTheMainFormAndNeverNest(): void
    {
        $html = $this->page();

        $mainStart = strpos($html, '<form method="post" action="https://tienda.example/wp-admin/admin.php?page=hwe-config"');
        $mainEnd = strpos($html, '</form>', $mainStart);
        $this->assertNotFalse($mainStart);
        $inside = substr($html, $mainStart + 5, $mainEnd - $mainStart - 5);
        $this->assertStringNotContainsString('<form', $inside, 'HTML prohíbe <form> anidados.');

        foreach (['hwe-smtp-form', 'hwe-backup-run-now-form', 'hwe-config-import-form'] as $id) {
            $this->assertSame(1, substr_count($html, '<form id="' . $id . '"'));
            $this->assertGreaterThan($mainEnd, strpos($html, '<form id="' . $id . '"'));
            $this->assertStringContainsString('form="' . $id . '"', $inside, "Sus controles viven dentro del panel y apuntan a #{$id}.");
        }
    }

    public function testTheSmtpTestAndTheBackupActionsLiveInTheirTabs(): void
    {
        $html = $this->page();

        $email = $this->panel($html, 'email');
        $this->assertStringContainsString('Servidor de correo (SMTP)', $email);
        $this->assertStringContainsString('Probar entrega de email', $email);
        $this->assertStringContainsString('data-hwe-email-preview-modal', $html, 'La vista previa del correo sigue disponible junto a la prueba.');

        $backups = $this->panel($html, 'backups');
        $this->assertStringContainsString('Copia automática', $backups);
        $this->assertStringContainsString('Backup ahora', $backups);
        $this->assertStringContainsString('Exportar configuraci', $backups);

        $this->assertStringContainsString('Gestión de banners', $this->panel($html, 'banners'));
    }

    public function testPaymentCredentialsAreOneSectionWithAProviderBlockEach(): void
    {
        $payments = $this->panel($this->page(), 'payments');

        $this->assertStringContainsString('hwe-section hwe-section--provider', $payments);
        foreach (['avalpay', 'wompi', 'payu', 'bold'] as $provider) {
            $this->assertStringContainsString('data-provider="' . $provider . '"', $payments);
        }
    }

    public function testShippingAlwaysHasItsTabBecauseOfFreeShipping(): void
    {
        $shipping = $this->panel($this->page(), 'shipping');

        $this->assertStringContainsString('Envío gratis', $shipping);
        $this->assertStringContainsString('hwe_config[ecommerce][free_shipping][enabled]', $shipping);
    }

    private function panel(string $html, string $tab): string
    {
        $start = strpos($html, 'data-hwe-panel="' . $tab . '"');
        $this->assertNotFalse($start, "Existe el panel «{$tab}».");
        $end = strpos($html, '<section class="hwe-panel"', $start);
        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
