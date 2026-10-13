<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\IntegrationsInternalApi;
use HWE\ControlCenter\PaymentsInternalApi;
use HWE\ControlCenter\SecretsStorage;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Las APIs internas del Control Center (`PaymentsInternalApi`, `IntegrationsInternalApi`).
 *
 * Son server-to-server para el BFF: exigen `X-HWE-Internal-Secret` y devuelven secretos
 * DESCIFRADOS que jamás salen por la config pública. Sin el secreto, 401 sin cuerpo útil.
 *
 * Las constantes son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InternalApisTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<array> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        define('HWE_REVALIDATION_SECRET', 'secreto-interno-de-pruebas-0123456789');

        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('register_rest_route')->alias(function (string $ns, string $route, array $args): bool {
            $this->routes[] = [$ns . $route, $args];
            return true;
        });

        $this->loadOwnFile('mu-plugins/headless-config.php');
    }

    private function requestWithSecret(?string $secret): \WP_REST_Request {
        $headers = $secret === null ? [] : ['X-HWE-Internal-Secret' => $secret];
        return new \WP_REST_Request([], $headers);
    }

    private function permissionOf(string $route): callable {
        foreach ($this->routes as [$registered, $args]) {
            if ($registered === $route) {
                return $args['permission_callback'];
            }
        }
        $this->fail("La ruta {$route} no se registró.");
    }

    // ── Puerta del secreto interno ──────────────────────────────────────────

    public function testBothRoutesRegisterAsGetWithPermissionCallback(): void {
        PaymentsInternalApi::registerRoute();
        IntegrationsInternalApi::registerRoute();

        $this->assertSame(
            ['hwe/v1/payments/credentials', 'hwe/v1/integrations/meta-capi'],
            array_map(static fn (array $r): string => $r[0], $this->routes)
        );
        foreach ($this->routes as [, $args]) {
            $this->assertSame('GET', $args['methods']);
            $this->assertIsCallable($args['permission_callback']);
        }
    }

    public function testWithoutTheSecretBothEndpointsDeny(): void {
        PaymentsInternalApi::registerRoute();
        IntegrationsInternalApi::registerRoute();

        $this->assertFalse($this->permissionOf('hwe/v1/payments/credentials')($this->requestWithSecret(null)));
        $this->assertFalse($this->permissionOf('hwe/v1/payments/credentials')($this->requestWithSecret('incorrecto')));
        $this->assertFalse($this->permissionOf('hwe/v1/integrations/meta-capi')($this->requestWithSecret(null)));
    }

    public function testWithTheSecretBothEndpointsAllow(): void {
        PaymentsInternalApi::registerRoute();
        IntegrationsInternalApi::registerRoute();

        $request = $this->requestWithSecret('secreto-interno-de-pruebas-0123456789');
        $this->assertTrue($this->permissionOf('hwe/v1/payments/credentials')($request));
        $this->assertTrue($this->permissionOf('hwe/v1/integrations/meta-capi')($request));
    }

    // ── payments/credentials ────────────────────────────────────────────────

    public function testPaymentsReturnsTheStoredProviderAndDecryptedSecrets(): void {
        $this->options['hwe_config'] = [
            'payments' => [
                'provider' => 'wompi',
                'wompi' => ['public_key' => '  pk_test  '],
            ],
        ];
        SecretsStorage::setAll(['payments' => ['wompi' => ['integrity_secret' => 'sk_test', 'events_secret' => 'wh_test']]]);

        $data = PaymentsInternalApi::handleGet()->get_data();

        $this->assertSame('wompi', $data['provider']);
        $this->assertSame('pk_test', $data['wompi']['public_key']);
        $this->assertSame('sk_test', $data['wompi']['integrity_secret']);
        $this->assertSame('wh_test', $data['wompi']['events_secret']);
        $this->assertSame('', $data['payu']['api_key'], 'Lo no configurado llega vacío.');
    }

    public function testPaymentsWithoutAStoredProviderReturnsNull(): void {
        $data = PaymentsInternalApi::handleGet()->get_data();

        // null, no el default del esquema: sin elección, el BFF decide con su entorno.
        $this->assertNull($data['provider']);
        $this->assertArrayHasKey('avalpay', $data);
        $this->assertArrayHasKey('wompi', $data);
        $this->assertArrayHasKey('payu', $data);
        $this->assertArrayHasKey('bold', $data);
    }

    public function testPaymentsReturnsAvalpayCredentials(): void {
        $this->options['hwe_config'] = [
            'payments' => ['provider' => 'avalpay', 'avalpay' => ['login' => '  MiLogin  ']],
        ];
        SecretsStorage::setAll(['payments' => ['avalpay' => ['secret_key' => 'sk_aval']]]);

        $data = PaymentsInternalApi::handleGet()->get_data();

        $this->assertSame('avalpay', $data['provider']);
        $this->assertSame('MiLogin', $data['avalpay']['login']);
        $this->assertSame('sk_aval', $data['avalpay']['secret_key']);
    }

    public function testPaymentsResponseIsNeverCached(): void {
        $response = PaymentsInternalApi::handleGet();

        $this->assertSame('no-store, private', $response->get_headers()['Cache-Control']);
    }

    // ── integrations/meta-capi ─────────────────────────────────────────────

    public function testMetaCapiReturnsTheDecryptedToken(): void {
        $this->options['hwe_config'] = [
            'integrations' => ['meta_capi_test_event_code' => '  TEST-1  '],
        ];
        SecretsStorage::setAll(['integrations' => ['meta_capi_token' => 'token_capi']]);

        $data = IntegrationsInternalApi::handleMetaCapi()->get_data();

        $this->assertSame('token_capi', $data['access_token']);
        $this->assertSame('TEST-1', $data['test_event_code']);
    }

    public function testMetaCapiWithoutSecretsReturnsEmpties(): void {
        $data = IntegrationsInternalApi::handleMetaCapi()->get_data();

        $this->assertSame('', $data['access_token']);
        $this->assertSame('', $data['test_event_code']);
    }
}
