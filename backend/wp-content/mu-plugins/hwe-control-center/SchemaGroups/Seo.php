<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: SEO (título, indexación, Open Graph y datos estructurados).
 *
 * Las señales de entidad (Organization / Person) viven en `seo.*` pero se editan en la
 * pestaña GEO (atributo `panel`): son las que usan buscadores e IA para reconocer la marca.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Seo {

    /** Dónde se editan las señales de entidad en el panel (ver `panel` en Schema). */
    private const ENTITY_PANEL = ['tab' => 'geo', 'section' => 'Entidad de marca (Organization / Person)'];

    /**
     * @return array<string,array>
     */
    private static function seoGroups(): array {
        return [

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
                    'bing_site_verification'    => [
                        'type'        => 'text',
                        'label'       => 'Código de verificación Bing Webmaster Tools',
                        'public'      => true,
                        'description' => 'El valor de la meta msvalidate.01 (solo el código). Bing alimenta también a DuckDuckGo, Yahoo y ChatGPT Search.',
                    ],
                    'default_og'                => [
                        'type'        => 'select',
                        'label'       => 'Imagen Open Graph por defecto',
                        'default'     => 'auto',
                        'public'      => true,
                        'options'     => [
                            'auto'   => 'Generar dinámicamente desde la marca (recomendado)',
                            'custom' => 'Usar la imagen «OG Image» (campo siguiente)',
                        ],
                        'description' => 'En modo automático la plantilla genera la tarjeta social y los iconos a partir del nombre y los colores de marca (sin archivos estáticos). En modo personalizado usa la imagen del campo siguiente.',
                    ],
                    'og_image'                  => [
                        'type'        => 'image',
                        'label'       => 'OG Image',
                        'public'      => true,
                        'description' => 'Imagen usada en Open Graph / Twitter cuando el modo anterior es «personalizado». Selecciónala desde la medioteca.',
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
                        'description' => 'Se emite en el JSON-LD Product. «Ventana finita» requiere indicar los días arriba; «ilimitada» y «no se permiten» no.',
                    ],
                    'return_method'             => [
                        'type'        => 'select',
                        'label'       => 'Cómo se devuelve un producto (schema)',
                        'default'     => '',
                        'public'      => true,
                        'options'     => [
                            ''               => '— No declarar —',
                            'mail'           => 'Por mensajería',
                            'in_store'       => 'En tienda física',
                            'mail_and_store' => 'Por mensajería o en tienda física',
                        ],
                        'description' => 'MerchantReturnPolicy.returnMethod. Debe coincidir con los términos de venta publicados.',
                    ],
                    'return_fees'               => [
                        'type'        => 'select',
                        'label'       => 'Quién paga la devolución (schema)',
                        'default'     => '',
                        'public'      => true,
                        'options'     => [
                            ''              => '— No declarar —',
                            'free'          => 'Gratis para el cliente',
                            'customer_pays' => 'El cliente paga el envío de la devolución',
                        ],
                        'description' => 'MerchantReturnPolicy.returnFees. Vacío = no se declara.',
                    ],
                    'handling_days'             => [
                        'type'        => 'text',
                        'label'       => 'Días de preparación del pedido (schema)',
                        'public'      => true,
                        'description' => 'Días hábiles desde el pago hasta que el pedido sale, como «mín-máx» (p. ej. «0-1») o un solo número. Vacío = no se declara.',
                    ],
                    'transit_days'              => [
                        'type'        => 'text',
                        'label'       => 'Días de tránsito del envío (schema)',
                        'public'      => true,
                        'description' => 'Días hábiles que tarda la transportadora, como «mín-máx» (p. ej. «1-5»). Vacío = no se declara.',
                    ],
                    'organization_logo'         => [
                        'type'        => 'image',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Logo de la organización (JSON-LD)',
                        'public'      => true,
                        'description' => 'Logo usado en el schema Organization (reconocimiento de entidad por buscadores/IA). Selecciónalo desde la medioteca. Vacío = se usa el icono generado dinámicamente desde la marca.',
                    ],
                    'founding_date'             => [
                        'type'        => 'text',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Fecha de fundación (ISO 8601)',
                        'public'      => true,
                        'description' => 'Ej.: 2020-01-15. Se añade al schema Organization (foundingDate).',
                    ],
                    'knows_about'               => [
                        'type'        => 'textarea',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Áreas de conocimiento (knowsAbout)',
                        'public'      => true,
                        'description' => 'Temas en los que la marca es experta, uno por línea o separados por comas. Señal fuerte de entidad para la IA.',
                    ],
                    'alternate_names'           => [
                        'type'        => 'textarea',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Otros nombres de la marca',
                        'public'      => true,
                        'description' => 'Nombres anteriores o alternativos por los que se conoce a la marca, uno por línea o separados por comas. Se declaran en el schema Organization (alternateName).',
                    ],
                    'founder_name'              => [
                        'type'        => 'text',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Responsable / fundador (nombre)',
                        'public'      => true,
                        'description' => 'Se muestra en la página "Sobre nosotros" y genera un schema Person (E-E-A-T). Vacío = no se publica.',
                    ],
                    'founder_role'              => [
                        'type'        => 'text',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Responsable / fundador (cargo)',
                        'public'      => true,
                        'description' => 'Ej.: Fundador y CEO. Se usa como jobTitle del schema Person.',
                    ],
                    'founder_url'               => [
                        'type'        => 'url',
                        'panel'       => self::ENTITY_PANEL,
                        'label'       => 'Responsable / fundador (perfil)',
                        'public'      => true,
                        'description' => 'URL de perfil (LinkedIn, web personal…). Se usa como url/sameAs del schema Person.',
                    ],
                    'business_type'             => [
                        'type'        => 'select',
                        'label'       => 'Tipo de negocio (schema.org)',
                        'default'     => 'LocalBusiness',
                        'public'      => true,
                        'options'     => [
                            'LocalBusiness'              => 'Negocio local (genérico)',
                            'Store'                      => 'Tienda',
                            'ClothingStore'              => 'Tienda de ropa',
                            'ShoeStore'                  => 'Zapatería',
                            'JewelryStore'               => 'Joyería',
                            'BookStore'                  => 'Librería',
                            'ElectronicsStore'           => 'Tienda de electrónica',
                            'FurnitureStore'             => 'Tienda de muebles',
                            'HardwareStore'              => 'Ferretería',
                            'PetStore'                   => 'Tienda de animales',
                            'SportingGoodsStore'         => 'Tienda de deportes',
                            'GroceryStore'               => 'Tienda de alimentación',
                            'Florist'                    => 'Floristería',
                            'Pharmacy'                   => 'Farmacia',
                            'HealthAndBeautyBusiness'    => 'Salud y belleza',
                            'BeautySalon'                => 'Salón de belleza',
                            'Restaurant'                 => 'Restaurante',
                            'CafeOrCoffeeShop'           => 'Cafetería',
                            'Bakery'                     => 'Panadería',
                            'ProfessionalService'        => 'Servicios profesionales',
                            'HomeAndConstructionBusiness' => 'Hogar y construcción',
                        ],
                        'description' => 'Tipo schema.org con el que se publica cada sede de Legal → Sedes (LocalBusiness). Elige el más específico que describa tu negocio.',
                    ],
                ],
            ],

        ];
    }
}
