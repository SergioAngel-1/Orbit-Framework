<?php

namespace HWE\ControlCenter;

/**
 * Mapa de información del panel «HWE Config»: qué pestañas hay, en qué capa de la navegación
 * van y qué campos del esquema pinta cada sección.
 *
 * Es un dato declarativo del núcleo. NO toca el esquema: las rutas (`brand.name`), los `name`
 * de los inputs y `/wp-json/hwe/v1/config` son contrato y no cambian; aquí solo se decide DÓNDE
 * se pinta cada campo. `PanelLayout` aplica el mapa al esquema efectivo.
 *
 * Capas (la nav del panel las muestra como encabezados):
 *   · `business`   → Negocio: lo que el comerciante toca a diario.
 *   · `visibility` → Marca y visibilidad.
 *   · `setup`      → Puesta en marcha: lo que la agencia configura una vez.
 *
 * Una pestaña tiene:
 *   · `layer`, `label`, `icon` (dashicon), `intro` (para qué sirve, una frase);
 *   · `sections`: lista de `{title, intro, paths, class?}`. `paths` admite tres formas:
 *       - `a.b`    → ese campo (con sus variantes traducibles pegadas), o ese grupo entero
 *                    como un bloque (sedes, credenciales de una pasarela);
 *       - `a.b.*`  → los hijos directos del grupo `a.b`, cada uno por su cuenta;
 *     Una ruta que el esquema efectivo no tiene (módulo apagado) se ignora sin ruido.
 *   · `rest`: título de la sección final con lo que el mapa no nombra (campos nuevos, de módulos
 *     o de la instancia). Cadena vacía = sin título (pestañas que son solo de un módulo);
 *   · `module` (pestaña de un módulo: la nav las agrupa bajo «Módulos») y `always` (existe
 *     aunque no tenga campos propios).
 *
 * Todo campo que el mapa no nombra cae en la pestaña por defecto de su clave raíz
 * (`rootTabs()`), en la sección `rest`. Un campo con el atributo `panel` del esquema
 * (`['tab' => <clave raíz o de pestaña>, 'section' => 'Título']`) se pinta en su pestaña destino,
 * en una sección con ese título, antes de `rest`.
 */
final class PanelMap {

    /**
     * Capas de la navegación, en orden.
     *
     * @return array<string,string> clave → etiqueta
     */
    public static function layers(): array {
        return [
            'business'   => __('Negocio', 'hwe'),
            'visibility' => __('Marca y visibilidad', 'hwe'),
            'setup'      => __('Puesta en marcha', 'hwe'),
        ];
    }

    /**
     * Pestaña por defecto de cada clave raíz del esquema: destino de todo lo que el mapa no nombra.
     *
     * @return array<string,string> clave raíz → clave de pestaña
     */
    public static function rootTabs(): array {
        return [
            'brand'        => 'brand',
            'i18n'         => 'site',
            'auth'         => 'site',
            'social'       => 'brand',
            'legal'        => 'support',
            'design'       => 'brand',
            'ecommerce'    => 'store',
            'banners'      => 'banners',
            'payments'     => 'payments',
            'integrations' => 'marketing',
            'backups'      => 'backups',
            'seo'          => 'search',
            'shipping'     => 'shipping',
            'geo'          => 'search',
            'landings'     => 'landings',
            'loyalty'      => 'loyalty',
            'memberships'  => 'memberships',
            'instance'     => 'instance',
        ];
    }

