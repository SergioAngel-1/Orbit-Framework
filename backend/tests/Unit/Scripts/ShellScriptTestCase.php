<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Base de los tests que EJECUTAN los scripts de shell de backend/scripts con binarios
 * falsos (`docker`, `wp`, `mariadb-dump`, `curl`…) delante en el PATH.
 *
 * Cada binario falso escribe lo que recibe (argumentos y entorno) en un registro, así
 * se comprueba qué habría hecho el script sin necesitar Docker ni WordPress.
 */
abstract class ShellScriptTestCase extends TestCase
{
    protected string $dir;
    protected string $bin;
    protected string $log;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hwe-sh-' . bin2hex(random_bytes(5));
        $this->bin = $this->dir . '/bin';
        $this->log = $this->dir . '/calls.log';
        mkdir($this->bin, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /** Instala un binario falso en el PATH de la prueba. */
    protected function fakeBinary(string $name, string $body): void
    {
        file_put_contents($this->bin . '/' . $name, "#!/bin/sh\n" . $body . "\n");
        chmod($this->bin . '/' . $name, 0755);
    }

    protected function write(string $relative, string $content): string
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Ejecuta un script con `sh`, en `$cwd` (por defecto el directorio de la prueba).
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    protected function sh(string $script, array $args = [], array $env = [], ?string $stdin = null, ?string $cwd = null): array
    {
        $process = proc_open(
            array_merge(['sh', $script], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? $this->dir,
            [
                'PATH' => $this->bin . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
                'HOME' => $this->dir,
                'CALLS_LOG' => $this->log,
            ] + $env
        );
        $this->assertIsResource($process);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    protected function calls(): string
    {
        return is_file($this->log) ? (string) file_get_contents($this->log) : '';
    }

    /** Ruta de un script de backend/scripts. */
    protected function script(string $name): string
    {
        return HWE_BACKEND_DIR . '/scripts/' . $name;
    }
}
