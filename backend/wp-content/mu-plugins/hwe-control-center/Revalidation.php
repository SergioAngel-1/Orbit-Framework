<?php

namespace HWE\ControlCenter;

/**
 * Dispara la revalidación ISR del tag `site-config` en el frontend Next.js.
 *
 * Usa la misma URL del frontend definida en HEADLESS_FRONTEND_URL y firma la
 * petición con HWE_REVALIDATION_SECRET (compartido con el BFF) para que el
 * endpoint `/api/revalidate` confíe en la petición.
 *
 * Si la constante no está definida, la revalidación se omite silenciosamente.
 */
class Revalidation {

    public static function trigger(): void {
        $secret = defined('HWE_REVALIDATION_SECRET') ? HWE_REVALIDATION_SECRET : '';
        if ($secret === '') {
            return;
        }

        $frontendUrl = defined('HEADLESS_FRONTEND_URL')
            ? rtrim(HEADLESS_FRONTEND_URL, '/')
            : 'http://localhost:3000';

        $endpoint = $frontendUrl . '/api/revalidate';
        $body     = json_encode(['tag' => 'site-config', 'source' => 'hwe-control-center'], JSON_THROW_ON_ERROR);
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
