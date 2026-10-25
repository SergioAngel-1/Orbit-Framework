<?php

namespace HWE\ControlCenter;

/**
 * URL del frontend para llamadas SERVIDOR A SERVIDOR (revalidación ISR, medición de
 * consentimiento). `backend/scripts/setup.sh` aplica la misma precedencia en shell para los
 * webhooks que crea.
 *
 * Prioridad:
 *   1. HEADLESS_FRONTEND_INTERNAL_URL (p. ej. http://frontend:3000 en Docker).
 *   2. HEADLESS_FRONTEND_URL         (la pública; en producción llega por Caddy).
 *   3. http://localhost:3000         -> fallback de desarrollo.
 *
 * Por qué existe: dentro de un contenedor, `localhost` es el propio contenedor, así que
 * WordPress llamándose a la URL pública de desarrollo (`http://localhost:3000`) nunca
 * alcanza al frontend. La variable interna apunta al servicio en la red de Compose.
 *
 * NO usar para enlaces del navegador (correos), CORS ni orígenes permitidos: ahí la URL
 * correcta es la pública (`hwe_allowed_origins()`), porque la interna no resuelve desde
 * fuera de la red de Compose.
 */
final class FrontendUrl {

    /** Último recurso: el frontend nativo en desarrollo, como antes de la variable interna. */
    private const FALLBACK = 'http://localhost:3000';

    /** URL sin barra final. */
    public static function internal(): string {
        foreach (['HEADLESS_FRONTEND_INTERNAL_URL', 'HEADLESS_FRONTEND_URL'] as $constant) {
            if (defined($constant) && (string) constant($constant) !== '') {
                return rtrim((string) constant($constant), '/');
            }
        }

        return self::FALLBACK;
    }
}
