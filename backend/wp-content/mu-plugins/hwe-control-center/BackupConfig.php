<?php

namespace HWE\ControlCenter;

/**
 * Puente entre la configuración de backup almacenada en wp_options y el
 * contenedor `backup` de Docker, que no tiene acceso directo a la BD.
 *
 * Al guardar la sección "Backups" en el panel, escribe un JSON en
 * wp-content/hwe-backup-config.json. El contenedor `backup` monta
 * wp-content como volumen de solo lectura y lee ese archivo al arrancar.
 *
 * Flujo completo:
 *   wp-admin (guardar) → Storage::setAll() → BackupConfig::write()
 *       → hwe-backup-config.json (en wp-content)
 *       → backup-entrypoint.sh lo lee al arrancar y configura cron
 *
 * Si el archivo no existe (instalación nueva), backup-entrypoint.sh usa
 * sus propios valores por defecto.
 */
class BackupConfig {

    /** Ruta del archivo de configuración dentro de wp-content. */
    public const CONFIG_PATH = WP_CONTENT_DIR . '/hwe-backup-config.json';

    /**
     * Escribe la configuración de backup como JSON.
     * Se llama desde AdminPage::handleSave() tras guardar el formulario.
     *
     * @param array $cfg Sección 'backups' ya sanitizada de wp_options.
     */
    public static function write(array $cfg): void {
        $enabled        = !empty($cfg['enabled']);
        $schedule       = $cfg['schedule'] ?? 'daily';
        $hourUtc        = max(0, min(23, (int) ($cfg['hour_utc'] ?? 3)));
        $retainDays     = max(1, (int) ($cfg['retain_days'] ?? 14));
        $includeUploads = !empty($cfg['include_uploads']);
        $notifyEmail    = sanitize_email($cfg['notify_email'] ?? '');

        $data = [
            'enabled'         => $enabled,
            'schedule'        => $schedule,
            'cron'            => self::buildCronExpr($schedule, $hourUtc),
            'hour_utc'        => $hourUtc,
            'retain_days'     => $retainDays,
            'include_uploads' => $includeUploads,
            'notify_email'    => $notifyEmail,
            'updated_at'      => date('c'),
            '_note'           => 'Generado por HWE Control Center. No editar manualmente.',
        ];

        file_put_contents(
            self::CONFIG_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Lee la configuración actual del archivo JSON.
     * Devuelve un array con valores por defecto si el archivo no existe.
     *
     * @return array<string,mixed>
     */
    public static function read(): array {
        if (!file_exists(self::CONFIG_PATH)) {
            return self::defaults();
        }
        $raw = file_get_contents(self::CONFIG_PATH);
        if ($raw === false) {
            return self::defaults();
        }
        try {
            return json_decode($raw, true, 10, JSON_THROW_ON_ERROR) ?? self::defaults();
        } catch (\JsonException) {
            return self::defaults();
        }
    }

    /** Ejecuta un backup ahora mismo desde PHP (útil para botón "Backup manual"). */
    public static function triggerNow(): bool {
        $script = ABSPATH . '../scripts/backup-cron.sh'; // ruta aproximada; ajustar si cambia.
        if (!file_exists($script)) {
            return false;
        }
        exec("sh " . escapeshellarg($script) . " >> /var/log/hwe-backup.log 2>&1 &");
        return true;
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
            'enabled'         => true,
            'schedule'        => 'daily',
            'cron'            => '0 3 * * *',
            'hour_utc'        => 3,
            'retain_days'     => 14,
            'include_uploads' => true,
            'notify_email'    => '',
        ];
    }
}
