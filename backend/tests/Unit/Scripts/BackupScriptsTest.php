<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `backup.sh`, `restore.sh` y `backup-cron.sh`: tres defectos de operación que no se
 * veían en uso normal.
 *
 *  1. La contraseña de MySQL viajaba como `-p"$PASS"`: visible en `ps aux` y en
 *     `/proc/<pid>/cmdline` mientras corre el volcado. Ahora va por `MYSQL_PWD`.
 *  2. `mariadb-dump | gzip` y `gunzip | mariadb` en `/bin/sh` (sin `pipefail`): el estado
 *     de la tubería es el del ÚLTIMO comando, así que un volcado que falla a medias o un
 *     archivo truncado «tenían éxito»: copias truncadas o restauraciones que no
 *     restauran nada, sin ninguna señal. Ahora se vuelca a un archivo intermedio y se
 *     comprueba cada paso; si algo falla no queda ninguna copia a medias.
 *  3. `. ./.env` ejecutaba el archivo como shell (ver LoadEnvTest).
 *
 * Además `backup-cron.sh` deja `hwe-backup-status.json` (lo lee el panel) y exporta la
 * config en `config-*.json`; si el volcado falla, el status marca el fallo pero no se
 * borra ninguna copia anterior.
 *
 * Los scripts se EJECUTAN con `docker` y `mariadb-dump` falsos que registran lo que
 * reciben (argumentos y `MYSQL_PWD`).
 */
final class BackupScriptsTest extends ShellScriptTestCase
{
    private const PASSWORD = 'S3cr3t-pa55=con#simbolos';

    protected function setUp(): void
    {
        parent::setUp();

        // `docker compose exec -T [-e MYSQL_PWD] db <cmd> …`: registra argv y MYSQL_PWD.
        $this->fakeBinary('docker', <<<'SH'
echo "docker $*" >> "$CALLS_LOG"
echo "MYSQL_PWD=${MYSQL_PWD-<sin definir>}" >> "$CALLS_LOG"
case "$*" in
  *mariadb-dump*)
    if [ "${FAKE_DUMP_FAIL:-}" = 1 ]; then
      echo "-- volcado parcial"
      echo "mariadb-dump: la conexion se perdio (simulado)" >&2
      exit 2
    fi
    echo "-- MariaDB dump"
    echo "CREATE TABLE t (id int);"
    echo "-- Dump completed"
    exit 0 ;;
  *" ps "*|*redis-cli*) exit 0 ;;
  *mariadb*)
    cat > "$CALLS_LOG.import"
    echo "IMPORT_BYTES=$(wc -c < "$CALLS_LOG.import" | tr -d ' ')" >> "$CALLS_LOG"
    exit 0 ;;
