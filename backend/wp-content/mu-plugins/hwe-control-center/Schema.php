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
                    'logo'        => [
                        'type'        => 'url',
                        'label'       => 'Logo (URL)',
                        'public'      => true,
                        'description' => 'URL del logo de cabecera (súbelo a la medioteca y pega aquí su URL). Vacío = se muestra el nombre del sitio como texto.',
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
                    'youtube'   => ['type' => 'text', 'label' => 'YouTube (@handle o URL)',  'public' => true],
                    'wikipedia' => ['type' => 'url',  'label' => 'Wikipedia (URL del artículo)', 'public' => true, 'description' => 'Refuerza el reconocimiento de entidad (sameAs) por buscadores e IA.'],
                    'wikidata'  => ['type' => 'url',  'label' => 'Wikidata (URL del ítem Q…)',   'public' => true, 'description' => 'Identificador de entidad legible por máquinas (sameAs).'],
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
                            'secondary'      => [
                                'type'    => 'color',
                                'label'   => 'Color secundario',
                                'default' => '#16a34a',
                                'token'   => '--color-secondary',
                                'public'  => true,
                            ],
                            'secondary_dark' => [
                                'type'    => 'color',
                                'label'   => 'Color secundario (hover/activo)',
                                'default' => '#15803d',
                                'token'   => '--color-secondary-dark',
                                'public'  => true,
                            ],
                            'accent'         => [
                                'type'    => 'color',
                                'label'   => 'Color de énfasis',
                                'default' => '#f59e0b',
                                'token'   => '--color-accent',
                                'public'  => true,
                            ],
                            'surface'        => [
                                'type'    => 'color',
                                'label'   => 'Superficie (tarjetas/paneles)',
                                'default' => '#f8fafc',
                                'token'   => '--color-surface',
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
                            'font_heading' => [
                                'type'        => 'text',
                                'label'       => 'Fuente de titulares (opcional)',
                                'token'       => '--font-heading',
                                'public'      => true,
                                'description' => 'Si se deja vacía, los titulares usan la fuente principal.',
                            ],
                            'font_heading_url' => [
                                'type'        => 'url',
                                'label'       => 'URL Google Fonts para titulares (opcional)',
                                'public'      => true,
                                'description' => 'Pega la URL de importación de Google Fonts para cargar la fuente de titulares.',
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
            /* SEO & GEO                                                           */
            /* ------------------------------------------------------------------ */
            'seo' => [
                'type'     => 'group',
                'label'    => 'SEO & GEO',
                'children' => [
                    'title_template'            => [
                        'type'        => 'text',
                        'label'       => 'Plantilla de título',
                        'default'     => '%s · %site%',
                        'public'      => true,
                        'description' => 'Marcadores: %s = título de la página, %site% = nombre del sitio. Ej.: "%s · %site%".',
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
                    'default_og'                => [
                        'type'        => 'select',
                        'label'       => 'Imagen Open Graph por defecto',
                        'default'     => 'auto',
                        'public'      => true,
                        'options'     => [
                            'auto'   => 'Generar dinámicamente desde la marca (recomendado)',
                            'custom' => 'Usar la URL de "OG Image por defecto" (Identidad de marca)',
                        ],
                        'description' => 'En modo automático la plantilla genera la tarjeta social y los iconos a partir del nombre y los colores de marca (sin archivos estáticos). En modo personalizado usa la URL definida en Identidad de marca → OG Image.',
                    ],

                    /* Datos estructurados de producto (Merchant Listing / Rich Results).
                       Todos opcionales: si se dejan vacíos, no se emite ese bloque. */
                    'product_brand'             => [
                        'type'        => 'text',
                        'label'       => 'Marca por defecto de los productos',
                        'public'      => true,
                        'description' => 'Se usa en el JSON-LD Product (campo brand). Vacío = nombre del sitio.',
                    ],
                    'shipping_amount'           => [
                        'type'        => 'text',
                        'label'       => 'Coste de envío para datos estructurados',
                        'public'      => true,
                        'description' => 'Importe en la moneda de la tienda (0 = envío gratis). Vacío = no declarar shippingDetails en el schema.',
                    ],
                    'return_days'               => [
                        'type'        => 'text',
                        'label'       => 'Días de devolución (schema)',
                        'public'      => true,
                        'description' => 'Nº de días de la política de devolución (MerchantReturnPolicy). Vacío = no declarar devoluciones en el schema.',
                    ],
                    'return_category'           => [
                        'type'        => 'select',
                        'label'       => 'Tipo de política de devolución',
                        'default'     => 'finite',
                        'public'      => true,
                        'options'     => [
                            'finite'    => 'Ventana finita (devolución dentro de X días)',
                            'unlimited' => 'Ilimitada',
                            'none'      => 'No se permiten devoluciones',
                        ],
                        'description' => 'Solo se aplica si se ha indicado un nº de días de devolución.',
                    ],
                    'organization_logo'         => [
                        'type'        => 'url',
                        'label'       => 'Logo de la organización (JSON-LD)',
                        'public'      => true,
                        'description' => 'URL del logo usado en el schema Organization (reconocimiento de entidad por buscadores/IA). Vacío = se usa el icono generado dinámicamente desde la marca.',
                    ],
                    'founding_date'             => [
                        'type'        => 'text',
                        'label'       => 'Fecha de fundación (ISO 8601)',
                        'public'      => true,
                        'description' => 'Ej.: 2020-01-15. Se añade al schema Organization (foundingDate).',
                    ],
                    'knows_about'               => [
                        'type'        => 'textarea',
                        'label'       => 'Áreas de conocimiento (knowsAbout)',
                        'public'      => true,
                        'description' => 'Temas en los que la marca es experta, uno por línea o separados por comas. Señal fuerte de entidad para la IA.',
                    ],
                    'founder_name'              => [
                        'type'        => 'text',
                        'label'       => 'Responsable / fundador (nombre)',
                        'public'      => true,
                        'description' => 'Se muestra en la página "Sobre nosotros" y genera un schema Person (E-E-A-T). Vacío = no se publica.',
                    ],
                    'founder_role'              => [
                        'type'        => 'text',
                        'label'       => 'Responsable / fundador (cargo)',
                        'public'      => true,
                        'description' => 'Ej.: Fundador y CEO. Se usa como jobTitle del schema Person.',
                    ],
                    'founder_url'               => [
                        'type'        => 'url',
                        'label'       => 'Responsable / fundador (perfil)',
                        'public'      => true,
                        'description' => 'URL de perfil (LinkedIn, web personal…). Se usa como url/sameAs del schema Person.',
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* ENVÍO                                                               */
            /* ------------------------------------------------------------------ */
            'shipping' => [
                'type'     => 'group',
                'label'    => 'Envío',
                'children' => [
                    'zone_name' => [
                        'type'        => 'text',
                        'label'       => 'Nombre de la zona de envío principal',
                        'default'     => 'España peninsular',
                        'public'      => false,
                        'description' => 'Nombre de la zona principal en WooCommerce. Cámbialo si tu mercado principal no es España.',
                    ],
                    'flat_rate_cost' => [
                        'type'        => 'text',
                        'label'       => 'Coste de envío (tarifa plana)',
                        'default'     => '4.99',
                        'public'      => true,
                        'description' => 'Importe en la moneda de la tienda. Ej.: 4.99. Aplicado a todos los pedidos de la zona principal salvo que superen el umbral de envío gratis.',
                    ],
                    'free_above' => [
                        'type'        => 'text',
                        'label'       => 'Envío gratis a partir de (0 = desactivado)',
                        'default'     => '0',
                        'public'      => true,
                        'description' => 'Si el subtotal del carrito supera este importe, el envío es gratuito. 0 = tarifa plana siempre.',
                    ],
                    'free_label' => [
                        'type'        => 'text',
                        'label'       => 'Etiqueta de envío gratis',
                        'default'     => 'Envío gratuito',
                        'public'      => true,
                        'description' => 'Texto que se muestra al cliente cuando el envío es gratis.',
                    ],
                    'flat_label' => [
                        'type'        => 'text',
                        'label'       => 'Etiqueta de tarifa plana',
                        'default'     => 'Envío estándar',
                        'public'      => true,
                        'description' => 'Texto que se muestra al cliente para la tarifa plana.',
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* GEO (optimización para agentes de IA)                              */
            /* ------------------------------------------------------------------ */
            'geo' => [
                'type'     => 'group',
                'label'    => 'GEO (IA)',
                'children' => [
                    'ai_crawlers'      => [
                        'type'        => 'select',
                        'label'       => 'Crawlers de IA',
                        'default'     => 'allow',
                        'public'      => true,
                        'options'     => [
                            'allow'       => 'Permitir todos (máxima visibilidad en respuestas de IA)',
                            'search_only' => 'Solo búsqueda/citación; bloquear bots de entrenamiento',
                            'block'       => 'Bloquear todos los crawlers de IA',
                        ],
                        'description' => 'Controla las reglas de robots.txt para bots como GPTBot, ClaudeBot, PerplexityBot, Google-Extended, etc. Las rutas privadas se bloquean siempre.',
                    ],
                    'llms_txt_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Generar /llms.txt',
                        'default'     => true,
                        'public'      => true,
                        'description' => 'Publica un fichero /llms.txt (estándar emergente) que describe la estructura del sitio para agentes de IA.',
                    ],
                    'content_signal'   => [
                        'type'        => 'boolean',
                        'label'       => 'Declarar Content-Signal en robots.txt',
                        'default'     => true,
                        'public'      => true,
                        'description' => 'Añade la directiva Content-Signal (borrador IETF) coherente con la política de crawlers de IA: declara explícitamente ai-train / search / ai-retrieval.',
                    ],
                    'faq'              => [
                        'type'        => 'textarea',
                        'label'       => 'Preguntas frecuentes (FAQ)',
                        'public'      => true,
                        'description' => 'Una pregunta y respuesta por línea, separadas por "|". Se muestran en la home y se publican como JSON-LD FAQPage (muy citado por la IA). Ej.: ¿Hacéis envíos internacionales? | Sí, enviamos a toda Europa.',
                    ],
                ],
            ],

        ];
    }
}
