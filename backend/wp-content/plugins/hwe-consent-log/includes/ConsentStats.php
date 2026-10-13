<?php
/**
 * Medición ANÓNIMA de la respuesta al banner de cookies: de las sesiones que
 * vieron el banner de primera visita, cuántas eligieron «Aceptar todas»,
 * cuántas «Solo necesarias» y cuántas no respondieron. Se pinta arriba de la
 * pantalla «Consentimientos de cookies» (`hwe-consent-log.php`), que es quien
 * carga este archivo.
 *
 * Es OTRA cosa que el registro de esa misma pantalla: esto son totales sin
 * identificar a nadie; aquello, la prueba individual de cada decisión.
 *
 * El dato NO vive en WordPress: lo cuenta el BFF en Redis (un contador por
 * hora, sin IP ni cookie ni identificador; `lib/analytics/consent-stats.ts`
 * en el frontend) para que cada visita no despierte un worker de PHP. Aquí
 * solo se LEE, de `GET /api/consent-stats` con el secreto interno, al abrir
 * la pantalla, y en la zona horaria del sitio (Ajustes → Generales), que es
 * la que agrupa las horas en días.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Consent_Stats {

    /** Periodos ofrecidos, en días. */
    private const PERIODS = [7, 14, 30, 90];
    private const DEFAULT_DAYS = 14;
    /** Caché corta: cambiar de periodo o de página no merece repetir la petición al BFF. */
    private const CACHE_SECONDS = 60;

    public const QUERY_ARG = 'hwe_consent_days';

    /** Periodo pedido en la URL, o el de por defecto. */
    public static function days(): int {
        $days = isset($_GET[self::QUERY_ARG]) ? (int) $_GET[self::QUERY_ARG] : self::DEFAULT_DAYS; // phpcs:ignore WordPress.Security.NonceVerification
        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_DAYS;
    }

    /** @param string $pageUrl Pantalla donde se pinta (conserva su búsqueda y página). */
    public static function render(string $pageUrl): string {
        $days  = self::days();
        $links = [];
        foreach (self::PERIODS as $period) {
            $label   = esc_html(sprintf(__('%d días', 'hwe'), $period));
            $links[] = $period === $days
                ? '<strong aria-current="true">' . $label . '</strong>'
                : '<a href="' . esc_url(add_query_arg(self::QUERY_ARG, $period, $pageUrl)) . '">' . $label . '</a>';
        }
        $periods = '<p class="hwe-consent-periods">' . implode(' · ', $links) . '</p>';

        $result = self::fetch($days);
        if (isset($result['error'])) {
            return $periods . '<p class="hwe-consent-error">' . esc_html($result['error']) . '</p>';
        }

        // La medición distingue la forma del banner (`bar`/`modal`); este
        // resumen las suma. Si algún día conviven, el desglose sale del GET.
        $c = ['shown' => 0, 'accepted' => 0, 'declined' => 0];
        foreach ((array) ($result['totals'] ?? []) as $counts) {
            foreach ($c as $event => $_) {
                $c[$event] += max(0, (int) ($counts[$event] ?? 0));
            }
        }
        // Una decisión cuyo «visto» no llegó (petición perdida) no da negativos.
        $ignored = max(0, $c['shown'] - $c['accepted'] - $c['declined']);

        if ($c['shown'] === 0 && $c['accepted'] === 0 && $c['declined'] === 0) {
            return $periods . '<p class="hwe-consent-empty">' . esc_html__('Sin respuestas registradas en este periodo.', 'hwe') . '</p>';
        }

        $items = [
            [__('Vieron el banner', 'hwe'), $c['shown'], null],
            [__('Aceptar todas', 'hwe'), $c['accepted'], $c['shown']],
            [__('Solo necesarias', 'hwe'), $c['declined'], $c['shown']],
            [__('No respondieron', 'hwe'), $ignored, $c['shown']],
        ];
        $html = '';
        foreach ($items as [$label, $value, $of]) {
            $percent = '';
            if ($of !== null) {
                $percent = ' <span class="hwe-consent-percent">(' . esc_html(
                    $of > 0 ? number_format_i18n(100 * $value / $of, 1) . ' %' : '—'
                ) . ')</span>';
            }
            $html .= sprintf(
                '<li><span>%s</span> <strong>%s</strong>%s</li>',
                esc_html($label),
                esc_html(number_format_i18n($value)),
                $percent
            );
        }

        return $periods . '<ul class="hwe-consent-figures">' . $html . '</ul>';
    }

    /**
     * URL del frontend para llamadas servidor a servidor. En desarrollo con
     * Docker, `HEADLESS_FRONTEND_URL` es `http://localhost:3001` —válida
     * desde el navegador, pero dentro del contenedor de WordPress `localhost`
     * es el propio contenedor—, así que el compose de desarrollo define
     * `HEADLESS_FRONTEND_INTERNAL_URL` con el nombre del servicio. En
     * producción no hace falta: la pública (el dominio) llega igual.
     */
    private static function frontendUrl(): string {
        foreach (['HEADLESS_FRONTEND_INTERNAL_URL', 'HEADLESS_FRONTEND_URL'] as $constant) {
            if (defined($constant) && (string) constant($constant) !== '') {
                return rtrim((string) constant($constant), '/');
            }
        }
        return 'http://localhost:3000';
    }

    /**
     * @return array<string,mixed> El informe del BFF, o ['error' => mensaje].
     */
    private static function fetch(int $days): array {
        $secret = defined('HWE_REVALIDATION_SECRET') ? (string) HWE_REVALIDATION_SECRET : '';
        if ($secret === '') {
            return ['error' => __('Falta HWE_REVALIDATION_SECRET en wp-config: sin él no se puede consultar la medición al frontend.', 'hwe')];
        }

        $timeZone = wp_timezone_string();
        $cacheKey = 'hwe_consent_stats_' . $days . '_' . md5($timeZone);
        $cached   = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $endpoint = add_query_arg(
            ['days' => $days, 'tz' => rawurlencode($timeZone)],
            self::frontendUrl() . '/api/consent-stats'
        );

        $response = wp_remote_get($endpoint, [
            'timeout' => 4,
            'headers' => ['X-HWE-Internal-Secret' => $secret],
        ]);

        if (is_wp_error($response)) {
            return ['error' => sprintf(
                /* translators: %s: mensaje del error de red */
                __('No se pudo consultar la medición al frontend (%s).', 'hwe'),
                $response->get_error_message()
            )];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return ['error' => __('El frontend rechazó la consulta: HWE_REVALIDATION_SECRET no coincide entre WordPress y el frontend.', 'hwe')];
        }
        if ($code !== 200 || !is_array($body)) {
            return ['error' => sprintf(
                /* translators: %d: código HTTP */
                __('El frontend respondió %d al consultar la medición. Si es 503, Redis no está disponible.', 'hwe'),
                $code
            )];
        }

        set_transient($cacheKey, $body, self::CACHE_SECONDS);
        return $body;
    }
}
