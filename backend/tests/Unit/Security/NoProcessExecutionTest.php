<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Security;

use HWE\ControlCenter\BackupConfig;
use PHPUnit\Framework\TestCase;

/**
 * El código PHP propio no ejecuta procesos del sistema.
 *
 * `BackupConfig::triggerNow()` construía un `exec()` sobre una ruta que su propio
 * comentario llamaba «aproximada; ajustar si cambia», sin ninguna comprobación de
 * capacidad propia (dependía entera de quien la llamara) y sin ningún llamador en
 * todo el repositorio: superficie de ejecución de comandos sin uso. Si hace falta un
 * «backup manual», se escribe de nuevo con la ruta correcta y su propio
 * `current_user_can()`.
 */
final class NoProcessExecutionTest extends TestCase
{
    /** Funciones que lanzan procesos o intérpretes. */
    private const FORBIDDEN = [
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
    ];

    public function testBackupConfigNoLongerExposesTheDeadExecEntryPoint(): void
    {
        $this->assertFalse(method_exists(BackupConfig::class, 'triggerNow'));
    }

    /** @return list<string> rutas de los PHP propios (mu-plugins, plugins hwe-* y tema base) */
    private function ownPhpFiles(): array
    {
        $roots = [
            HWE_WP_CONTENT_DIR . '/mu-plugins',
            HWE_WP_CONTENT_DIR . '/themes/hwe-headless-base',
            ...(glob(HWE_WP_CONTENT_DIR . '/plugins/hwe-*', GLOB_ONLYDIR) ?: []),
        ];

        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> «archivo:línea función» de cada llamada prohibida (sin comentarios ni cadenas) */
    private function findCalls(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::FORBIDDEN, true)) {
                continue;
            }
            // Siguiente token significativo: tiene que ser «(» (una llamada).
            $next = $i + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if (($tokens[$next] ?? null) !== '(') {
                continue;
            }
            // Anterior: no es un método (`->exec(`, `::exec(`) ni una declaración (`function exec(`).
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }
            $before = $tokens[$prev] ?? null;
            if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $found[] = sprintf('%s:%d %s()', str_replace(HWE_WP_CONTENT_DIR . '/', '', $file), $token[2], $token[1]);
        }
        return $found;
    }

    public function testThereAreFilesToCheck(): void
    {
        $this->assertGreaterThan(10, count($this->ownPhpFiles()), 'La guarda no puede ser vacía.');
    }

    public function testOwnCodeNeverSpawnsProcesses(): void
    {
        $calls = [];
        foreach ($this->ownPhpFiles() as $file) {
            array_push($calls, ...$this->findCalls($file));
        }

        $this->assertSame([], $calls);
    }

    public function testTheScannerDoesDetectACall(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hwe') . '.php';
        file_put_contents($tmp, "<?php\n// exec('ignorado en comentario');\n\$s = 'exec(';\n\$o->exec();\nfunction exec() {}\nexec('ls');\n");
        try {
            $this->assertCount(1, $this->findCalls($tmp), 'Solo la llamada real, no comentarios, cadenas, métodos ni declaraciones.');
        } finally {
            unlink($tmp);
        }
    }
}