esac
exit 0
SH);
        // Lo que `backup-cron.sh` ejecuta directamente en el contenedor `backup`.
        $this->fakeBinary('mariadb-dump', <<<'SH'
echo "mariadb-dump $*" >> "$CALLS_LOG"
echo "MYSQL_PWD=${MYSQL_PWD-<sin definir>}" >> "$CALLS_LOG"
if [ "${FAKE_DUMP_FAIL:-}" = 1 ]; then
  echo "-- volcado parcial"
  echo "mariadb-dump: la conexion se perdio (simulado)" >&2
  exit 2
fi
echo "-- MariaDB dump"
echo "CREATE TABLE t (id int);"
echo "-- Dump completed"
SH);
        // El export de configuración del panel (`config-*.json`) lee las options por SQL.
        $this->fakeBinary('mariadb', <<<'SH'
echo "mariadb $*" >> "$CALLS_LOG"
echo "a:1:{s:5:\"brand\";a:1:{s:4:\"name\";s:1:\"T\";}}"
SH);
    }

    private function writeEnv(): void
    {
        // Un valor SIN comillas con espacios: `. ./.env` fallaba justo aquí.
        $this->write('.env', "WP_TITLE=Mi Tienda Online\nMYSQL_USER=wp\nMYSQL_PASSWORD=" . self::PASSWORD . "\nMYSQL_DATABASE=tienda\n");
    }

    /** @return list<string> archivos de un directorio (sin . ni ..) */
    private function filesIn(string $relative): array
    {
        $dir = $this->dir . '/' . $relative;
        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    private function gunzipped(string $path): string
    {
        $raw = gzdecode((string) file_get_contents($path));
        $this->assertIsString($raw, "$path no es un gzip válido");
        return $raw;
    }

    // ─────────────────────────────── backup.sh ───────────────────────────────

    public function testBackupNeverPutsThePasswordInTheCommandLine(): void
    {
        $this->writeEnv();

        $result = $this->sh($this->script('backup.sh'), ['out']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        foreach (explode("\n", $this->calls()) as $line) {
            if (str_starts_with($line, 'docker ')) {
                $this->assertStringNotContainsString(self::PASSWORD, $line, 'La contraseña aparece en los argumentos.');
                $this->assertStringNotContainsString(' -p', $line, 'Quedó un flag -p.');
            }
        }
        $this->assertStringContainsString('MYSQL_PWD=' . self::PASSWORD, $this->calls());
        $this->assertStringContainsString('-e MYSQL_PWD', $this->calls(), 'MYSQL_PWD se reenvía sin valor: `-e MYSQL_PWD`.');
    }

    public function testBackupWritesAValidCompressedDumpAndLeavesNoIntermediateFile(): void
    {
        $this->writeEnv();

        $result = $this->sh($this->script('backup.sh'), ['out']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $files = $this->filesIn('out');
        $dumps = array_values(array_filter($files, static fn ($f) => str_ends_with($f, '.sql.gz')));
        $this->assertCount(1, $dumps);
        $this->assertSame([], array_values(array_filter($files, static fn ($f) => str_ends_with($f, '.sql'))), 'Quedó el volcado sin comprimir.');
        $this->assertStringContainsString('CREATE TABLE t', $this->gunzipped($this->dir . '/out/' . $dumps[0]));
    }

    public function testBackupPacksTheUploads(): void
    {
        $this->writeEnv();
        $this->write('backend/wp-content/uploads/2026/foto.txt', 'imagen');

        $result = $this->sh($this->script('backup.sh'), ['out']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $tars = array_values(array_filter($this->filesIn('out'), static fn ($f) => str_ends_with($f, '.tar.gz')));
        $this->assertCount(1, $tars);
    }

    public function testBackupFailsLoudAndLeavesNoPartialCopyWhenTheDumpFails(): void
    {
        $this->writeEnv();
        $this->write('backend/wp-content/uploads/foto.txt', 'imagen');

        $result = $this->sh($this->script('backup.sh'), ['out'], ['FAKE_DUMP_FAIL' => '1']);

        $this->assertNotSame(0, $result['code'], 'Un volcado fallido tiene que hacer fallar el script.');
        $this->assertStringContainsString('✗', $result['stderr'], 'Debe explicar el fallo.');
        $this->assertSame([], $this->filesIn('out'), 'No debe quedar ninguna copia a medias que parezca válida.');
        $this->assertStringNotContainsString('Backup completado', $result['stdout']);
    }

    public function testBackupToleratesAnEnvFileThatCannotBeSourced(): void
    {
        $this->write('.env', "WP_TITLE=Mi Tienda Online\nMYSQL_PASSWORD=" . self::PASSWORD . "\n");

        $result = $this->sh($this->script('backup.sh'), ['out']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('MYSQL_PWD=' . self::PASSWORD, $this->calls());
    }

    public function testBackupUsesTheExplicitEnvironmentOverTheEnvFile(): void
    {
        $this->writeEnv();

        $result = $this->sh($this->script('backup.sh'), ['out'], ['MYSQL_PASSWORD' => 'de-entorno']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('MYSQL_PWD=de-entorno', $this->calls());
        $this->assertStringNotContainsString(self::PASSWORD, $this->calls());
    }

    // ─────────────────────────────── restore.sh ──────────────────────────────

    private function gz(string $name, string $content): string
    {
        return $this->write($name, (string) gzencode($content));
    }

    public function testRestoreNeverPutsThePasswordInTheCommandLine(): void
    {
        $this->writeEnv();
        $dump = $this->gz('db.sql.gz', "CREATE TABLE t (id int);\n");

        $result = $this->sh($this->script('restore.sh'), [$dump], [], "restore\n");

        $this->assertSame(0, $result['code'], $result['stderr'] . $result['stdout']);
        foreach (explode("\n", $this->calls()) as $line) {
            if (str_starts_with($line, 'docker ')) {
                $this->assertStringNotContainsString(self::PASSWORD, $line);
                $this->assertStringNotContainsString(' -p', $line);
            }
        }
        $this->assertStringContainsString('MYSQL_PWD=' . self::PASSWORD, $this->calls());
    }

    public function testRestoreImportsTheDecompressedDump(): void
    {
        $this->writeEnv();
        $sql = "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n";
        $dump = $this->gz('db.sql.gz', $sql);

        $result = $this->sh($this->script('restore.sh'), [$dump], [], "restore\n");

        $this->assertSame(0, $result['code'], $result['stderr'] . $result['stdout']);
        $this->assertStringContainsString('IMPORT_BYTES=' . strlen($sql), $this->calls());
    }

    public function testRestoreAbortsBeforeTouchingTheDatabaseWhenTheDumpIsTruncated(): void
    {
        $this->writeEnv();
        // Un gzip válido al que le falta la mitad: `gunzip` falla a mitad, y con una tubería
        // `gunzip | mariadb` el estado seguía siendo el de `mariadb`: «restauración exitosa».
        $full = (string) gzencode(str_repeat("INSERT INTO t VALUES (12345);\n", 5000));
        $truncated = $this->write('trunc.sql.gz', substr($full, 0, intdiv(strlen($full), 2)));

        $result = $this->sh($this->script('restore.sh'), [$truncated], [], "restore\n");

        $this->assertNotSame(0, $result['code'], 'Un volcado truncado no puede darse por restaurado.');
        $this->assertStringNotContainsString('IMPORT_BYTES', $this->calls(), 'No debe llegar a importar nada.');
        $this->assertStringNotContainsString('Restauración completada', $result['stdout']);
    }

    public function testRestoreWithoutTheExplicitConfirmationDoesNothing(): void
    {
        $this->writeEnv();
        $dump = $this->gz('db.sql.gz', "CREATE TABLE t (id int);\n");

        $result = $this->sh($this->script('restore.sh'), [$dump], [], "no\n");

        $this->assertSame(1, $result['code']);
        $this->assertStringNotContainsString('IMPORT_BYTES', $this->calls());
    }

    // ───────────────────────────── backup-cron.sh ────────────────────────────

    /** @return array{code: int, stdout: string, stderr: string} */
    private function runCron(array $env = []): array
    {
        return $this->sh($this->script('backup-cron.sh'), [], [
            'BACKUP_DIR' => $this->dir . '/cron-out',
            'MYSQL_HOST' => 'db',
            'MYSQL_USER' => 'wp',
            'MYSQL_PASSWORD' => self::PASSWORD,
            'MYSQL_DATABASE' => 'tienda',
        ] + $env);
    }

    public function testCronNeverPutsThePasswordInTheCommandLine(): void
    {
        $result = $this->runCron();

        $this->assertSame(0, $result['code'], $result['stderr']);
        foreach (explode("\n", $this->calls()) as $line) {
            if (str_starts_with($line, 'mariadb-dump ')) {
                $this->assertStringNotContainsString(self::PASSWORD, $line);
                $this->assertStringNotContainsString(' -p', $line);
            }
        }
        $this->assertStringContainsString('MYSQL_PWD=' . self::PASSWORD, $this->calls());
    }

    public function testCronWritesAValidCompressedDump(): void
    {
        $result = $this->runCron();

        $this->assertSame(0, $result['code'], $result['stderr']);
        $files = $this->filesIn('cron-out');
        $dumps = array_values(array_filter($files, static fn ($f) => str_starts_with($f, 'db-') && str_ends_with($f, '.sql.gz')));
        $this->assertCount(1, $dumps);
        $this->assertMatchesRegularExpression('/^db-.*\.sql\.gz$/', $dumps[0]);
        $this->assertStringContainsString('CREATE TABLE t', $this->gunzipped($this->dir . '/cron-out/' . $dumps[0]));
    }

    public function testCronWritesStatusAndConfigExport(): void
    {
        $result = $this->runCron();

        $this->assertSame(0, $result['code'], $result['stderr']);
        $status = json_decode((string) file_get_contents($this->dir . '/cron-out/hwe-backup-status.json'), true);
        $this->assertTrue($status['success']);
        $this->assertSame('scheduled', $status['trigger']);
        $this->assertNotSame('', $status['summary']);
        $configs = array_values(array_filter(
            $this->filesIn('cron-out'),
            static fn ($f) => str_starts_with($f, 'config-') && str_ends_with($f, '.json')
        ));
        $this->assertCount(1, $configs, 'El export de configuración acompaña a cada backup.');
    }

    public function testCronFailsLoudAndLeavesNoPartialCopyWhenTheDumpFails(): void
    {
        $result = $this->runCron(['FAKE_DUMP_FAIL' => '1']);

        $this->assertNotSame(0, $result['code']);
        $this->assertStringContainsString('✗', $result['stderr'] . $result['stdout']);
        $dumps = array_values(array_filter(
            $this->filesIn('cron-out'),
            static fn ($f) => str_starts_with($f, 'db-') && str_ends_with($f, '.sql.gz')
        ));
        $this->assertSame([], $dumps, 'No debe quedar ningún volcado a medias que parezca válido.');
        // El status SÍ se escribe aunque falle: es lo que permite al panel mostrar el fallo.
        $status = json_decode((string) file_get_contents($this->dir . '/cron-out/hwe-backup-status.json'), true);
        $this->assertFalse($status['success']);
    }

    public function testCronSurvivesARetentionFailureWithoutLosingTheBackupItJustMade(): void
    {
        // `find … || true` con `2>/dev/null`: si la limpieza falla, la copia ya hecha
        // no se tumba y las copias buenas anteriores tampoco se tocan.
        $this->fakeBinary('find', 'echo "find: no se puede borrar (simulado)" >&2; exit 1');

        $result = $this->runCron();

        $this->assertSame(0, $result['code'], 'La copia ya está hecha: la retención no debe tumbarla.');
        $dumps = array_values(array_filter(
            $this->filesIn('cron-out'),
            static fn ($f) => str_starts_with($f, 'db-') && str_ends_with($f, '.sql.gz')
        ));
        $this->assertCount(1, $dumps);
    }

    // ──────────────────── el lock de backup-cron.sh no deja el sitio sin copias ────────────────────

    /** @return list<string> Volcados que dejó la corrida. */
    private function dumps(): array
    {
        return array_values(array_filter(
            $this->filesIn('cron-out'),
            static fn ($f) => str_starts_with($f, 'db-') && str_ends_with($f, '.sql.gz')
        ));
    }

    private function lockDir(): string
    {
        return $this->dir . '/hwe-backup.lock';
    }

    private function runCronWithLock(array $env = []): array
    {
        return $this->runCron(['HWE_BACKUP_LOCK_DIR' => $this->lockDir()] + $env);
    }

    public function testCronReleasesItsLockAtTheEnd(): void
    {
        $result = $this->runCronWithLock();

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertDirectoryDoesNotExist($this->lockDir());
    }

    public function testCronReleasesItsLockEvenWhenTheDumpFails(): void
    {
        $this->runCronWithLock(['FAKE_DUMP_FAIL' => '1']);

        $this->assertDirectoryDoesNotExist($this->lockDir());
    }

    public function testCronSkipsWhileTheLockOwnerIsStillRunning(): void
    {
        $owner = proc_open(['sleep', '60'], [], $pipes);
        try {
            $pid = (int) (proc_get_status($owner)['pid']);
            mkdir($this->lockDir());
            file_put_contents($this->lockDir() . '/pid', (string) $pid);

            $result = $this->runCronWithLock();

            $this->assertSame(0, $result['code']);
            $this->assertStringContainsString('Ya hay un backup en curso', $result['stdout']);
            $this->assertSame([], $this->dumps(), 'No se pisa un backup en marcha.');
            $this->assertDirectoryExists($this->lockDir(), 'El lock de otro no se toca.');
        } finally {
            proc_terminate($owner);
            proc_close($owner);
        }
    }

    public function testCronRecoversFromAnOrphanLockWhoseOwnerIsDead(): void
    {
        // El caso real: un backup manual murió empaquetando los uploads y dejó el lock;
        // desde entonces cada corrida diaria se omitía y nadie lo supo en 18 días.
        mkdir($this->lockDir());
        file_put_contents($this->lockDir() . '/pid', '999999');

        $result = $this->runCronWithLock();

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('Lock huérfano', $result['stderr']);
        $this->assertCount(1, $this->dumps(), 'La corrida diaria vuelve a hacer su copia.');
        $this->assertDirectoryDoesNotExist($this->lockDir());
    }

    public function testCronRecoversFromAnOldLockWithoutPid(): void
    {
        // Lock de la versión anterior (directorio vacío): solo se puede juzgar por su edad.
        mkdir($this->lockDir());
        touch($this->lockDir(), time() - 7 * 3600);

        $result = $this->runCronWithLock();

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertCount(1, $this->dumps());
    }

    public function testCronRespectsARecentLockWithoutPid(): void
    {
        mkdir($this->lockDir());

        $result = $this->runCronWithLock();

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Ya hay un backup en curso', $result['stdout']);
        $this->assertSame([], $this->dumps());
    }

    public function testCronTreatsALockOlderThanTheLimitAsOrphanEvenIfThePidLooksAlive(): void
    {
        // Un PID puede reutilizarse tras reiniciar el contenedor.
        $owner = proc_open(['sleep', '60'], [], $pipes);
        try {
            mkdir($this->lockDir());
            file_put_contents($this->lockDir() . '/pid', (string) (proc_get_status($owner)['pid']));
            touch($this->lockDir(), time() - 7 * 3600);

            $result = $this->runCronWithLock();

            $this->assertSame(0, $result['code'], $result['stderr']);
            $this->assertCount(1, $this->dumps());
        } finally {
            proc_terminate($owner);
            proc_close($owner);
        }
    }
}
