<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: integraciones de analítica y marketing, y copias de seguridad.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Integrations {

    /**
     * @return array<string,array>
     */
    private static function integrationGroups(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* INTEGRACIONES                                                        */
            /* ------------------------------------------------------------------ */
            'integrations' => [
                'type'     => 'group',
                'label'    => 'Integraciones',
                'children' => [
                    'analytics_provider' => [
                        'type'    => 'select',
                        'label'   => 'Proveedor de analítica',
                        'default' => 'none',
                        'public'  => true,
                        'options' => ['none' => 'Ninguno', 'ga4' => 'Google Analytics 4', 'plausible' => 'Plausible'],
                    ],
                    'analytics_id'  => ['type' => 'text',   'label' => 'ID de analítica (G-XXXXXXXX)', 'public' => true],
                    'gtm_id'        => [
                        'type'        => 'text',
                        'label'       => 'Google Tag Manager (GTM-XXXXXXX)',
                        'public'      => true,
                        'description' => 'Si lo activas, el contenedor de GTM gestiona el disparo de etiquetas (incluida GA4).',
                    ],
                    'meta_pixel_id' => [
                        'type'        => 'text',
                        'label'       => 'Meta Pixel ID',
                        'public'      => true,
                        'description' => 'ID numérico del píxel (Meta Events Manager). Vacío = desactivado.',
                    ],
                    // Los dos siguientes los lee solo el BFF, por el canal interno
                    // (`IntegrationsInternalApi`): nunca van a la config pública.
                    'meta_capi_token' => [
                        'type'        => 'secret',
                        'label'       => 'Meta Conversions API — token de acceso',
                        'public'      => false,
                        'description' => 'Con él, el servidor envía la compra cuando la pasarela confirma el pago, con el mismo event_id que el píxel del navegador. Vacío = sin envío desde el servidor. Se almacena cifrado; déjalo en blanco al guardar para conservar el valor actual.',
                    ],
                    'meta_capi_test_event_code' => [
                        'type'        => 'text',
                        'label'       => 'Meta Conversions API — código de prueba',
                        'public'      => false,
                        'description' => 'Solo mientras se prueba (empieza por TEST). BÓRRALO al terminar las pruebas.',
                    ],
                    'tiktok_pixel_id' => [
                        'type'        => 'text',
                        'label'       => 'TikTok Pixel ID',
                        'public'      => true,
                        'description' => 'Vacío = desactivado.',
                    ],
                    'hubspot_portal_id' => [
                        'type'        => 'text',
                        'label'       => 'HubSpot — ID del portal (Hub ID)',
                        'public'      => true,
                        'description' => 'Solo el número. El frontend carga el script de seguimiento en todas las páginas, solo con el consentimiento del sitio. Vacío = desactivado.',
                    ],
                    // Mapas: integración genérica que usa el servidor (el módulo de envío la
                    // consume para calcular distancias y geocodificar). Nada sale al navegador.
                    'maps_provider' => [
                        'type'        => 'select',
                        'label'       => 'Mapas — Proveedor',
                        'default'     => 'none',
                        'public'      => false,
                        'options'     => [
                            'none'             => 'Ninguno',
                            'google_maps'      => 'Google Maps',
                            'openrouteservice' => 'OpenRouteService',
                        ],
                        'description' => 'Proveedor con el que el servidor calcula distancias y geocodifica direcciones. Solo lo usa el backend; el navegador nunca recibe la clave ni llama al proveedor. Con «Ninguno» se usa el cálculo en línea recta (y la geocodificación sin proveedor de pago).',
                    ],
                    'maps_api_key' => [
                        'type'        => 'secret',
                        'label'       => 'Mapas — Clave de API',
                        'public'      => false,
                        'description' => 'Se almacena cifrada (AES-256-GCM) y solo la usa el servidor: no viaja al navegador, ni en una URL, ni en un log. Déjala en blanco al guardar para conservar la actual. Para Google necesita las APIs Routes y Geocoding habilitadas en el proyecto y la clave restringida por la IP del servidor (no por referer HTTP).',
                    ],
                    // Lo lee el módulo de registro de consentimientos (purga); no lo necesita el frontend.
                    'consent_retention_years' => [
                        'type'        => 'number',
                        'label'       => 'Conservar el registro de consentimientos (años)',
                        'default'     => '5',
                        'min'         => 0,
                        'max'         => 20,
                        'public'      => false,
                        'description' => 'Años que se conserva la prueba de cada decisión del banner de cookies. 0 = no borrar nunca. Confírmalo con el asesor legal.',
                    ],
                    'smtp_enabled'    => ['type' => 'boolean', 'label' => 'Activar envío por SMTP', 'default' => false, 'public' => false, 'description' => 'Enruta TODO el correo (pedidos, reset de contraseña, verificación) por el SMTP de abajo. Desactivado = envío PHP por defecto (poco fiable en producción).'],
                    'smtp_host'       => ['type' => 'text',    'label' => 'SMTP Host',                'public' => false, 'description' => 'P. ej. email-smtp.eu-west-1.amazonaws.com, smtp.postmarkapp.com, smtp.resend.com.'],
                    'smtp_port'       => ['type' => 'text',    'label' => 'SMTP Puerto',              'default' => '587', 'public' => false],
                    'smtp_encryption' => ['type' => 'select',  'label' => 'Cifrado',                  'default' => 'tls', 'public' => false, 'options' => ['tls' => 'STARTTLS (puerto 587)', 'ssl' => 'SSL/TLS implícito (puerto 465)', 'none' => 'Sin cifrado (solo redes internas)']],
                    'smtp_auth'       => ['type' => 'boolean', 'label' => 'Requiere autenticación',   'default' => true, 'public' => false],
                    'smtp_user'       => ['type' => 'text',    'label' => 'SMTP Usuario',             'public' => false],
                    'smtp_password'   => ['type' => 'secret',  'label' => 'SMTP Contraseña / API key','public' => false, 'description' => 'Se almacena cifrada (AES-256-GCM). Déjalo en blanco al guardar para conservar el valor actual.'],
                    'smtp_from'       => ['type' => 'email',   'label' => 'Email remitente (From)',   'public' => false, 'description' => 'Usa un dominio con SPF/DKIM válidos para máxima entregabilidad.'],
                    'smtp_from_name'  => ['type' => 'text',    'label' => 'Nombre remitente',         'public' => false],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* BACKUPS AUTOMÁTICOS                                                 */
            /* ------------------------------------------------------------------ */
            'backups' => [
                'type'     => 'group',
                'label'    => 'Backups automáticos',
                'children' => [

                    'enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar backup automático',
                        'default'     => true,
                        'public'      => false,
                        'description' => 'Requiere que el contenedor `backup` esté corriendo (docker-compose.prod.yml).',
                    ],
                    'schedule' => [
                        'type'    => 'select',
                        'label'   => 'Frecuencia',
                        'default' => 'daily',
                        'public'  => false,
                        'options' => [
                            'daily'       => 'Diario',
                            'twice_daily' => 'Dos veces al día (12h de diferencia)',
                            'weekly'      => 'Semanal (todos los domingos)',
                        ],
                    ],
                    'hour_utc' => [
                        'type'        => 'select',
                        'label'       => 'Hora de inicio (UTC)',
                        'default'     => '3',
                        'public'      => false,
                        'options'     => array_combine(
                            array_map('strval', range(0, 23)),
                            array_map(static fn(int $h) => sprintf('%02d:00 UTC', $h), range(0, 23))
                        ),
                        'description' => 'Reinicia el contenedor `backup` tras cambiar la hora para que el cron se actualice.',
                    ],
                    'retain_days' => [
                        'type'        => 'number',
                        'label'       => 'Días de retención local',
                        'default'     => '14',
                        'min'         => 1,
                        'max'         => 365,
                        'public'      => false,
                        'description' => 'Tras cada backup correcto se borran los que tienen más de estos días. Si un backup falla no se borra nada. Para retención larga, sincroniza a S3/R2. No aplica si «Eliminar backup anterior» está activo.',
                    ],
                    'keep_only_latest' => [
                        'type'        => 'boolean',
                        'label'       => 'Eliminar backup anterior (conservar solo el último)',
                        'default'     => false,
                        'public'      => false,
                        'description' => 'Tras cada backup correcto se borran los anteriores y queda solo el más reciente de cada tipo. Anula los días de retención.',
                    ],
                    'include_uploads' => [
                        'type'    => 'boolean',
                        'label'   => 'Incluir archivos multimedia (wp-content/uploads)',
                        'default' => true,
                        'public'  => false,
                    ],
                    'exclude_thumbnails' => [
                        'type'        => 'boolean',
                        'label'       => 'Excluir miniaturas regenerables de multimedia',
                        'default'     => false,
                        'public'      => false,
                        'description' => 'Omite los tamaños que WordPress genera de cada imagen; los originales se guardan siempre. Tras restaurar hay que regenerarlos (wp media regenerate).',
                    ],
                    'include_config_export' => [
                        'type'        => 'boolean',
                        'label'       => 'Incluir configuración del panel (JSON)',
                        'default'     => true,
                        'public'      => false,
                        'description' => 'Guarda un export aparte de la configuración de HWE Control Center en cada backup, además de quedar ya incluida en el dump de la base de datos.',
                    ],
                    'notify_email' => [
                        'type'        => 'email',
                        'label'       => 'Email de notificación (opcional)',
                        'public'      => false,
                        'description' => 'Requiere SMTP configurado en la sección Integraciones. Recibe un resumen tras cada backup.',
                    ],

                ],
            ],

        ];
    }
}
