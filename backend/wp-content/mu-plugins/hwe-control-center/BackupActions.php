<?php

namespace HWE\ControlCenter;

/**
 * Acciones interactivas de la tab "Backups automáticos": disparar un backup
 * manual, listar/descargar los backups recientes, y exportar/importar la
 * configuración del panel (ver handleConfigExport/Import). Vive aparte de
 * AdminPage.php (que ya es grande) siguiendo el mismo patrón que
 * WooCurrencySync/Revalidation: una clase por responsabilidad, invocada
 * desde AdminPage.
 *
 * Cada acción usa su propio <form> externo al formulario principal de
 * guardado (declarados en AdminPage::renderPage()): HTML prohíbe <form>
 * anidados, y estas acciones van a admin-post.php, no al guardado normal.
 */
class BackupActions {

    private const NONCE_RUN_NOW = 'hwe_backup_run_now';
    private const NONCE_DOWNLOAD = 'hwe_backup_download';
    private const NONCE_CONFIG_EXPORT = 'hwe_config_export';
    private const NONCE_CONFIG_IMPORT = 'hwe_config_import';

    public static function register(): void {
        add_action('admin_post_hwe_backup_run_now', [self::class, 'handleRunNow']);
        add_action('admin_post_hwe_backup_download', [self::class, 'handleDownload']);
        add_action('admin_post_hwe_config_export', [self::class, 'handleConfigExport']);
        add_action('admin_post_hwe_config_import', [self::class, 'handleConfigImport']);
    }

    /** HTML inyectado en $sections['backups'] desde AdminPage::renderPage(). */
    public static function render(): string {
        return self::renderNotice()
            . self::renderRunNow()
            . self::renderRecentBackups()
            . self::renderConfigExportImport();
    }

    // -------------------------------------------------------------------------
    // Aviso (tras un redirect de cualquiera de las acciones)
    // -------------------------------------------------------------------------

    /** @return array<string,array{0:string,1:string}> */
    private static function noticeMessages(): array {
        return [
            'run_now:ok'              => ['success', __('Backup solicitado. Se ejecutará en menos de un minuto.', 'hwe')],
            'run_now:already_running' => ['warning', __('Ya hay un backup en curso, espera a que termine antes de solicitar otro.', 'hwe')],
            'download:invalid'        => ['error', __('Nombre de archivo inválido.', 'hwe')],
            'download:missing'        => ['error', __('Ese archivo ya no existe.', 'hwe')],
            'import:ok'               => ['success', __('Configuración importada correctamente.', 'hwe')],
            'import:ok_no_secrets'    => ['success', __('Configuración importada. Los secretos (contraseñas/API keys) no se pudieron leer — probablemente el export viene de otro servidor; vuelve a introducirlos a mano en sus secciones.', 'hwe')],
            'import:invalid'          => ['error', __('El archivo subido no tiene el formato esperado de un export de HWE Control Center.', 'hwe')],
            'import:missing_confirm'  => ['error', __('Debes marcar la casilla de confirmación para importar (reemplaza la configuración actual).', 'hwe')],
        ];
    }

