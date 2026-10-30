<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Email;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Correo transaccional de marca (tarea 38): `hwe-email-chrome.php`, `hwe-email-test.php`
 * y los correos de `hwe-auth.php`.
 *
 *  · El texto sale del diccionario por idioma, con reserva al de la tienda y al español.
 *  · Los enlaces van al FRONTEND, a las rutas del starter y con el prefijo del idioma;
 *    sin HEADLESS_FRONTEND_URL (el cron) se usa `brand.url`, nunca localhost.
 *  · La prueba de plantillas fuerza el destinatario y quita CC/BCC.
 *  · Los correos de acceso salen en el idioma del visitante.
 *
 * Cada test en su proceso: los archivos definen funciones globales y algunos tests
 * definen HEADLESS_FRONTEND_URL.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailChromeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'esc_html' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_attr' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_url' => static fn ($v) => (string) $v,
            'esc_url_raw' => static fn ($v) => (string) $v,
            'wp_specialchars_decode' => static fn ($v) => (string) $v,
            'home_url' => static fn ($path = '') => 'https://wp.example.test' . $path,
            'get_bloginfo' => static fn () => 'Tienda',
            '__' => static fn ($v) => $v,
        ]);
        // Como el de WordPress: NO codifica los valores (quien llama ya lo hizo).
        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            $pairs = [];
            foreach ($args as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            return $url . '?' . implode('&', $pairs);
        });
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('error_log')->justReturn(true);
        $this->loadOwnFile('mu-plugins/hwe-email-chrome.php');
    }

    private function config(array $config): void {
        $this->options['hwe_config'] = $config;
    }

    // ── diccionario ─────────────────────────────────────────────────────────

    public function testCopyUsesTheShopLocaleByDefaultAndTheRequestedOneWhenItIsASiteLocale(): void {
        $this->config(['brand' => ['locale' => 'es'], 'i18n' => ['locales' => 'en']]);

        $this->assertSame('Hola Ana,', hwe_email_copy('greeting_named', null, 'Ana'));
        $this->assertSame('Hi Ana,', hwe_email_copy('greeting_named', 'en', 'Ana'));
        $this->assertSame('Hola Ana,', hwe_email_copy('greeting_named', 'fr', 'Ana'), 'Un idioma que no es del sitio cae al de la tienda.');
    }

    public function testASiteLocaleWithoutDictionaryFallsBackToTheShopLocale(): void {
        $this->config(['brand' => ['locale' => 'en'], 'i18n' => ['locales' => 'fr']]);

        $this->assertSame('Hi,', hwe_email_copy('greeting', 'fr'));
    }

    public function testTheDictionaryIsExtensibleWithTheFilter(): void {
        $this->config(['brand' => ['locale' => 'es'], 'i18n' => ['locales' => 'en, fr']]);
        Filters\expectApplied('hwe_email_copy')->andReturnUsing(static function (array $d): array {
            $d['fr'] = ['greeting' => 'Bonjour,'];
            $d['es']['greeting'] = '¡Hola!';
            return $d;
        });

        $this->assertSame('Bonjour,', hwe_email_copy('greeting', 'fr'));
        $this->assertSame('¡Hola!', hwe_email_copy('greeting'));
        $this->assertSame('Hola Ana,', hwe_email_copy('greeting_named', 'fr', 'Ana'), 'Lo que el idioma nuevo no trae cae al de la tienda.');
    }

    // ── enlaces ─────────────────────────────────────────────────────────────

    public function testLinksGoToTheFrontendWithTheLocalePrefix(): void {
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example.test/');
        $this->config(['brand' => ['locale' => 'es'], 'i18n' => ['locales' => 'en']]);

        $this->assertSame('https://tienda.example.test/account', hwe_email_frontend_url('/account'));
        $this->assertSame('https://tienda.example.test/en/account', hwe_email_frontend_url('/account', [], 'en'));
        $this->assertSame('https://tienda.example.test/', hwe_email_frontend_url('/'));
        $this->assertSame('https://tienda.example.test/en', hwe_email_frontend_url('/', [], 'en'));
        $this->assertSame(
            'https://tienda.example.test/reset-password?key=a%20b&login=ana',
            hwe_email_frontend_url('/reset-password', ['key' => 'a b', 'login' => 'ana'])
        );
    }

    public function testWithoutTheFrontendConstantTheCronUsesTheBrandUrlNotLocalhost(): void {
        $this->config(['brand' => ['url' => 'https://tienda.example.test', 'locale' => 'es']]);
        Functions\when('hwe_allowed_origins')->justReturn(['http://localhost:3000']);

        $this->assertSame('https://tienda.example.test/account', hwe_email_frontend_url('/account'));
    }

    // ── marco ───────────────────────────────────────────────────────────────

    public function testColorsAndFontsFromTheControlCenterCannotBreakOutOfTheStyle(): void {
        $this->config([
            'design' => [
                'colors' => ['brand' => 'red;background:url(x)', 'accent' => '#123abc'],
                'typography' => ['font_sans' => "Inter'; } body { x: 1", 'font_heading' => ''],
            ],
        ]);

        $this->assertSame('#123abc', hwe_email_color('accent'));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', hwe_email_color('brand'));
        $this->assertStringNotContainsString(';', hwe_email_font_stack('body'));
        $this->assertStringStartsWith("'Inter  body  x 1'", hwe_email_font_stack('body'));
    }

    public function testANamedHeadingFontKeepsItsSerifFallbackByDefault(): void {
        $this->config(['design' => ['typography' => ['font_heading' => 'Playfair Display']]]);

        $this->assertSame(
            "'Playfair Display', Georgia, 'Times New Roman', serif",
            hwe_email_font_stack('heading')
        );
    }

    public function testAnEmptyHeadingFontUsesTheBodyStack(): void {
        $this->config(['design' => ['typography' => ['font_sans' => 'Inter', 'font_heading' => '']]]);

        $this->assertSame(hwe_email_font_stack('body'), hwe_email_font_stack('heading'));
        $this->assertStringStartsWith("'Inter', ", hwe_email_font_stack('heading'));
        $this->assertStringNotContainsString('Georgia', hwe_email_font_stack('heading'));
    }

    public function testAnInstanceCanChooseTheHeadingFallback(): void {
        $this->config(['design' => ['typography' => ['font_heading' => 'Unbounded']]]);
        Filters\expectApplied('hwe_email_heading_font_fallback')
            ->andReturn("'Segoe UI', Arial, sans-serif");

        $this->assertSame("'Unbounded', 'Segoe UI', Arial, sans-serif", hwe_email_font_stack('heading'));
    }

    public function testTheFilterDoesNotApplyToTheBodyNorToAnEmptyHeading(): void {
        $this->config(['design' => ['typography' => ['font_sans' => 'Inter', 'font_heading' => '']]]);
        Filters\expectApplied('hwe_email_heading_font_fallback')->never();

        $this->assertStringEndsWith('sans-serif', hwe_email_font_stack('body'));
        $this->assertStringEndsWith('sans-serif', hwe_email_font_stack('heading'));
    }

    /** @return array<string,array{mixed}> */
    public static function badFallbacks(): array {
        return [
            'rompe el style' => ['Arial; } body { x: 1'],
            'comillas dobles' => ['"Arial"'],
            'vacío' => [''],
            'no es texto' => [['Arial']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFallbacks')]
    public function testAnUnsafeHeadingFallbackIsDiscarded(mixed $value): void {
        $this->config(['design' => ['typography' => ['font_heading' => 'Unbounded']]]);
        Filters\expectApplied('hwe_email_heading_font_fallback')->andReturn($value);

        $this->assertSame("'Unbounded', Georgia, 'Times New Roman', serif", hwe_email_font_stack('heading'));
    }

    // ── prueba de plantillas ────────────────────────────────────────────────

    public function testTheTestSendForcesTheRecipientAndDropsCopies(): void {
        $this->loadOwnFile('mu-plugins/hwe-email-test.php');

        $filter = hwe_email_test_force_recipient('qa@example.test');
        $args = $filter([
            'to' => 'cliente-real@example.test',
            'headers' => ['Content-Type: text/html', 'Cc: otro@example.test', ' bcc: oculto@example.test', 'Reply-To: x@example.test'],
        ]);

        $this->assertSame('qa@example.test', $args['to']);
        $this->assertSame(['Content-Type: text/html', 'Reply-To: x@example.test'], $args['headers']);
    }

    // ── correos de acceso ───────────────────────────────────────────────────

    public function testAuthEmailsAreBrandedAndInTheVisitorLocale(): void {
        $this->config(['brand' => ['locale' => 'es', 'name' => 'Tienda'], 'i18n' => ['locales' => 'en']]);
        $this->options['blogname'] = 'Tienda';
        $this->loadOwnFile('mu-plugins/hwe-auth.php');

        $en = \HWE_Auth_Endpoints::buildEmail('reset', 'Ana', 'https://tienda.example.test/en/reset-password?key=k', 'en');
        $es = \HWE_Auth_Endpoints::buildEmail('verify', 'Ana', 'https://tienda.example.test/verify-email?token=t', 'xx');

        $this->assertSame('Tienda — Reset your password', $en['subject']);
        $this->assertStringContainsString('lang="en"', $en['body']);
        $this->assertStringContainsString('Hi Ana,', $en['body']);
        $this->assertStringContainsString('href="https://tienda.example.test/en/reset-password?key=k"', $en['body']);
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $en['headers']);

        $this->assertSame('Tienda — Verifica tu email', $es['subject'], 'Un idioma que no es del sitio cae al de la tienda.');
        $this->assertStringContainsString('lang="es"', $es['body']);
    }

    public function testRegisteringNoLongerSendsTheNativeLoginDetailsToTheCustomer(): void {
        $this->loadOwnFile('mu-plugins/hwe-auth.php');

        $this->assertFalse(has_action('register_new_user', 'wp_send_new_user_notifications'));
        $this->assertNotFalse(has_action('register_new_user', 'hwe_auth_notify_new_user_admin_only'));

        Functions\expect('wp_send_new_user_notifications')->once()->with(7, 'admin');
        hwe_auth_notify_new_user_admin_only('7');
    }
}
