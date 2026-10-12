<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `scripts/setup.sh` no debe crear el usuario administrador con una contraseña por
 * defecto.
 *
 * Antes caía en `admin` si `WP_ADMIN_PASSWORD` no estaba definida; combinado con
 * `WP_ADMIN_USER=admin` por defecto, un despliegue que olvidara la variable quedaba con
 * credenciales admin/admin públicas. `wp-login.php` es además el único login humano.
 *
 * Se ejecuta el script de verdad con un `wp` falso que registra sus llamadas: así se
 * comprueba qué haría WP-CLI sin necesitar WordPress.
 */
final class SetupAdminPasswordTest extends TestCase
{
    private string $dir;
    private string $log;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hwe-setup-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0777, true);
        $this->log = $this->dir . '/wp-calls.log';

        // `db check` responde bien (el script espera a la BD); `core is-installed` dice que
        // NO está instalado (así se llega al bloque que crea el admin); `rewrite` corta la
        // ejecución con un código reconocible para no seguir con descargas de plugins.
        file_put_contents($this->dir . '/bin/wp', <<<'SH'
#!/bin/sh
echo "$*" >> "$WP_CALLS_LOG"
case "$*" in
  *"db check"*) exit 0 ;;
  *"core is-installed"*) exit 1 ;;
  *"rewrite"*) exit 99 ;;
  *) exit 0 ;;
esac
SH);
        chmod($this->dir . '/bin/wp', 0755);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{bin/*,*}', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/bin');
        @rmdir($this->dir);
    }

    /**
     * @param array<string, string> $env
     * @return array{code: int, stderr: string, calls: string}
     */
    private function runSetup(array $env): array
    {
        $script = HWE_BACKEND_DIR . '/scripts/setup.sh';
        $process = proc_open(
            ['sh', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            [
                'PATH' => $this->dir . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
                'WP_CALLS_LOG' => $this->log,
            ] + $env
        );
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stderr' => $stderr,
            'calls' => is_file($this->log) ? (string) file_get_contents($this->log) : '',
        ];
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function withoutPassword(): iterable
    {
        yield 'variable ausente' => [[]];
        yield 'variable vacía' => [['WP_ADMIN_PASSWORD' => '']];
    }

    /** @param array<string, string> $env */
    #[DataProvider('withoutPassword')]
    public function testWithoutAPasswordItAbortsAndNeverCreatesTheAdmin(array $env): void
    {
        $result = $this->runSetup($env);

        $this->assertSame(1, $result['code'], 'Debe abortar con error.');
        $this->assertStringContainsString('WP_ADMIN_PASSWORD', $result['stderr']);
        $this->assertStringNotContainsString('core install', $result['calls']);
    }

    public function testItNeverFallsBackToTheAdminPassword(): void
    {
        $result = $this->runSetup([]);

        $this->assertStringNotContainsString('--admin_password=admin', $result['calls']);
    }

    public function testWithAPasswordItCreatesTheAdminWithThatPassword(): void
    {
        $result = $this->runSetup(['WP_ADMIN_PASSWORD' => 'una-clave-larga-y-unica']);

        $this->assertSame(99, $result['code'], 'Llegó hasta el corte del `wp` falso (rewrite).');
        $this->assertStringContainsString('core install', $result['calls']);
        $this->assertStringContainsString('--admin_password=una-clave-larga-y-unica', $result['calls']);
    }

    public function testAnAlreadyInstalledSiteDoesNotNeedThePassword(): void
    {
        // Re-ejecutar el script sobre un WordPress ya instalado no crea ningún admin: la
        // variable no es necesaria y no debe romper la idempotencia.
        file_put_contents($this->dir . '/bin/wp', <<<'SH'
#!/bin/sh
echo "$*" >> "$WP_CALLS_LOG"
case "$*" in
  *"db check"*) exit 0 ;;
  *"core is-installed"*) exit 0 ;;
  *"rewrite"*) exit 99 ;;
  *) exit 0 ;;
esac
SH);

        $result = $this->runSetup([]);

        $this->assertSame(99, $result['code']);
        $this->assertStringNotContainsString('core install', $result['calls']);
    }
}
