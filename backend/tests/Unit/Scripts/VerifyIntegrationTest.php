<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `scripts/verify-integration.sh`: la verificación del módulo `external-integration` contra la
 * pila Docker. Aquí se ejecuta con `docker` y `curl` falsos: se comprueba el propio script (que
 * no toca la pila sin motivo, que su sección de puerta distingue una pila cerrada de una
 * abierta y que los argumentos se validan), no WordPress.
 */
final class VerifyIntegrationTest extends ShellScriptTestCase
{
    /** `docker` que imita una pila bien cerrada (o, con `$open`, una que deja pasar a todos). */
    private function fakeStack(bool $open = false): void
    {
        $hostStatus = $open ? '200' : '404';
        $this->fakeBinary('docker', <<<SH
echo "docker \$*" >> "\$CALLS_LOG"
case "\$1" in
  port) echo "0.0.0.0:8080" ;;
  compose) echo "secreto-de-prueba" ;;
  exec)
    case "\$*" in
      *"%{http_code}"*) echo 404 ;;
      *) echo '{"data":{"__typename":"RootQuery"}}' ;;
    esac ;;
esac
SH);
        $this->fakeBinary('curl', "echo {$hostStatus}");
    }

    public function testElScriptTieneSintaxisValida(): void
    {
        $result = $this->sh('-n', [$this->script('verify-integration.sh')]);

        $this->assertSame(0, $result['code'], $result['stderr']);
    }

    public function testUnaSeccionDesconocidaFallaSinTocarLaPila(): void
    {
        $this->fakeStack();

        $result = $this->sh($this->script('verify-integration.sh'), ['inventada']);

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('sección desconocida', $result['stdout']);
        $this->assertSame('', $this->calls(), 'No debe llamar a docker.');
    }

    public function testLaSeccionDePuertaPasaEnUnaPilaCerrada(): void
    {
        $this->fakeStack();

        $result = $this->sh($this->script('verify-integration.sh'), ['gate']);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $this->assertStringContainsString('TODO OK', $result['stdout']);
        $this->assertStringContainsString('IP no autorizada (desde el host): 404', $result['stdout']);
        // El BFF se simula con el secreto interno, leído de la propia WordPress.
        $this->assertStringContainsString('X-HWE-Internal-Secret: secreto-de-prueba', $this->calls());
        $this->assertStringContainsString('X-Forwarded-For: 198.51.100.7', $this->calls());
    }

    public function testLaSeccionDePuertaFallaSiEntraUnaIpAjena(): void
    {
        $this->fakeStack(open: true);

        $result = $this->sh($this->script('verify-integration.sh'), ['gate']);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('FALLA  IP no autorizada (desde el host): 404', $result['stdout']);
    }

    public function testLaIpDePruebasSePuedeCambiar(): void
    {
        $this->fakeStack();

        $this->sh($this->script('verify-integration.sh'), ['gate'], ['INTEGRATION_IP' => '203.0.113.50']);

        $this->assertStringContainsString('X-Forwarded-For: 203.0.113.50', $this->calls());
    }
}
