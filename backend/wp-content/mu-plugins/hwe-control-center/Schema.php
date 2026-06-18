<?php

namespace HWE\ControlCenter;

/**
 * Árbol de configuración de la plantilla — fuente única de verdad.
 *
 * Tipos de nodo:
 *   'group'    → contenedor con 'children' (recursivo).
 *   'text'     → input de texto de una línea.
 *   'textarea' → área de texto multilínea.
 *   'color'    → selector de color (#rrggbb).
 *   'url'      → input de URL con validación.
 *   'email'    → input de email con validación.
 *   'select'   → desplegable; requiere 'options' => ['value' => 'Etiqueta'].
 *   'boolean'  → checkbox (true/false).
 *   'secret'   → cifrado en BD; nunca aparece en la API pública.
 *
 * Atributos de campo:
 *   'label'       → etiqueta legible para humanos en la UI de admin.
 *   'default'     → valor por defecto cuando no hay nada almacenado.
 *   'public'      → bool: si true, el campo se incluye en la API REST pública.
 *   'token'       → nombre de la propiedad CSS (p. ej. '--color-brand').
 *   'options'     → ['value' => 'Etiqueta'] para el tipo 'select'.
 *   'description' → texto de ayuda mostrado en la UI de admin.
 */
class Schema {

    /** @return array<string,array> */
    public static function get(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* MARCA / IDENTIDAD                                                   */
            /* ------------------------------------------------------------------ */
            'brand' => [
                'type'     => 'group',
                'label'    => 'Identidad de marca',
                'children' => [
                    'name'        => [
                        'type'    => 'text',
                        'label'   => 'Nombre del sitio',
                        'default' => 'HeadlessWP',
                        'public'  => true,
                    ],
                    'tagline'     => [
                        'type'    => 'text',
                        'label'   => 'Tagline',
                        'default' => 'Headless WooCommerce Template',
                        'public'  => true,
                    ],
                    'description' => [
                        'type'    => 'textarea',
                        'label'   => 'Descripción breve',
                        'default' => 'Tienda headless construida con Next.js y WooCommerce.',
                        'public'  => true,
                    ],
                    'url'         => [
                        'type'   => 'url',
                        'label'  => 'URL pública del frontend',
                        'public' => true,
                    ],
                    'locale'      => [
                        'type'    => 'select',
                        'label'   => 'Idioma por defecto',
                        'default' => 'es',
                        'public'  => true,
                        'options' => ['es' => 'Español', 'en' => 'English'],
                    ],
                    'og_image'    => [
                        'type'        => 'url',
                        'label'       => 'OG Image por defecto',
                        'public'      => true,
                        'description' => 'URL de la imagen usada en Open Graph / Twitter cuando no hay imagen específica.',
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* REDES SOCIALES                                                       */
            /* ------------------------------------------------------------------ */
            'social' => [
                'type'     => 'group',
                'label'    => 'Redes sociales',
                'children' => [
                    'twitter'   => ['type' => 'text', 'label' => 'Twitter / X (@handle)',  'public' => true],
                    'instagram' => ['type' => 'text', 'label' => 'Instagram (@handle)',     'public' => true],
                    'facebook'  => ['type' => 'text', 'label' => 'Facebook (slug o URL)',   'public' => true],
                    'linkedin'  => ['type' => 'url',  'label' => 'LinkedIn (URL completa)', 'public' => true],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* LEGAL                                                                */
            /* ------------------------------------------------------------------ */
            'legal' => [
                'type'     => 'group',
                'label'    => 'Legal',
                'children' => [
                    'company' => ['type' => 'text',  'label' => 'Razón social',       'default' => 'Headless Web Ecosystem Inc.', 'public' => true],
                    'nif'     => ['type' => 'text',  'label' => 'NIF / RUT / NIT',    'public' => true],
                    'email'   => ['type' => 'email', 'label' => 'Email de contacto',  'default' => 'hello@headlesswp.com',       'public' => true],
                    'address' => ['type' => 'text',  'label' => 'Dirección legal',    'public' => true],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* DISEÑO (design tokens)                                              */
            /* ------------------------------------------------------------------ */
            'design' => [
                'type'     => 'group',
                'label'    => 'Diseño',
                'children' => [

                    'colors' => [
                        'type'     => 'group',
                        'label'    => 'Colores',
                        'children' => [
                            'brand'       => [
                                'type'    => 'color',
                                'label'   => 'Color principal',
                                'default' => '#2563eb',
                                'token'   => '--color-brand',
                                'public'  => true,
                            ],
                            'brand_dark'  => [
                                'type'    => 'color',
                                'label'   => 'Color oscuro (hover/activo)',
                                'default' => '#1e40af',
                                'token'   => '--color-brand-dark',
                                'public'  => true,
                            ],
                            'brand_light' => [
                                'type'    => 'color',
                                'label'   => 'Color claro (énfasis suave)',
                                'default' => '#3b82f6',
                                'token'   => '--color-brand-light',
                                'public'  => true,
                            ],
                            'background'  => [
                                'type'    => 'color',
                                'label'   => 'Fondo (modo claro)',
                                'default' => '#ffffff',
                                'token'   => '--background',
                                'public'  => true,
                            ],
                            'foreground'  => [
                                'type'    => 'color',
                                'label'   => 'Texto (modo claro)',
                                'default' => '#0a0a0a',
                                'token'   => '--foreground',
                                'public'  => true,
                            ],
                        ],
                    ],

                    'typography' => [
                        'type'     => 'group',
                        'label'    => 'Tipografía',
                        'children' => [
                            'font_sans' => [
                                'type'    => 'text',
                                'label'   => 'Fuente principal',
                                'default' => 'Inter',
                                'token'   => '--font-sans',
                                'public'  => true,
                            ],
                            'font_url'  => [
                                'type'        => 'url',
                                'label'       => 'URL Google Fonts (opcional)',
                                'public'      => true,
                                'description' => 'Pega la URL de importación de Google Fonts para cargar la fuente.',
                            ],
                        ],
                    ],

                ],
            ],

            /* ------------------------------------------------------------------ */
            /* TIENDA / E-COMMERCE                                                 */
            /* ------------------------------------------------------------------ */
            'ecommerce' => [
                'type'     => 'group',
                'label'    => 'Tienda',
                'children' => [
                    'currency'          => ['type' => 'text',    'label' => 'Moneda (ISO-4217)',     'default' => 'EUR',  'public' => true],
                    'country'           => ['type' => 'text',    'label' => 'País por defecto',      'default' => 'ES',   'public' => true],
                    'products_per_page' => ['type' => 'text',    'label' => 'Productos por página',  'default' => '12',   'public' => true],
                    'reviews_enabled'   => ['type' => 'boolean', 'label' => 'Habilitar reseñas',    'default' => false,  'public' => true],
                    'wishlist_enabled'  => ['type' => 'boolean', 'label' => 'Habilitar wishlist',   'default' => false,  'public' => true],
                    'coupons_enabled'   => ['type' => 'boolean', 'label' => 'Habilitar cupones',    'default' => false,  'public' => true],
                    'search_enabled'    => ['type' => 'boolean', 'label' => 'Habilitar búsqueda',   'default' => true,   'public' => true],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* PASARELAS DE PAGO (secretos cifrados)                               */
            /* ------------------------------------------------------------------ */
            'payments' => [
                'type'     => 'group',
                'label'    => 'Pasarelas de pago',
                'children' => [

                    'provider' => [
                        'type'        => 'select',
                        'label'       => 'Pasarela activa',
                        'default'     => 'noop',
                        'public'      => false,
                        'options'     => [
                            'noop'  => 'Sandbox (noop) — sin cobro real',
                            'wompi' => 'Wompi (Colombia)',
                            'payu'  => 'PayU (LATAM)',
                            'bold'  => 'Bold (Colombia)',
                        ],
                        'description' => 'El valor se aplica como variable de entorno PAYMENT_PROVIDER en el frontend.',
                    ],

                    'wompi' => [
                        'type'     => 'group',
                        'label'    => 'Wompi',
                        'children' => [
                            'public_key'     => ['type' => 'text',    'label' => 'Clave pública',      'public' => true],
                            'secret_key'     => ['type' => 'secret',  'label' => 'Clave privada',      'public' => false],
                            'webhook_secret' => ['type' => 'secret',  'label' => 'Secreto de webhook', 'public' => false],
                            'sandbox'        => ['type' => 'boolean', 'label' => 'Modo sandbox',       'default' => true, 'public' => false],
                        ],
                    ],

                    'payu' => [
                        'type'     => 'group',
                        'label'    => 'PayU',
                        'children' => [
                            'merchant_id'    => ['type' => 'text',   'label' => 'Merchant ID',         'public' => false],
                            'account_id'     => ['type' => 'text',   'label' => 'Account ID',          'public' => false],
                            'api_login'      => ['type' => 'text',   'label' => 'API Login',           'public' => false],
                            'api_key'        => ['type' => 'secret', 'label' => 'API Key',             'public' => false],
                            'webhook_secret' => ['type' => 'secret', 'label' => 'Secreto de webhook',  'public' => false],
                            'sandbox'        => ['type' => 'boolean','label' => 'Modo sandbox',        'default' => true, 'public' => false],
                        ],
                    ],

                    'bold' => [
                        'type'     => 'group',
                        'label'    => 'Bold',
                        'children' => [
                            'api_key'       => ['type' => 'secret', 'label' => 'API Key',              'public' => false],
                            'integrity_key' => ['type' => 'secret', 'label' => 'Clave de integridad',  'public' => false],
                            'sandbox'       => ['type' => 'boolean','label' => 'Modo sandbox',         'default' => true, 'public' => false],
                        ],
                    ],

                ],
            ],

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
                    'smtp_host'     => ['type' => 'text',   'label' => 'SMTP Host',        'public' => false],
                    'smtp_port'     => ['type' => 'text',   'label' => 'SMTP Puerto',      'default' => '587', 'public' => false],
                    'smtp_user'     => ['type' => 'text',   'label' => 'SMTP Usuario',     'public' => false],
                    'smtp_password' => ['type' => 'secret', 'label' => 'SMTP Contraseña',  'public' => false],
                    'smtp_from'     => ['type' => 'email',  'label' => 'Email remitente',  'public' => false],
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
                        'type'        => 'text',
                        'label'       => 'Días de retención local',
                        'default'     => '14',
                        'public'      => false,
                        'description' => 'Backups más antiguos se eliminan automáticamente. Para retención larga, sincroniza a S3/R2.',
                    ],
                    'include_uploads' => [
                        'type'    => 'boolean',
                        'label'   => 'Incluir archivos multimedia (wp-content/uploads)',
                        'default' => true,
                        'public'  => false,
                    ],
                    'notify_email' => [
                        'type'        => 'email',
                        'label'       => 'Email de notificación (opcional)',
                        'public'      => false,
                        'description' => 'Requiere SMTP configurado en la sección Integraciones. Recibe un resumen tras cada backup.',
                    ],

                ],
            ],

            /* ------------------------------------------------------------------ */
            /* SEO                                                                 */
            /* ------------------------------------------------------------------ */
            'seo' => [
                'type'     => 'group',
                'label'    => 'SEO',
                'children' => [
                    'title_template'            => [
                        'type'        => 'text',
                        'label'       => 'Plantilla de título',
                        'default'     => '%s',
                        'public'      => true,
                        'description' => 'Usa %s como marcador del título de página.',
                    ],
                    'robots'                    => [
                        'type'    => 'select',
                        'label'   => 'Indexación por defecto',
                        'default' => 'index,follow',
                        'public'  => true,
                        'options' => [
                            'index,follow'     => 'Indexar todo (recomendado para producción)',
                            'noindex,nofollow' => 'No indexar (desarrollo / staging)',
                        ],
                    ],
                    'google_site_verification'  => [
                        'type'   => 'text',
                        'label'  => 'Código de verificación Google Search Console',
                        'public' => true,
                    ],
                ],
            ],

        ];
    }
}