    /**
     * Pestañas del panel, en el orden de la navegación.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function tabs(): array {
        $others = __('Otros ajustes', 'hwe');

        return [

            // ── Negocio ─────────────────────────────────────────────────────────

            'store' => [
                'layer'    => 'business',
                'label'    => __('Tienda', 'hwe'),
                'icon'     => 'dashicons-store',
                'intro'    => __('Cómo se muestra el catálogo y qué puede hacer el cliente al comprar.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Catálogo y checkout', 'hwe'),
                        'intro' => __('Moneda, país y las funciones que el cliente ve en la tienda.', 'hwe'),
                        'paths' => [
                            'ecommerce.currency',
                            'ecommerce.country',
                            'ecommerce.products_per_page',
                            'ecommerce.reviews_enabled',
                            'ecommerce.wishlist_enabled',
                            'ecommerce.coupons_enabled',
                            'ecommerce.search_enabled',
                            'ecommerce.checkout_login_required',
                        ],
                    ],
                ],
            ],

            'payments' => [
                'layer'    => 'business',
                'label'    => __('Pagos', 'hwe'),
                'icon'     => 'dashicons-money-alt',
                'intro'    => __('Cómo te pagan tus clientes.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Métodos de cobro', 'hwe'),
                        'intro' => __('Elige qué formas de pago ofrece el checkout.', 'hwe'),
                        'paths' => [
                            'payments.gateway_enabled',
                            'payments.provider',
                            'payments.cash_enabled',
                            'payments.whatsapp_enabled',
                        ],
                    ],
                    [
                        'title' => __('Credenciales de la pasarela', 'hwe'),
                        'intro' => __('Los datos que te da la pasarela al abrir la cuenta. Solo se muestran los de la pasarela activa y los secretos se guardan cifrados.', 'hwe'),
                        'class' => 'hwe-section--provider',
                        'paths' => [
                            'payments.avalpay',
                            'payments.wompi',
                            'payments.payu',
                            'payments.bold',
                        ],
                    ],
                ],
            ],

            'shipping' => [
                'layer'    => 'business',
                'label'    => __('Envíos', 'hwe'),
                'icon'     => 'dashicons-airplane',
                'intro'    => __('Qué cuesta el envío y cuándo es gratis.', 'hwe'),
                'rest'     => __('Tarifas y distancia', 'hwe'),
                'always'   => true,
                'sections' => [
                    [
                        'title' => __('Envío gratis', 'hwe'),
                        'intro' => __('A partir de qué compra el envío no se cobra, y a qué productos aplica.', 'hwe'),
                        'paths' => ['ecommerce.free_shipping.*'],
                    ],
                ],
            ],

            'support' => [
                'layer'    => 'business',
                'label'    => __('Atención al cliente', 'hwe'),
                'icon'     => 'dashicons-phone',
                'intro'    => __('Cómo te encuentra y te contacta un cliente.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Datos de contacto', 'hwe'),
                        'intro' => __('La empresa y su dirección legal.', 'hwe'),
                        'paths' => [
                            'legal.company',
                            'legal.nif',
                            'legal.email',
                            'legal.phone',
                            'legal.mobile',
                            'legal.address',
                            'legal.city',
                            'legal.region',
                            'legal.postal_code',
                        ],
                    ],
                    [
                        'title' => __('Sedes', 'hwe'),
                        'intro' => __('Tiendas físicas u oficinas con atención al público. Añade solo las que tengas.', 'hwe'),
                        'paths' => ['legal.locations'],
                    ],
                    [
                        'title' => __('Preguntas frecuentes', 'hwe'),
                        'intro' => __('Las respuestas que das a las dudas más comunes, en general y sobre pagos.', 'hwe'),
                        'paths' => ['geo.faq', 'geo.faq_payments'],
                    ],
                ],
            ],

            'loyalty' => [
                'layer'    => 'business',
                'module'   => true,
                'label'    => __('Fidelización', 'hwe'),
                'icon'     => 'dashicons-awards',
                'intro'    => __('Ajustes del módulo Fidelización.', 'hwe'),
                'rest'     => '',
                'sections' => [],
            ],

            'memberships' => [
                'layer'    => 'business',
                'module'   => true,
                'label'    => __('Membresías', 'hwe'),
                'icon'     => 'dashicons-id-alt',
                'intro'    => __('Ajustes del módulo Membresías.', 'hwe'),
                'rest'     => '',
                'sections' => [],
            ],

            'landings' => [
                'layer'    => 'business',
                'module'   => true,
                'label'    => __('Landings', 'hwe'),
                'icon'     => 'dashicons-layout',
                'intro'    => __('Ajustes del módulo Landings.', 'hwe'),
                'rest'     => '',
                'sections' => [],
            ],

            'banners' => [
                'layer'    => 'business',
                'module'   => true,
                'label'    => __('Banners', 'hwe'),
                'icon'     => 'dashicons-images-alt2',
                'intro'    => __('Activa los banners de la tienda y gestiona su contenido.', 'hwe'),
                'rest'     => '',
                'sections' => [],
            ],

            // ── Marca y visibilidad ─────────────────────────────────────────────

            'brand' => [
                'layer'    => 'visibility',
                'label'    => __('Marca', 'hwe'),
                'icon'     => 'dashicons-art',
                'intro'    => __('Cómo se ve y cómo se llama tu tienda.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Identidad', 'hwe'),
                        'intro' => __('El nombre, el texto de presentación y el logo de la tienda.', 'hwe'),
                        'paths' => [
                            'brand.name',
                            'brand.tagline',
                            'brand.description',
                            'brand.logo',
                            'brand.email_logo',
                        ],
                    ],
                    [
                        'title' => __('Colores', 'hwe'),
                        'intro' => __('La paleta de la tienda. El color principal también es el de este panel.', 'hwe'),
                        'paths' => ['design.color_scheme', 'design.colors.*'],
                    ],
                    [
                        'title' => __('Tipografía', 'hwe'),
                        'intro' => __('Las fuentes de los textos y de los titulares.', 'hwe'),
                        'paths' => ['design.typography.*'],
                    ],
                    [
                        'title' => __('Redes sociales', 'hwe'),
                        'intro' => __('Los perfiles de la marca que se enlazan desde la tienda.', 'hwe'),
                        'paths' => ['social.*'],
                    ],
                ],
            ],

            'search' => [
                'layer'    => 'visibility',
                'label'    => __('Buscadores e IA', 'hwe'),
                'icon'     => 'dashicons-search',
                'intro'    => __('Cómo te encuentran Google, las redes y los asistentes de IA.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Resultados de búsqueda', 'hwe'),
                        'intro' => __('El título que se muestra, si se indexa la tienda y la verificación en los buscadores.', 'hwe'),
                        'paths' => [
                            'seo.title_template',
                            'seo.robots',
                            'seo.google_site_verification',
                            'seo.bing_site_verification',
                        ],
                    ],
                    [
                        'title' => __('Al compartir', 'hwe'),
                        'intro' => __('La imagen que acompaña un enlace de la tienda cuando se comparte.', 'hwe'),
                        'paths' => ['seo.default_og', 'seo.og_image'],
                    ],
                    [
                        'title' => __('Datos estructurados del producto', 'hwe'),
                        'intro' => __('Lo que los buscadores leen de cada ficha: marca, tipo de negocio, envío y devoluciones.', 'hwe'),
                        'paths' => [
                            'seo.product_brand',
                            'seo.business_type',
                            'seo.shipping_amount',
                            'seo.return_days',
                            'seo.return_category',
                            'seo.return_method',
                            'seo.return_fees',
                            'seo.handling_days',
                            'seo.transit_days',
                        ],
                    ],
                    [
                        'title' => __('Entidad de marca', 'hwe'),
                        'intro' => __('Quién es la marca para buscadores y asistentes: logo, fundación, responsable y temas de los que sabe.', 'hwe'),
                        'paths' => [
                            'seo.organization_logo',
                            'seo.founding_date',
                            'seo.knows_about',
                            'seo.alternate_names',
                            'seo.founder_name',
                            'seo.founder_role',
                            'seo.founder_url',
                        ],
                    ],
                    [
                        'title' => __('Asistentes de IA', 'hwe'),
                        'intro' => __('Qué rastreadores de IA pueden leer la tienda y si se publica /llms.txt.', 'hwe'),
                        'paths' => ['geo.ai_crawlers', 'geo.llms_txt_enabled', 'geo.content_signal'],
                    ],
                ],
            ],

            // ── Puesta en marcha ────────────────────────────────────────────────

            'site' => [
                'layer'    => 'setup',
                'label'    => __('Sitio e idiomas', 'hwe'),
                'icon'     => 'dashicons-admin-site-alt3',
                'intro'    => __('Dónde vive la web pública y en qué idiomas habla.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Dirección e idiomas', 'hwe'),
                        'intro' => __('La URL pública de la tienda, su idioma por defecto y los secundarios.', 'hwe'),
                        'paths' => ['brand.url', 'brand.locale', 'i18n.locales'],
                    ],
                    [
                        'title' => __('Acceso con teléfono', 'hwe'),
                        'intro' => __('Si el cliente puede iniciar sesión y registrarse con su número.', 'hwe'),
                        'paths' => ['auth.*'],
                    ],
                ],
            ],

            'email' => [
                'layer'    => 'setup',
                'label'    => __('Correo', 'hwe'),
                'icon'     => 'dashicons-email-alt',
                'intro'    => __('Por dónde salen los correos de la tienda.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Servidor de correo (SMTP)', 'hwe'),
                        'intro' => __('Los datos del servidor SMTP. Sin él, los correos salen por el envío PHP del servidor.', 'hwe'),
                        'paths' => [
                            'integrations.smtp_enabled',
                            'integrations.smtp_host',
                            'integrations.smtp_port',
                            'integrations.smtp_encryption',
                            'integrations.smtp_auth',
                            'integrations.smtp_user',
                            'integrations.smtp_password',
                            'integrations.smtp_from',
                            'integrations.smtp_from_name',
                        ],
                    ],
                ],
            ],

            'marketing' => [
                'layer'    => 'setup',
                'label'    => __('Analítica y marketing', 'hwe'),
                'icon'     => 'dashicons-chart-line',
                'intro'    => __('Medición y píxeles de publicidad.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Analítica', 'hwe'),
                        'intro' => __('El proveedor de analítica y el gestor de etiquetas.', 'hwe'),
                        'paths' => [
                            'integrations.analytics_provider',
                            'integrations.analytics_id',
                            'integrations.gtm_id',
                        ],
                    ],
                    [
                        'title' => __('Píxeles de publicidad', 'hwe'),
                        'intro' => __('Meta (píxel y API de conversiones) y TikTok.', 'hwe'),
                        'paths' => [
                            'integrations.meta_pixel_id',
                            'integrations.meta_capi_token',
                            'integrations.meta_capi_test_event_code',
                            'integrations.tiktok_pixel_id',
                        ],
                    ],
                    [
                        'title' => __('CRM', 'hwe'),
                        'intro' => __('La cuenta de HubSpot a la que se envían los contactos.', 'hwe'),
                        'paths' => ['integrations.hubspot_portal_id'],
                    ],
                ],
            ],

            'services' => [
                'layer'    => 'setup',
                'label'    => __('Servicios y privacidad', 'hwe'),
                'icon'     => 'dashicons-location-alt',
                'intro'    => __('Servicios externos que usa la tienda y qué se conserva de los visitantes.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Mapas', 'hwe'),
                        'intro' => __('El proveedor y la clave para mapas y cálculo de distancias.', 'hwe'),
                        'paths' => ['integrations.maps_provider', 'integrations.maps_api_key'],
                    ],
                    [
                        'title' => __('Registro de consentimientos', 'hwe'),
                        'intro' => __('Cuánto tiempo se guarda la prueba de lo que aceptó cada visitante.', 'hwe'),
                        'paths' => ['integrations.consent_retention_years'],
                    ],
                ],
            ],

            'backups' => [
                'layer'    => 'setup',
                'label'    => __('Copias de seguridad', 'hwe'),
                'icon'     => 'dashicons-backup',
                'intro'    => __('Copias automáticas, copia manual y exportación de la configuración.', 'hwe'),
                'rest'     => $others,
                'sections' => [
                    [
                        'title' => __('Copia automática', 'hwe'),
                        'intro' => __('Cuándo se hace, qué incluye y cuánto tiempo se conserva.', 'hwe'),
                        'paths' => ['backups.*'],
                    ],
                ],
            ],

            'instance' => [
                'layer'    => 'setup',
                'label'    => __('Instancia', 'hwe'),
                'icon'     => 'dashicons-admin-tools',
                'intro'    => __('Ajustes propios de esta tienda.', 'hwe'),
                'rest'     => '',
                'sections' => [],
            ],
        ];
    }
}
