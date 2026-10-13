<?php

namespace HWE\ControlCenter;

/**
 * Dispara la revalidación ISR de un tag en el frontend Next.js.
 *
 * Usa la misma URL del frontend definida en HEADLESS_FRONTEND_URL y firma la
 * petición con HWE_REVALIDATION_SECRET (compartido con el BFF) para que el
 * endpoint `/api/revalidate` confíe en la petición.
 *
 * TAGS. El núcleo acepta `site-config`; una instancia o un módulo registra los suyos con el
 * filtro `hwe_revalidation_tags` y el frontend debe declararlos también (ver
 * mu-plugins/instance/README.md). Un tag no registrado no se envía: el frontend lo rechazaría.
 *
 * SIN FALLOS SILENCIOSOS. Si falta el secreto, o se pide un tag no registrado, no se envía nada
 * y se registra el motivo (una vez por proceso): antes la revalidación «se omitía
 * silenciosamente» y el sitio seguía sirviendo la config vieja sin que nada lo dijera.
 */
class Revalidation {

    /**
     * Tags que acepta el núcleo (los demás los registran instancias y módulos): la config,
     * los banners, las entradas del blog y los menús de navegación.
     */
    private const CORE_TAGS = ['site-config', 'banners', 'posts', 'menus'];

    /** Formato de un tag: minúsculas, dígitos, punto, guion y guion bajo; hasta 64 caracteres. */
    private const TAG_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /**
     * Tags que se pueden revalidar: los del núcleo más los del filtro `hwe_revalidation_tags`.
     * Se descartan (y se avisa) los que no son texto o no cumplen el formato; los del núcleo
     * siempre están, aunque un filtro los quite.
     *
     * @return list<string>
     */
    public static function allowedTags(): array {
        $filtered = apply_filters('hwe_revalidation_tags', self::CORE_TAGS);

        if (!is_array($filtered)) {
            Log::once(
                'tags:not-array',
                sprintf(
                    'el filtro hwe_revalidation_tags debe devolver una lista de tags (devolvió %s); solo se aceptan los del núcleo.',
                    get_debug_type($filtered)
                )
            );
            return self::CORE_TAGS;
        }

        $tags = array_fill_keys(self::CORE_TAGS, true);
        foreach ($filtered as $tag) {
            if (!is_string($tag) || preg_match(self::TAG_PATTERN, $tag) !== 1) {
                Log::once(
                    'tags:bad:' . (is_scalar($tag) ? (string) $tag : get_debug_type($tag)),
                    sprintf(
                        'tag descartado de hwe_revalidation_tags: %s (formato: minúsculas, dígitos, «.», «-» o «_», máximo 64 caracteres).',
                        is_scalar($tag) ? '«' . (string) $tag . '»' : get_debug_type($tag)
                    )
                );
                continue;
            }
            $tags[$tag] = true;
        }

        return array_keys($tags);
    }

    /** Pide al frontend que invalide `$tag` (por defecto la config del sitio). */
    public static function trigger(string $tag = 'site-config'): void {
        $secret = defined('HWE_REVALIDATION_SECRET') ? HWE_REVALIDATION_SECRET : '';
        if ($secret === '') {
            Log::once(
                'revalidation:no-secret',
                'HWE_REVALIDATION_SECRET no está definido: no se revalida el frontend, así que los cambios pueden tardar hasta 5 minutos en verse.'
            );
            return;
        }

        if (!in_array($tag, self::allowedTags(), true)) {
            Log::once(
                'revalidation:tag:' . $tag,
                sprintf('revalidación omitida: el tag «%s» no está registrado en el filtro hwe_revalidation_tags.', $tag)
            );
            return;
        }

        $frontendUrl = defined('HEADLESS_FRONTEND_URL')
            ? rtrim(HEADLESS_FRONTEND_URL, '/')
            : 'http://localhost:3000';

        $endpoint = $frontendUrl . '/api/revalidate';
        $body     = json_encode(['tag' => $tag, 'source' => 'hwe-control-center'], JSON_THROW_ON_ERROR);
        $sig      = 'sha256=' . hash_hmac('sha256', $body, $secret);

        // Petición no bloqueante (fire-and-forget); el resultado no se necesita aquí.
        wp_remote_post($endpoint, [
            'timeout'  => 8,
            'blocking' => false,
            'headers'  => [
                'Content-Type'     => 'application/json',
                'X-HWE-Signature'  => $sig,
            ],
            'body'     => $body,
        ]);
    }
}