    private static function renderNotice(): string {
        $action = isset($_GET['hwe_backup_action'])
            ? sanitize_text_field(wp_unslash($_GET['hwe_backup_action']))
            : '';
        $status = isset($_GET['hwe_backup_status'])
            ? sanitize_text_field(wp_unslash($_GET['hwe_backup_status']))
            : '';
        if ($action === '' || $status === '') {
            return '';
        }

        $key = $action . ':' . $status;
        $messages = self::noticeMessages();
        if (!isset($messages[$key])) {
            return '';
        }
        [$type, $message] = $messages[$key];
        return sprintf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    private static function redirect(string $action, string $status): void {
        wp_safe_redirect(add_query_arg(
            [
                'page'              => 'hwe-config',
                'hwe_backup_action' => $action,
                'hwe_backup_status' => $status,
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Estado + "Backup ahora"
    // -------------------------------------------------------------------------

    private static function renderRunNow(): string {
        $status  = BackupConfig::readStatus();
        $running = BackupConfig::readRunning();
        $nonce   = esc_attr(wp_create_nonce(self::NONCE_RUN_NOW));

        if (!empty($running)) {
            $startedAt = isset($running['started_at']) ? (string) $running['started_at'] : '?';
            $statusHtml = '<div class="hwe-smtp-status">'
                . '<span class="dashicons dashicons-update spin"></span>'
                . '<p>' . esc_html(sprintf(
                    /* translators: %s: fecha ISO-8601 de inicio */
                    __('Backup en curso (iniciado a las %s)… la página se actualizará sola al terminar.', 'hwe'),
                    $startedAt
                )) . '</p>'
                . '</div>';
        } elseif (empty($status)) {
            $statusHtml = '<div class="hwe-smtp-status"><span class="dashicons dashicons-info"></span><p>'
                . esc_html__('Todavía no se ha ejecutado ningún backup.', 'hwe') . '</p></div>';
        } else {
            $ok      = !empty($status['success']);
            $icon    = $ok ? 'yes-alt' : 'warning';
            $lastRun = isset($status['last_run_at']) ? (string) $status['last_run_at'] : '?';
            $trigger = isset($status['trigger']) ? (string) $status['trigger'] : '?';
            $summary = isset($status['summary']) ? (string) $status['summary'] : '';
            $statusHtml = '<div class="hwe-smtp-status">'
                . '<span class="dashicons dashicons-' . esc_attr($icon) . '"></span>'
                . '<p>' . esc_html(sprintf(
                    /* translators: 1: fecha ISO-8601, 2: scheduled/manual, 3: resumen */
                    __('Último backup: %1$s (%2$s). %3$s', 'hwe'),
                    $lastRun,
                    $trigger,
                    $summary
                )) . '</p>'
                . '</div>';
        }

        ob_start();
        ?>
        <div class="hwe-smtp-test">
            <div class="hwe-panel-header">
                <span class="dashicons dashicons-backup"></span>
                <h2><?php echo esc_html__('Estado y backup manual', 'hwe'); ?></h2>
            </div>
            <div class="hwe-panel-body">
                <?php echo $statusHtml; ?>
                <p class="submit">
                    <input type="hidden" form="hwe-backup-run-now-form" name="action" value="hwe_backup_run_now">
                    <input type="hidden" form="hwe-backup-run-now-form" name="_wpnonce_backup_run_now" value="<?php echo $nonce; ?>">
                    <button type="submit" form="hwe-backup-run-now-form" class="button button-secondary"<?php echo !empty($running) ? ' disabled' : ''; ?>><span class="dashicons dashicons-update"></span> <?php echo !empty($running) ? esc_html__('Backup en curso…', 'hwe') : esc_html__('Backup ahora', 'hwe'); ?></button>
                </p>
                <?php if (!empty($running)) : ?>
                    <script>setTimeout(function () { window.location.reload(); }, 15000);</script>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handleRunNow(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'hwe'));
        }
        check_admin_referer(self::NONCE_RUN_NOW, '_wpnonce_backup_run_now');

        if (!empty(BackupConfig::readRunning())) {
            self::redirect('run_now', 'already_running');
        }

        BackupConfig::writeTrigger(get_current_user_id());

        self::redirect('run_now', 'ok');
    }

    // -------------------------------------------------------------------------
    // Backups recientes (listado + descarga)
    // -------------------------------------------------------------------------

    private static function renderRecentBackups(): string {
        $artifacts = BackupConfig::listArtifacts();

        ob_start();
        ?>
        <div class="hwe-smtp-test">
            <div class="hwe-panel-header">
                <span class="dashicons dashicons-media-archive"></span>
                <h2><?php echo esc_html__('Backups recientes', 'hwe'); ?></h2>
            </div>
            <div class="hwe-panel-body">
                <?php if (empty($artifacts)) : ?>
                    <p><?php echo esc_html__('Todavía no hay backups en este servidor.', 'hwe'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Archivo', 'hwe'); ?></th>
                                <th><?php echo esc_html__('Tipo', 'hwe'); ?></th>
                                <th><?php echo esc_html__('Tamaño', 'hwe'); ?></th>
                                <th><?php echo esc_html__('Fecha', 'hwe'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($artifacts as $artifact) : ?>
                            <tr>
                                <td><?php echo esc_html($artifact['name']); ?></td>
                                <td><?php echo esc_html($artifact['type']); ?></td>
                                <td><?php echo esc_html(size_format($artifact['size'])); ?></td>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i', $artifact['mtime'])); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(
                                        [
                                            'action'   => 'hwe_backup_download',
                                            'file'     => $artifact['name'],
                                            '_wpnonce' => wp_create_nonce(self::NONCE_DOWNLOAD),
                                        ],
                                        admin_url('admin-post.php')
                                    )); ?>" class="button button-small">
                                        <?php echo esc_html__('Descargar', 'hwe'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handleDownload(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'hwe'));
        }
        check_admin_referer(self::NONCE_DOWNLOAD);

        $file = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
        if (!BackupConfig::isValidArtifactName($file)) {
            self::redirect('download', 'invalid');
        }

        $path = BackupConfig::artifactPath($file);
        if (!is_file($path)) {
            self::redirect('download', 'missing');
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    // -------------------------------------------------------------------------
    // Export/import manual de la configuración del panel
    // -------------------------------------------------------------------------

    private static function renderConfigExportImport(): string {
        $exportNonce = esc_attr(wp_create_nonce(self::NONCE_CONFIG_EXPORT));
        $importNonce = esc_attr(wp_create_nonce(self::NONCE_CONFIG_IMPORT));

        ob_start();
        ?>
        <div class="hwe-smtp-test">
            <div class="hwe-panel-header">
                <span class="dashicons dashicons-admin-generic"></span>
                <h2><?php echo esc_html__('Configuración del panel', 'hwe'); ?></h2>
            </div>
            <div class="hwe-panel-body">
                <p><?php echo esc_html__('Exporta o importa la configuración de HWE Control Center (marca, e-commerce, integraciones...), independiente de los backups de base de datos.', 'hwe'); ?></p>

                <p class="submit">
                    <a href="<?php echo esc_url(add_query_arg(
                        [
                            'action'   => 'hwe_config_export',
                            '_wpnonce' => $exportNonce,
                        ],
                        admin_url('admin-post.php')
                    )); ?>" class="button button-secondary"><span class="dashicons dashicons-download"></span> <?php echo esc_html__('Exportar configuración ahora', 'hwe'); ?></a>
                </p>

                <hr>

                <div class="hwe-field-row">
                    <input type="file" form="hwe-config-import-form" name="hwe_config_import_file" accept="application/json">
                </div>
                <label>
                    <input type="checkbox" form="hwe-config-import-form" name="hwe_config_import_confirm" value="1">
                    <?php echo esc_html__('Confirmo que quiero reemplazar la configuración actual con este archivo.', 'hwe'); ?>
                </label>
                <p class="submit">
                    <input type="hidden" form="hwe-config-import-form" name="action" value="hwe_config_import">
                    <input type="hidden" form="hwe-config-import-form" name="_wpnonce_config_import" value="<?php echo $importNonce; ?>">
                    <button type="submit" form="hwe-config-import-form" class="button button-secondary"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Importar configuración', 'hwe'); ?></button>
                </p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handleConfigExport(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'hwe'));
        }
        check_admin_referer(self::NONCE_CONFIG_EXPORT);

        $payload = self::buildConfigExportPayload();
        $stamp   = date('Ymd-His');
        $json    = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Deja también una copia en el directorio de backups, para que
        // aparezca en "Backups recientes" igual que un export programado.
        file_put_contents(BackupConfig::artifactPath("config-{$stamp}.json"), $json);

        nocache_headers();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="hwe-config-' . $stamp . '.json"');
        header('Content-Length: ' . (string) strlen($json));
        echo $json;
        exit;
    }

