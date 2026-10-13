<?php

namespace HWE\ControlCenter;

/**
 * Notificación por email tras un backup, vía wp_mail() — NO desde el
 * contenedor `backup`: ese contenedor no tiene PHP y no puede llamar
 * wp_mail()/hwe-smtp.php. Se dispara desde un evento WP-Cron, ejecutado de
 * forma fiable por el contenedor `wp-cron` (wp cron event run --due-now cada
 * 60s — ver docker-compose.prod.yml), que compara hwe-backup-status.json
 * contra la última notificación ya enviada.
 */
class BackupNotify {

    private const EVENT_HOOK = 'hwe_backup_check_notify';
    private const CRON_INTERVAL = 'hwe_five_minutes';
    private const LAST_NOTIFIED_OPTION = 'hwe_backup_last_notified';

    public static function register(): void {
        add_filter('cron_schedules', [self::class, 'addCronInterval']);
        add_action(self::EVENT_HOOK, [self::class, 'checkAndNotify']);
        // register() ya se invoca desde dentro del hook 'init' (bootstrap del
        // mu-plugin, prioridad 20): volver a engancharse a 'init' aquí nunca
        // se ejecutaría (WordPress ya pasó por las prioridades <= 20 de este
        // mismo disparo de do_action('init')), así que se llama directo.
        self::ensureScheduled();
    }

    /**
     * @param array<string,array{interval:int,display:string}> $schedules
     * @return array<string,array{interval:int,display:string}>
     */
    public static function addCronInterval(array $schedules): array {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 300,
            'display'  => __('Cada 5 minutos (HWE backups)', 'hwe'),
        ];
        return $schedules;
    }

    public static function ensureScheduled(): void {
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::EVENT_HOOK);
        }
    }

    public static function checkAndNotify(): void {
        $status = BackupConfig::readStatus();
        $lastNotified = (string) get_option(self::LAST_NOTIFIED_OPTION, '');

        if (!self::shouldNotify($status, $lastNotified)) {
            return;
        }

        $email = trim((string) Storage::get(['backups', 'notify_email'], ''));
        if ($email === '') {
            // Nada que enviar, pero se marca como "visto" para no volver a
            // evaluar esta misma corrida en el próximo tick.
            update_option(self::LAST_NOTIFIED_OPTION, $status['last_run_at'], false);
            return;
        }

        wp_mail(
            $email,
            self::renderSubject($status),
            self::renderEmailBody($status),
            ['Content-Type: text/html; charset=UTF-8']
        );

        update_option(self::LAST_NOTIFIED_OPTION, $status['last_run_at'], false);
    }

    /**
     * Pura (sin dependencias de WordPress) para poder verificarla de forma
     * aislada: hay una corrida nueva que notificar si hay `last_run_at` y es
     * distinto del último ya notificado.
     *
     * @param array<string,mixed> $status
     */
    public static function shouldNotify(array $status, string $lastNotified): bool {
        $lastRunAt = (string) ($status['last_run_at'] ?? '');
        return $lastRunAt !== '' && $lastRunAt !== $lastNotified;
    }

    /** @param array<string,mixed> $status */
    private static function renderSubject(array $status): string {
        $ok = !empty($status['success']);
        return sprintf('[%s] Backup %s', get_bloginfo('name'), $ok ? 'OK' : 'FALLÓ');
    }

    /** @param array<string,mixed> $status */
    private static function renderEmailBody(array $status): string {
        $summary = esc_html((string) ($status['summary'] ?? ''));
        return '<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px">'
            . '<h2 style="margin:0 0 16px">' . esc_html(get_bloginfo('name')) . ' — Backup</h2>'
            . '<p>' . $summary . '</p>'
            . '<p style="color:#666;font-size:13px">Generado automáticamente por HWE Control Center.</p>'
            . '</div>';
    }
}
