<?php

namespace HWE\ControlCenter;

/**
 * Puente entre la configuración de backup almacenada en wp_options y el
 * contenedor `backup` de Docker, que no tiene acceso directo a la BD.
 *
 * Todos los archivos de este puente viven en baseDir(): un directorio FUERA
 * del docroot de Apache (nunca servible por Caddy/Apache), compartido entre
 * los contenedores `wordpress`, `wp-cron` y `backup` (mismo host path
 * `./backups`, ver docker-compose.prod.yml). Antes vivía dentro de
 * wp-content, alcanzable públicamente en /wp-content/hwe-backup-config.json
 * porque Caddy expone ese subpath sin restricciones.
 *
 * Flujo completo:
 *   wp-admin (guardar) → Storage::merge() → BackupConfig::write()
 *       → hwe-backup-config.json (en baseDir())
 *       → backup-entrypoint.sh lo lee al arrancar y configura cron
 *   wp-admin ("Backup ahora") → BackupConfig::writeTrigger()
 *       → hwe-backup-trigger.json → backup-trigger-watch.sh (cron cada
 *       minuto en el contenedor `backup`) lo consume y lo borra
 *   backup-cron.sh (al adquirir su lock) → hwe-backup-running.json
 *       → BackupConfig::readRunning() (panel, bloquea el botón mientras
 *       exista) — borrado por el mismo script al terminar (trap EXIT)
 *   backup-cron.sh (tras cada corrida) → hwe-backup-status.json
 *       → BackupConfig::readStatus() (panel) y BackupNotify (WP-Cron)
 *
 * Si el archivo no existe (instalación nueva), backup-entrypoint.sh usa
 * sus propios valores por defecto.
 */
class BackupConfig {

    private const CONFIG_FILE = 'hwe-backup-config.json';
    private const TRIGGER_FILE = 'hwe-backup-trigger.json';
    private const STATUS_FILE = 'hwe-backup-status.json';
    private const RUNNING_FILE = 'hwe-backup-running.json';

    /**
     * Antigüedad máxima que se confía en hwe-backup-running.json. Un backup
     * real (BD + uploads de este catálogo) tarda segundos/pocos minutos; 30
     * min es margen de sobra. Pasado ese umbral se asume que backup-cron.sh
     * murió sin limpiar (OOM-kill, contenedor detenido a mitad de corrida) y
     * se trata como "no en curso" para no dejar el botón bloqueado para
     * siempre.
     */
    private const RUNNING_STALE_SECONDS = 30 * 60;

    /** Nombres de artefactos de backup reales (no de control) que expone listArtifacts(). */
    private const ARTIFACT_PATTERN = '/^(db|uploads|config)-\d{8}-\d{6}\.(sql\.gz|tar\.gz|json)$/';