    /**
     * @return array{_hwe_export:true,version:int,exported_at:string,hwe_config:array,hwe_config_secrets:array}
     */
    private static function buildConfigExportPayload(): array {
        return [
            '_hwe_export'        => true,
            'version'            => 1,
            'exported_at'        => date('c'),
            'hwe_config'         => Storage::getAll(),
            // Ciphertext tal cual (NO se desencripta aquí) — ver
            // maybeImportSecrets() para la verificación al importar.
            'hwe_config_secrets' => get_option('hwe_config_secrets', []),
        ];
    }

    public static function handleConfigImport(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'hwe'));
        }
        check_admin_referer(self::NONCE_CONFIG_IMPORT, '_wpnonce_config_import');

        if (empty($_POST['hwe_config_import_confirm'])) {
            self::redirect('import', 'missing_confirm');
        }

        if (!isset($_FILES['hwe_config_import_file']) || !is_uploaded_file($_FILES['hwe_config_import_file']['tmp_name'] ?? '')) {
            self::redirect('import', 'invalid');
        }

        $raw = file_get_contents($_FILES['hwe_config_import_file']['tmp_name']);
        try {
            $decoded = $raw === false ? null : json_decode($raw, true, 20, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (
            !is_array($decoded)
            || empty($decoded['_hwe_export'])
            || !is_array($decoded['hwe_config'] ?? null)
        ) {
            self::redirect('import', 'invalid');
        }

        $status = self::importConfig($decoded['hwe_config'], $decoded['hwe_config_secrets'] ?? null);
        self::redirect('import', $status);
    }

    /**
     * Aplica un export de config. Público para poder probarlo; quien llama ya
     * comprobó capacidad y nonce.
     *
     *  · La config pasa por el saneado del schema (modo *sparse*: lo que el export
     *    no trae se conserva) y por el mismo camino que un guardado del panel
     *    (`AdminSave::persist`): sincroniza Woo, avisa a los módulos y revalida.
     *    Un export manipulado no mete en `hwe_config` nada que el panel no aceptaría.
     *  · Los secretos se descifran ANTES de escribir nada: solo se instalan si TODOS
     *    los no vacíos descifran con la clave local, y se fusionan sin pisar con
     *    vacíos. Si no, los secretos guardados quedan intactos (antes se
     *    sobrescribían y, al «revertir», se vaciaban).
     *
     * @return 'ok'|'ok_no_secrets'
     */
    public static function importConfig(array $config, mixed $secretsTree): string {
        [$publicData] = AdminSave::sanitizeAndSplit(Schema::get(), $config, [], true);

        $secrets = null;
        if (is_array($secretsTree) && $secretsTree !== []) {
            $secrets = SecretsStorage::decryptImported($secretsTree);
            if ($secrets === null) {
                error_log('[hwe] control-center: importación de config sin secretos: no descifran con la clave de este servidor; los guardados no se han tocado.');
            }
        }

        AdminSave::persist($publicData, $secrets ?? [], $config);

        return ($secrets === null && is_array($secretsTree) && self::collectNonEmptyStrings($secretsTree) !== [])
            ? 'ok_no_secrets'
            : 'ok';
    }

    /** @return list<string> */
    private static function collectNonEmptyStrings(array $tree): array {
        $result = [];
        array_walk_recursive($tree, static function ($value) use (&$result): void {
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        });
        return $result;
    }
}
