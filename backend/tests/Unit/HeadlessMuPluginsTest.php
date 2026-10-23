<?php

declare(strict_types=1);

namespace HWE\Tests\Unit;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mejoras a los mu-plugins de núcleo (tarea 36).
 *
 *  · `hwe_is_graphql_request()` casa por segmento de path (`/graphql`), no por subcadena:
 *    una entrada en `/blog/graphql-vs-rest` no es una petición GraphQL.
 *  · `hwe_headless_risky_gateways()` lista COD/BACS activos (el COD intencional del panel
 *    no cuenta): el aviso de wp-admin solo debe saltar por olvidos del operador.
 *  · `hwe_logmgr_unlink()` registra el fallo en vez de silenciarlo con `@`.
 *  · Los gateways headless (`hwe_offline`, `hwe_whatsapp_link`) no se definen sin
 *    WooCommerce: el archivo carga sin fatal en cualquier contexto.
 *  · Con el panel en su propio host, la API REST y las redirecciones del panel se quedan
 *    en ese host (`hwe_rest_url_on_admin_host`, `hwe_allow_admin_redirect_host`).
 *  · El log de consultas GraphQL rechazadas se registra una sola vez y la caché muerta
 *    (`graphql_cache_active`) ya no existe.
 *  · La prueba de SMTP vuelve al Control Center (`admin.php`, no `options-general.php`).
 */
