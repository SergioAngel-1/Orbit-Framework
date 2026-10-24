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
        $this->fakeResolvedStack($open);
    }

    public function testElScriptTieneSintaxisValida(): void
    {
        $result = $this->sh('-n', [$this->script('verify-integration.sh')]);

        $this->assertSame(0, $result['code'], $result['stderr']);
    }

    /** Proyecto de Compose que declaran los tests (el script exige que la pila sea suya). */
    private const PROJECT = 'pila-de-prueba';

    /**
     * `docker` que resuelve el contenedor como el script: `compose ps -q` devuelve un id del
     * proyecto y `inspect` su etiqueta. Cualquier subcomando no previsto falla (42), para que la
     * prueba no pueda llegar nunca al docker real.
     */
    private function fakeResolvedStack(bool $open = false, string $project = self::PROJECT): void
    {
        $hostStatus = $open ? '200' : '404';
        $this->fakeBinary('docker', <<<SH
echo "docker \$*" >> "\$CALLS_LOG"
case "\$1" in
  compose)
    case "\$*" in
      *" ps -q "*) printf 'ctr-%s\n' "\$(printf '%s' "\$*" | sed 's/.* ps -q //')" ;;
      *) echo "secreto-de-prueba" ;;
    esac ;;
  inspect) printf '%s\n' "$project" ;;
  port) echo "0.0.0.0:8080" ;;
  exec)
    case "\$*" in
      *"%{http_code}"*) echo 404 ;;
      *) echo '{"data":{"__typename":"RootQuery"}}' ;;
    esac ;;
  *) echo "docker inesperado: \$*" >> "\$CALLS_LOG"; exit 42 ;;
esac
SH);
        $this->fakeBinary('curl', "echo {$hostStatus}");
    }

    public function testElContenedorSeResuelvePorElServicioDeComposeDelProyecto(): void
    {
        $this->fakeResolvedStack();

        $result = $this->sh($this->script('verify-integration.sh'), ['gate'], ['COMPOSE_PROJECT_NAME' => self::PROJECT]);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $calls = $this->calls();
        $this->assertStringContainsString('docker compose ps -q wordpress', $calls);
        $this->assertStringContainsString('docker exec ctr-wordpress curl', $calls);
        $this->assertStringContainsString('docker port ctr-wordpress 80/tcp', $calls);
    }

    public function testHweWpContainerAnulaLaResolucionPorServicio(): void
    {
        $this->fakeResolvedStack();

        $result = $this->sh($this->script('verify-integration.sh'), ['gate'], [
            'COMPOSE_PROJECT_NAME' => self::PROJECT,
            'HWE_WP_CONTAINER' => 'wp-a-mano',
        ]);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $calls = $this->calls();
        $this->assertStringContainsString('docker exec wp-a-mano curl', $calls);
        $this->assertStringNotContainsString('docker compose ps -q', $calls);
    }

    public function testAbortaSiElContenedorResueltoEsDeOtraPila(): void
    {
        // La etiqueta del contenedor dice otro proyecto: el script no debe tocarlo.
        $this->fakeResolvedStack(project: 'otra-pila');

        $result = $this->sh($this->script('verify-integration.sh'), ['gate'], [
            'COMPOSE_PROJECT_NAME' => self::PROJECT,
        ]);

        $out = $result['stdout'] . $result['stderr'];
        $this->assertNotSame(0, $result['code'], $out);
        $this->assertStringContainsString('otra-pila', $out);
        $this->assertStringContainsString(self::PROJECT, $out);
        $this->assertStringNotContainsString('docker exec', $this->calls(), 'No debe ejecutar nada en esa pila.');
    }

    public function testSinPilaEnEsteProyectoSaleConElMensajeDeSiempre(): void
    {
        $this->fakeBinary('docker', <<<'SH'
echo "docker $*" >> "$CALLS_LOG"
case "$1" in
  compose) exit 0 ;;
  inspect) exit 1 ;;
  *) echo "docker inesperado: $*" >> "$CALLS_LOG"; exit 42 ;;
esac
SH);

        $result = $this->sh($this->script('verify-integration.sh'), ['gate'], [
            'COMPOSE_PROJECT_NAME' => self::PROJECT,
        ]);

        $out = $result['stdout'] . $result['stderr'];
        $this->assertSame(2, $result['code'], $out);
        $this->assertStringContainsString('arranca con `docker compose up -d`', $out);
        $this->assertStringNotContainsString('docker exec', $this->calls());
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

        $result = $this->sh($this->script('verify-integration.sh'), ['gate'], ['COMPOSE_PROJECT_NAME' => self::PROJECT]);

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

        $result = $this->sh($this->script('verify-integration.sh'), ['gate'], ['COMPOSE_PROJECT_NAME' => self::PROJECT]);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('FALLA  IP no autorizada (desde el host): 404', $result['stdout']);
    }

    public function testLaIpDePruebasSePuedeCambiar(): void
    {
        $this->fakeStack();

        $this->sh($this->script('verify-integration.sh'), ['gate'], [
            'COMPOSE_PROJECT_NAME' => self::PROJECT,
            'INTEGRATION_IP' => '203.0.113.50',
        ]);

        $this->assertStringContainsString('X-Forwarded-For: 203.0.113.50', $this->calls());
    }
}