    /**
     * Directorio del puente. En producción es HWE_BACKUPS_DIR (definido en
     * docker-compose.prod.yml para `wordpress` y `wp-cron`, fuera del
     * docroot). Fuera de Docker (dev/híbrido, donde el contenedor `backup`
     * no corre) se crea un directorio hermano de wp-content bajo demanda —
     * no pasa nada si nadie más lo lee.
     */
    private static function baseDir(): string {
        $dir = defined('HWE_BACKUPS_DIR') && HWE_BACKUPS_DIR
            ? HWE_BACKUPS_DIR
            : WP_CONTENT_DIR . '/../hwe-backups';
        if (!is_dir($dir)) {
            // @ suprime el warning ante una condición de carrera (dos
            // requests concurrentes creándolo a la vez); mkdir() recursivo
            // no falla en ese caso salvo por permisos reales, que sí
            // queremos ver en los logs de PHP.
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function path(string $filename): string {
        return self::baseDir() . '/' . $filename;
    }

    /**
     * Escribe la configuración de backup como JSON.
     * Se llama desde AdminSave::handle() tras guardar el formulario.
     *
     * @param array $cfg Sección 'backups' ya sanitizada de wp_options.
     */
    public static function write(array $cfg): void {
        $enabled        = !empty($cfg['enabled']);
        $schedule       = $cfg['schedule'] ?? 'daily';
        $hourUtc        = max(0, min(23, (int) ($cfg['hour_utc'] ?? 3)));
        // Vacío o basura = 14, no 1: `(int) ''` es 0 y el max(1, …) de antes
        // convertía un campo borrado en «retener un día».
        $retainRaw      = (int) ($cfg['retain_days'] ?? 0);
        $retainDays     = $retainRaw >= 1 ? min($retainRaw, 365) : 14;
        $keepOnlyLatest = !empty($cfg['keep_only_latest']);
        $includeUploads = !empty($cfg['include_uploads']);
        $excludeThumbs  = !empty($cfg['exclude_thumbnails']);
        $includeConfig  = !empty($cfg['include_config_export']);
        $notifyEmail    = sanitize_email($cfg['notify_email'] ?? '');

        $data = [
            'enabled'               => $enabled,
            'schedule'              => $schedule,
            'cron'                  => self::buildCronExpr($schedule, $hourUtc),
            'hour_utc'              => $hourUtc,
            'retain_days'           => $retainDays,
            'keep_only_latest'      => $keepOnlyLatest,
            'include_uploads'       => $includeUploads,
            'exclude_thumbnails'    => $excludeThumbs,
            'include_config_export' => $includeConfig,
            'notify_email'          => $notifyEmail,
            'updated_at'            => date('c'),
            '_note'                 => 'Generado por HWE Control Center. No editar manualmente.',
        ];

        $written = file_put_contents(
            self::path(self::CONFIG_FILE),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        if ($written === false) {
            error_log(sprintf(
                '[hwe] control-center: no se pudo escribir %s (permisos/espacio en disco).',
                self::path(self::CONFIG_FILE)
            ));
        }
    }

    /**
     * Lee la configuración actual del archivo JSON.
     * Devuelve un array con valores por defecto si el archivo no existe.
     *
     * @return array<string,mixed>
     */
    public static function read(): array {
        return self::readJsonFile(self::path(self::CONFIG_FILE), self::defaults());
    }

    /**
     * Escribe el archivo que dispara una corrida inmediata en el contenedor
     * `backup` (consumido y borrado por backup-trigger-watch.sh, corrido por
     * crond cada minuto — hasta 60s de latencia).
     */
    public static function writeTrigger(int $userId): void {
        file_put_contents(
            self::path(self::TRIGGER_FILE),
            json_encode([
                'requested_at' => date('c'),
                'requested_by' => $userId,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Resultado de la última corrida (programada o manual), escrito por
     * backup-cron.sh. Array vacío si nunca ha corrido ningún backup.
     *
     * @return array<string,mixed>
     */
    public static function readStatus(): array {
        return self::readJsonFile(self::path(self::STATUS_FILE), []);
    }

    /**
     * Corrida en curso ahora mismo (escrita por backup-cron.sh al adquirir
     * su lock, borrada al terminar — ver el `trap` en ese script). Array
     * vacío si no hay ninguna corrida activa, incluida una marca obsoleta
     * (ver RUNNING_STALE_SECONDS): el panel solo debe bloquear "Backup
     * ahora" ante una corrida real.
     *
     * @return array{started_at?:string,trigger?:string}
     */
    public static function readRunning(): array {
        $running = self::readJsonFile(self::path(self::RUNNING_FILE), []);
        if (empty($running['started_at'])) {
            return [];
        }

        $startedAt = strtotime((string) $running['started_at']);
        if ($startedAt === false || (time() - $startedAt) > self::RUNNING_STALE_SECONDS) {
            return [];
        }

        return $running;
    }

    /**
     * Backups reales (no archivos de control) disponibles en baseDir(),
     * más recientes primero.
     *
     * @return list<array{name:string,type:string,size:int,mtime:int}>
     */
    public static function listArtifacts(): array {
        $dir = self::baseDir();
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        $artifacts = [];
        foreach ($entries as $entry) {
            if (!self::isValidArtifactName($entry)) {
                continue;
            }
            $fullPath = $dir . '/' . $entry;
            $artifacts[] = [
                'name'  => $entry,
                'type'  => str_starts_with($entry, 'db-') ? 'db'
                         : (str_starts_with($entry, 'uploads-') ? 'uploads' : 'config'),
                'size'  => (int) filesize($fullPath),
                'mtime' => (int) filemtime($fullPath),
            ];
        }

        usort($artifacts, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $artifacts;
    }

    /** Nombre de archivo válido para un artefacto real de backup (sin rutas, sin `..`). */
    public static function isValidArtifactName(string $name): bool {
        return (bool) preg_match(self::ARTIFACT_PATTERN, $name);
    }

    /**
     * Ruta absoluta de un artefacto YA VALIDADO con isValidArtifactName().
     * No vuelve a validar: quien llama es responsable de validar antes.
     */
    public static function artifactPath(string $name): string {
        return self::path($name);
    }

    private static function readJsonFile(string $path, array $default): array {
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        try {
            $decoded = json_decode($raw, true, 10, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $default;
        } catch (\JsonException) {
            return $default;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Construye la expresión cron a partir de la frecuencia y la hora.
     *
     * Ejemplos:
     *   daily      + 3  → "0 3 * * *"
     *   twice_daily+ 3  → "0 3,15 * * *"
     *   weekly     + 3  → "0 3 * * 0"    (domingo)
     */
    private static function buildCronExpr(string $schedule, int $hour): string {
        return match ($schedule) {
            'twice_daily' => sprintf('0 %d,%d * * *', $hour, ($hour + 12) % 24),
            'weekly'      => sprintf('0 %d * * 0', $hour),
            default       => sprintf('0 %d * * *', $hour),
        };
    }

    private static function defaults(): array {
        return [
            'enabled'               => true,
            'schedule'              => 'daily',
            'cron'                  => '0 3 * * *',
            'hour_utc'              => 3,
            'retain_days'           => 14,
            'keep_only_latest'      => false,
            'include_uploads'       => true,
            'exclude_thumbnails'    => false,
            'include_config_export' => true,
            'notify_email'          => '',
        ];
    }
}