final class HeadlessMuPluginsTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'wp_unslash' => static fn ($v) => $v,
            'wp_parse_url' => static fn ($url, $component = -1) => parse_url((string) $url, $component),
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('mu-plugins/headless-config.php');
        $this->loadOwnFile('mu-plugins/woocommerce-headless.php');
        $this->loadOwnFile('mu-plugins/hwe-log-manager.php');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST']);
        parent::tearDown();
    }

    // ── hwe_is_graphql_request ──────────────────────────────────────────────

    /** @return iterable<string, array{string, bool}> */
    public static function graphqlUris(): iterable {
        yield 'endpoint exacto' => ['/graphql', true];
        yield 'con barra final' => ['/graphql/', true];
        yield 'con query' => ['/graphql?query={__typename}', true];
        yield 'una entrada que lo menciona no es GraphQL' => ['/blog/graphql-vs-rest', false];
        yield 'REST no es GraphQL' => ['/wp-json/wc/store/v1/cart', false];
        yield 'la home no es GraphQL' => ['/', false];
    }

    #[DataProvider('graphqlUris')]
    public function testGraphqlDetectionMatchesByPathSegment(string $uri, bool $expected): void {
        $_SERVER['REQUEST_URI'] = $uri;

        $this->assertSame($expected, hwe_is_graphql_request());
    }

    // ── hwe_headless_risky_gateways ─────────────────────────────────────────

    public function testBothNativeGatewaysActiveAreRisky(): void {
        $this->options['woocommerce_cod_settings'] = ['enabled' => 'yes'];
        $this->options['woocommerce_bacs_settings'] = ['enabled' => 'yes'];

        $this->assertSame(['cod', 'bacs'], hwe_headless_risky_gateways());
    }

    public function testIntentionalCashFromThePanelIsNotRisky(): void {
        $this->options['hwe_config'] = ['payments' => ['cash_enabled' => true]];
        $this->options['woocommerce_cod_settings'] = ['enabled' => 'yes'];

        $this->assertSame([], hwe_headless_risky_gateways());
    }

    public function testDisabledGatewaysAreNotRisky(): void {
        $this->options['woocommerce_cod_settings'] = ['enabled' => 'no'];

        $this->assertSame([], hwe_headless_risky_gateways());
    }

    // ── hwe_logmgr_unlink ───────────────────────────────────────────────────

    public function testUnlinkFailureIsLoggedInsteadOfSilenced(): void {
        $missing = sys_get_temp_dir() . '/hwe-no-existe-' . bin2hex(random_bytes(4));

        $this->assertFalse(hwe_logmgr_unlink($missing));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('[hwe] log-manager:', $this->logged[0]);
    }

    public function testUnlinkSuccessDeletesQuietly(): void {
        $path = sys_get_temp_dir() . '/hwe-borrame-' . bin2hex(random_bytes(4));
        file_put_contents($path, 'x');

        $this->assertTrue(hwe_logmgr_unlink($path));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $this->logged);
    }

    // ── gateways headless sin Woo ───────────────────────────────────────────

    public function testHeadlessGatewaysAreNotDefinedWithoutWooCommerce(): void {
        $this->assertFalse(class_exists('WC_Gateway_HWE_Offline'));
        $this->assertFalse(class_exists('WC_Gateway_HWE_Whatsapp_Link'));
    }

    // ── panel en su propio host ─────────────────────────────────────────────

    private function splitHosts(string $site, string $home): void {
        Functions\when('site_url')->justReturn($site);
        Functions\when('home_url')->justReturn($home);
    }

    public function testRestUrlMovesToThePanelHostWhenServedThere(): void {
        $this->splitHosts('https://admin.example.test', 'https://example.test');
        $_SERVER['HTTP_HOST'] = 'admin.example.test:443';

        $this->assertSame(
            'https://admin.example.test/wp-json/wp/v2/users/me',
            hwe_rest_url_on_admin_host('https://example.test/wp-json/wp/v2/users/me')
        );
    }

    public function testRestUrlServedOnThePublicHostIsUntouched(): void {
        $this->splitHosts('https://admin.example.test', 'https://example.test');
        $_SERVER['HTTP_HOST'] = 'example.test';

        $this->assertSame('https://example.test/wp-json/', hwe_rest_url_on_admin_host('https://example.test/wp-json/'));
    }

    public function testRestUrlWithASingleHostOrWithoutRequestIsUntouched(): void {
        $this->splitHosts('http://localhost:8080', 'http://localhost:8080');
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $this->assertSame('http://localhost:8080/wp-json/', hwe_rest_url_on_admin_host('http://localhost:8080/wp-json/'));

        unset($_SERVER['HTTP_HOST']);
        $this->splitHosts('https://admin.example.test', 'https://example.test');
        $this->assertSame('https://example.test/wp-json/', hwe_rest_url_on_admin_host('https://example.test/wp-json/'));
    }

    public function testRedirectHostsGainOnlyThePanelHost(): void {
        $this->splitHosts('https://admin.example.test', 'https://example.test');

        $this->assertSame(['example.test', 'admin.example.test'], hwe_allow_admin_redirect_host(['example.test']));
        $this->assertSame(['admin.example.test'], hwe_allow_admin_redirect_host(['admin.example.test']));
    }

    // ── graphql-protection ──────────────────────────────────────────────────

    public function testRejectedQueriesLogIsRegisteredOnceAndDeadCacheIsGone(): void {
        Functions\when('wp_get_environment_type')->justReturn('production');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('sanitize_text_field')->returnArg();
        $this->loadOwnFile('mu-plugins/graphql-protection.php');

        $this->assertNotFalse(has_filter('graphql_request_results', 'hwe_graphql_log_rejected'));
        $this->assertFalse(has_filter('graphql_cache_active'));
        $this->assertFalse(has_action('graphql_execute_request'));
        // Los límites se enganchan a los filtros que WPGraphQL lee de verdad.
        // `graphql_query_max_depth` y `graphql_query_complexity` no existen: con
        // ellos ningún límite se aplicaba.
        $this->assertFalse(has_filter('graphql_query_max_depth'));
        $this->assertFalse(has_filter('graphql_query_complexity'));
        $this->assertNotFalse(has_filter('graphql_query_depth_max', 'hwe_graphql_query_depth_max'));
        $this->assertNotFalse(has_filter('graphql_validation_rules', 'hwe_graphql_validation_rules'));

        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $result = ['errors' => [['message' => 'demasiado profunda']]];
        $this->assertSame($result, hwe_graphql_log_rejected($result));
        $this->assertCount(1, $this->logged);
        $this->assertStringStartsWith('[hwe] graphql:', $this->logged[0]);
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testRejectedQueriesAreLoggedWhenWpGraphqlPassesAnExecutionResult(): void {
        Functions\when('wp_get_environment_type')->justReturn('production');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('sanitize_text_field')->returnArg();
        $this->loadOwnFile('mu-plugins/graphql-protection.php');

        // Por HTTP, WPGraphQL filtra el objeto de webonyx, no un array: antes no se registraba nada.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $result = new \GraphQL\Executor\ExecutionResult(null, [new \Exception('Max query complexity should be 1000 but got 1100.')]);
        $this->assertSame($result, hwe_graphql_log_rejected($result));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('203.0.113.9', $this->logged[0]);
        $this->assertStringContainsString('Max query complexity', $this->logged[0]);

        // Una respuesta sin errores no deja rastro.
        hwe_graphql_log_rejected(new \GraphQL\Executor\ExecutionResult(['data' => true]));
        $this->assertCount(1, $this->logged);
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testDepthLimitUsesTheFilterWpGraphqlReads(): void {
        Functions\when('wp_get_environment_type')->justReturn('production');
        $this->loadOwnFile('mu-plugins/graphql-protection.php');

        // Con el ajuste de WPGraphQL apagado le llega 0 (sin límite): el límite se aplica igual.
        $this->assertSame(15, hwe_graphql_query_depth_max(0));
        // Un ajuste más estricto del panel se respeta; uno más laxo, no.
        $this->assertSame(10, hwe_graphql_query_depth_max(10));
        $this->assertSame(15, hwe_graphql_query_depth_max(40));
    }

    public function testComplexityLimitReplacesTheDisabledWebonyxRule(): void {
        Functions\when('wp_get_environment_type')->justReturn('production');
        $this->loadOwnFile('mu-plugins/graphql-protection.php');

        // La regla de webonyx viene desactivada: se sustituye por una con límite.
        $rule = \GraphQL\Validator\Rules\QueryComplexity::class;
        $rules = hwe_graphql_validation_rules([
            'query_depth' => 'regla de WPGraphQL',
            $rule => new \GraphQL\Validator\Rules\QueryComplexity(\GraphQL\Validator\Rules\QueryComplexity::DISABLED),
        ]);

        $this->assertSame('regla de WPGraphQL', $rules['query_depth']);
        $this->assertInstanceOf($rule, $rules[$rule]);
        $this->assertSame(1000, $rules[$rule]->getMaxQueryComplexity());
    }

    // ── hwe-smtp ────────────────────────────────────────────────────────────

    public function testSmtpTestReturnsToTheTopLevelControlCenter(): void {
        Functions\when('admin_url')->alias(static fn (string $path = '') => 'https://admin.example.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(static fn (array $args, string $url) => $url . '?' . http_build_query($args));
        $this->loadOwnFile('mu-plugins/hwe-smtp.php');

        $this->assertSame(
            'https://admin.example.test/wp-admin/admin.php?page=hwe-config&hwe_smtp_test=sent&hwe_smtp_test_tpl=basic&hwe_smtp_test_real=0',
            hwe_smtp_test_redirect_url(true)
        );
        $this->assertStringContainsString('hwe_smtp_test=failed', hwe_smtp_test_redirect_url(false));
        $this->assertStringContainsString(
            'hwe_smtp_test_tpl=wc%3Acustomer_note&hwe_smtp_test_real=1',
            hwe_smtp_test_redirect_url(true, 'wc:customer_note', true),
            'Vuelve con la plantilla probada para dejarla seleccionada.'
        );
    }
}
