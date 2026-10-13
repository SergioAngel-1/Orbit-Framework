<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\BackupActions;
use HWE\ControlCenter\BackupConfig;
use HWE\ControlCenter\BackupNotify;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * El puente de backups (`BackupConfig`, `BackupActions`, `BackupNotify`).
 *
 * WordPress (panel) y el contenedor `backup` (cron) se hablan por archivos en un directorio
 * FUERA del docroot (`HWE_BACKUPS_DIR`): la config, el disparador manual, el estado de la
 * última corrida y si hay una en curso. Aquí se prueba que el panel escribe la petición en
 * el puente y lee el estado; las acciones con `exit`/`readfile` (descarga, export) y los
 * scripts `.sh` se verifican contra la pila (`verify-*.sh`, tarea 62).
 *
 * `HWE_BACKUPS_DIR` apunta a un temporal: cada test corre en su proceso.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BackupBridgeTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hwe-backups-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
        define('HWE_BACKUPS_DIR', $this->dir);

        Functions\stubs([
            'sanitize_email' => static fn ($v) => is_string($v) ? trim((string) $v) : '',
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
            'esc_html__' => static fn ($v) => (string) $v,
            'esc_attr__' => static fn ($v) => (string) $v,
            'esc_url' => static fn ($v) => (string) $v,
            'size_format' => static fn ($v) => (string) $v . ' B',
            'wp_date' => static fn ($f, $t) => date((string) $f, (int) $t),
            'add_query_arg' => static fn (...$a) => 'https://tienda.example/wp-admin/admin-post.php?x=1',
            'admin_url' => static fn ($p = '') => 'https://tienda.example/wp-admin/' . ltrim((string) $p, '/'),
            'wp_create_nonce' => static fn ($a = '') => 'nonce-' . $a,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            is_file($path) && unlink($path);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function bridgeFile(string $name): string {
        return $this->dir . '/' . $name;
    }

    private function readBridgeJson(string $name): array {
        return json_decode((string) file_get_contents($this->bridgeFile($name)), true);
    }

    // ── BackupConfig::write/read ────────────────────────────────────────────

    public function testWriteAndReadRoundtrip(): void {
        BackupConfig::write([
            'enabled' => true, 'schedule' => 'daily', 'hour_utc' => '3',
            'retain_days' => '30', 'include_uploads' => true, 'notify_email' => 'ops@example.com',
        ]);

        $read = BackupConfig::read();
        $this->assertTrue($read['enabled']);
        $this->assertSame('0 3 * * *', $read['cron']);
        $this->assertSame(30, $read['retain_days']);
        $this->assertSame('ops@example.com', $read['notify_email']);
    }

    public function testWriteClampsGarbageInsteadOfBreakingRetention(): void {
        // `(int) ''` es 0: sin esta guarda, un campo borrado retenía UN día.
        BackupConfig::write(['retain_days' => '', 'hour_utc' => '99']);

        $read = BackupConfig::read();
        $this->assertSame(14, $read['retain_days']);
        $this->assertSame(23, $read['hour_utc']);
    }

    public function testReadWithoutFileReturnsDefaults(): void {
        $this->assertTrue(BackupConfig::read()['enabled']);
    }

    // ── trigger / status / running ──────────────────────────────────────────

    public function testTriggerAndStatus(): void {
        $this->assertSame([], BackupConfig::readStatus());

        BackupConfig::writeTrigger(7);
        $trigger = $this->readBridgeJson('hwe-backup-trigger.json');
        $this->assertSame(7, $trigger['requested_by']);

        file_put_contents(
            $this->bridgeFile('hwe-backup-status.json'),
            json_encode(['last_run_at' => '2026-01-01T00:00:00+00:00', 'success' => true, 'trigger' => 'manual'])
        );
        $status = BackupConfig::readStatus();
        $this->assertTrue($status['success']);
        $this->assertSame('manual', $status['trigger']);
    }

    public function testRunningIsEmptyWithoutAMarkerAndIgnoresStaleOnes(): void {
        $this->assertSame([], BackupConfig::readRunning());

        file_put_contents(
            $this->bridgeFile('hwe-backup-running.json'),
            json_encode(['started_at' => date('c', time() - 3600), 'trigger' => 'scheduled'])
        );
        $this->assertSame([], BackupConfig::readRunning(), 'Una marca de hace una hora es un resto, no una corrida.');
    }

    public function testRunningReportsAFreshMarker(): void {
        file_put_contents(
            $this->bridgeFile('hwe-backup-running.json'),
            json_encode(['started_at' => date('c'), 'trigger' => 'manual'])
        );

        $running = BackupConfig::readRunning();
        $this->assertSame('manual', $running['trigger']);
    }

    // ── artefactos ──────────────────────────────────────────────────────────

    public function testArtifactsListsOnlyRealBackups(): void {
        file_put_contents($this->bridgeFile('db-20260101-030000.sql.gz'), 'x');
        file_put_contents($this->bridgeFile('hwe-backup-status.json'), '{}');
        file_put_contents($this->bridgeFile('nota.txt'), 'x');

        $names = array_map(static fn (array $a): string => $a['name'], BackupConfig::listArtifacts());

        $this->assertSame(['db-20260101-030000.sql.gz'], $names);
    }

    public function testArtifactNamesRejectPaths(): void {
        $this->assertTrue(BackupConfig::isValidArtifactName('uploads-20260101-030000.tar.gz'));
        $this->assertTrue(BackupConfig::isValidArtifactName('config-20260101-030000.json'));
        $this->assertFalse(BackupConfig::isValidArtifactName('../hwe_config.php'));
        $this->assertFalse(BackupConfig::isValidArtifactName('db-manual.sql.gz'));
        $this->assertFalse(BackupConfig::isValidArtifactName(''));
    }

    // ── BackupActions::render ───────────────────────────────────────────────

    public function testRenderShowsTheManualButtonAndRecentBackups(): void {
        file_put_contents($this->bridgeFile('db-20260101-030000.sql.gz'), 'x');

        $html = BackupActions::render();

        $this->assertStringContainsString('Backup ahora', $html);
        $this->assertStringContainsString('db-20260101-030000.sql.gz', $html);
        $this->assertStringContainsString('Exportar configuraci', $html);
    }

    public function testRenderBlocksTheButtonWhileRunning(): void {
        file_put_contents(
            $this->bridgeFile('hwe-backup-running.json'),
            json_encode(['started_at' => date('c'), 'trigger' => 'manual'])
        );

        $html = BackupActions::render();

        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('en curso', $html);
    }

    // ── BackupNotify::shouldNotify ──────────────────────────────────────────

    public function testShouldNotifyOnlyOnNewRuns(): void {
        $this->assertTrue(BackupNotify::shouldNotify(['last_run_at' => '2026-01-01T00:00:00+00:00'], ''));
        $this->assertTrue(BackupNotify::shouldNotify(['last_run_at' => 'B'], 'A'));
        $this->assertFalse(BackupNotify::shouldNotify(['last_run_at' => 'A'], 'A'));
        $this->assertFalse(BackupNotify::shouldNotify([], ''));
    }
}
