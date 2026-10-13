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
 *   'translatable' → bool (text, textarea, lines, richtext): el esquema efectivo añade
 *                    un hermano `<clave>_<locale>` por cada idioma secundario de
 *                    `i18n.locales` (vacío = se usa el texto del idioma por defecto).
 *                    El frontend lo resuelve con `pickLocalized(entity, campo, locale)`.
 *
 * CONTRATO CON LA INSTANCIA
 * -------------------------
 * `get()` devuelve el esquema del núcleo tras aplicar el filtro `hwe_control_center_schema`,
 * con el que una instancia añade sus ajustes. El filtro solo puede poblar el grupo raíz
 * `instance` (la pestaña «Instancia»):
 *   · una clave raíz que no sea del núcleo ni `instance` se descarta y se registra;
 *   · un grupo del núcleo que el filtro elimine se restaura y se registra;
 *   · si `instance` deja de ser un grupo, o el filtro no devuelve un array, se descarta lo que
 *     devolvió y se registra.
 * Los avisos salen UNA vez por problema y proceso: el esquema se evalúa varias veces por
 * petición. Ver mu-plugins/instance/README.md.
 */
class Schema {

    /**
     * Esquema efectivo: el del núcleo más lo que aporte la instancia, ya validado.
     *
     * @return array<string,array>
     */
    public static function get(): array {
        $core     = self::core();
        $filtered = apply_filters('hwe_control_center_schema', $core);

        if (!is_array($filtered)) {
            Log::once(
                'schema:not-array',
                sprintf(
                    'el filtro hwe_control_center_schema debe devolver un array (devolvió %s); se usa el esquema del núcleo.',
                    get_debug_type($filtered)
                )
            );
            return self::expandTranslatable($core, self::secondaryLocales());
        }

        return self::expandTranslatable(self::enforceContract($core, $filtered), self::secondaryLocales());
    }

    /** Idiomas secundarios por defecto (los de `frontend/src/i18n/routing.ts` del framework). */
    public const DEFAULT_SECONDARY_LOCALES = 'en';

    /** Tipos que admiten `'translatable' => true`. */
    private const TRANSLATABLE_TYPES = ['text', 'textarea', 'lines', 'richtext'];

    /**
     * Sufijos de los idiomas secundarios configurados (`i18n.locales`), sin el idioma por
     * defecto (`brand.locale`) y normalizados para usarse en una clave: minúsculas y `-`
     * → `_` (`pt-BR` → `pt_br`). El frontend aplica la misma normalización.
     *
     * @return list<string>
     */
    public static function secondaryLocales(): array {
        $raw = Storage::get(['i18n', 'locales']);
        if (!is_string($raw)) {
            $raw = self::DEFAULT_SECONDARY_LOCALES;
        }
        $default = self::localeSuffix((string) Storage::get(['brand', 'locale'], 'es'));

        $suffixes = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) ?: [] as $code) {
            if ($code === '') {
                continue;
            }
            if (!preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/', $code)) {
                Log::once(
                    'schema:locale:' . $code,
                    sprintf('código de idioma «%s» ignorado en i18n.locales: usa códigos como «en» o «pt-BR».', $code)
                );
                continue;
            }
            $suffix = self::localeSuffix($code);
            if ($suffix !== $default && !in_array($suffix, $suffixes, true)) {
                $suffixes[] = $suffix;
            }
        }
        return $suffixes;
    }

    /** `pt-BR` → `pt_br`: forma del idioma dentro de una clave del esquema. */
    public static function localeSuffix(string $locale): string {
        return str_replace('-', '_', strtolower(trim($locale)));
    }

    /**
     * Añade, junto a cada nodo `translatable`, una variante `<clave>_<sufijo>` por idioma
     * secundario: mismo tipo y visibilidad, vacía por defecto (vacío = se hereda el texto
     * del idioma por defecto). Se aplica también a los campos de la instancia.
     *
     * Una variante declarada a mano en el esquema manda sobre la generada. Quitar un idioma
     * de `i18n.locales` oculta sus variantes, pero no borra lo guardado.
     *
     * @param array<string,mixed> $nodes
     * @param list<string>        $locales
     * @return array<string,mixed>
     */
    private static function expandTranslatable(array $nodes, array $locales): array {
        $result = [];
        foreach ($nodes as $key => $node) {
            if (self::isGroup($node)) {
                $node['children'] = self::expandTranslatable($node['children'], $locales);
                $result[$key]     = $node;
                continue;
            }
            $result[$key] = $node;
            if (!is_array($node) || empty($node['translatable'])) {
                continue;
            }
            $type = (string) ($node['type'] ?? 'text');
            if (!in_array($type, self::TRANSLATABLE_TYPES, true)) {
                Log::once(
                    'schema:translatable:' . $key,
                    sprintf('«translatable» ignorado en «%s»: solo admiten traducción los tipos %s.', (string) $key, implode(', ', self::TRANSLATABLE_TYPES))
                );
                continue;
            }
            foreach ($locales as $suffix) {
                $variant = $key . '_' . $suffix;
                if (array_key_exists($variant, $nodes)) {
                    continue;
                }
                $copy = $node;
                unset($copy['translatable'], $copy['token'], $copy['default'], $copy['description']);
                $result[$variant] = [
                    'label'       => sprintf('%s (%s)', (string) ($node['label'] ?? $key), strtoupper(str_replace('_', '-', $suffix))),
                    'default'     => '',
                    'description' => 'Vacío = se usa el texto del idioma por defecto.',
                ] + $copy;
            }
        }
        return $result;
    }

    /**
     * Aplica las reglas del contrato (ver el docblock de la clase) a lo que devolvió el filtro.
     *
     * @param array<string,array> $core
     * @param array<mixed>        $filtered
     * @return array<string,array>
     */
    private static function enforceContract(array $core, array $filtered): array {
        foreach (array_keys($filtered) as $key) {
            if (!array_key_exists($key, $core)) {
                Log::once(
                    'schema:root:' . (string) $key,
                    sprintf(
                        'clave raíz «%s» descartada de hwe_control_center_schema: los ajustes de la instancia van dentro del grupo «instance» ($schema[\'instance\'][\'children\'][\'%s\']).',
                        (string) $key,
                        (string) $key
                    )
                );
            }
        }

        $result = [];
        foreach ($core as $key => $coreNode) {
            if (!array_key_exists($key, $filtered)) {
                Log::once(
                    'schema:removed:' . $key,
                    sprintf('el grupo «%s» del núcleo fue eliminado por un filtro de hwe_control_center_schema; se restaura (el núcleo no se puede quitar).', $key)
                );
                $result[$key] = $coreNode;
                continue;
            }
            $result[$key] = $filtered[$key];
        }

        if (!self::isGroup($result['instance'])) {
            Log::once(
                'schema:instance-invalid',
                'el nodo «instance» de hwe_control_center_schema debe seguir siendo un grupo con «children» (array); se restaura vacío.'
            );
            $result['instance'] = $core['instance'];
        }

        return $result;
    }

    private static function isGroup(mixed $node): bool {
        return is_array($node)
            && ($node['type'] ?? null) === 'group'
            && is_array($node['children'] ?? null);
    }

    /**
     * El esquema del NÚCLEO, sin filtros: la fuente única de todos los campos.
     *
     * @return array<string,array>
     */
    public static function core(): array {
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
                        'translatable' => true,
                    ],
                    'description' => [
                        'type'    => 'textarea',
                        'label'   => 'Descripción breve',
                        'default' => 'Tienda headless construida con Next.js y WooCommerce.',
                        'public'  => true,
                        'translatable' => true,
                    ],
                    'url'         => [
                        'type'   => 'url',
                        'label'  => 'URL pública del frontend',
                        'public' => true,
                    ],
                    'logo'        => [
                        'type'        => 'image',
                        'label'       => 'Logo',
                        'default'     => '',
                        'public'      => true,
                        'description' => 'Logo de cabecera. Usa el selector de la medioteca. Vacío = se muestra el nombre del sitio como texto.',
                    ],
                    'email_logo'  => [
                        'type'        => 'image',
                        'label'       => 'Logo para correos (opcional)',
                        'default'     => '',
                        'public'      => true,
                        'description' => 'Variante del logo para el encabezado de los correos transaccionales. Vacío = usa el logo de cabecera.',
                    ],
                    'locale'      => [
                        'type'    => 'select',
                        'label'   => 'Idioma por defecto',
                        'default' => 'es',
                        'public'  => true,
                        'options' => ['es' => 'Español', 'en' => 'English'],
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* IDIOMAS                                                             */
            /* ------------------------------------------------------------------ */
            'i18n' => [
                'type'     => 'group',
                'label'    => 'Idiomas',
                'children' => [
                    'locales' => [
                        'type'        => 'text',
                        'label'       => 'Idiomas secundarios',
                        'default'     => self::DEFAULT_SECONDARY_LOCALES,
                        'public'      => true,
                        'description' => 'Códigos separados por comas (p. ej. «en, fr»), sin el idioma por defecto (Marca → Idioma por defecto). Deben coincidir con los «locales» de frontend/src/i18n/routing.ts. Cada campo traducible del panel muestra una variante por idioma tras guardar.',
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
                    'twitter'   => [
                        'type'        => 'text',
                        'label'       => 'Twitter / X',
                        'public'      => true,
                        'description' => 'Solo usuario(s), sin URL. Varias cuentas: sepáralas con « | ». El frontend arma https://twitter.com/{usuario}.',
                    ],
                    'instagram' => [
                        'type'        => 'text',
                        'label'       => 'Instagram',
                        'public'      => true,
                        'description' => 'Solo usuario(s), sin URL. Varias cuentas: sepáralas con « | ». El frontend arma https://instagram.com/{usuario}.',
                    ],
                    'facebook'  => [
                        'type'        => 'text',
                        'label'       => 'Facebook',
                        'public'      => true,
                        'description' => 'Solo slug(s) de página, sin URL. Varios: sepáralos con « | ». El frontend arma https://facebook.com/{slug}.',
                    ],
                    'linkedin'  => [
                        'type'        => 'text',
                        'label'       => 'LinkedIn (empresa)',
                        'public'      => true,
                        'description' => 'Solo slug(s) de empresa, sin URL. El frontend arma https://www.linkedin.com/company/{slug}.',
                    ],
                    'youtube'   => [
                        'type'        => 'text',
                        'label'       => 'YouTube',
                        'public'      => true,
                        'description' => 'Solo usuario(s) de canal (@handle), sin URL. Varios canales: sepáralos con « | ». El frontend arma https://youtube.com/@{usuario}.',
                    ],
                    'whatsapp'  => [
                        'type'        => 'text',
                        'label'       => 'WhatsApp',
                        'public'      => true,
                        'description' => 'Solo número(s) internacional(es), sin URL. Varios: sepáralos con « | » (el botón flotante usa el primero; el resto va a sameAs). Vacío = sin botón flotante.',
                    ],
                    'wikipedia' => [
                        'type'        => 'text',
                        'label'       => 'Wikipedia',
                        'public'      => true,
                        'description' => 'Solo título(s) de artículo (es), sin URL. Refuerza el reconocimiento de entidad (sameAs) por buscadores e IA.',
                    ],
                    'wikidata'  => [
                        'type'        => 'text',
                        'label'       => 'Wikidata',
                        'public'      => true,
                        'description' => 'Solo id(s) Q…, sin URL. Identificador de entidad legible por máquinas (sameAs).',
                    ],
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
                    'phone'   => [
                        'type'        => 'text',
                        'label'       => 'Teléfono fijo',
                        'public'      => true,
                        'description' => 'Texto visible en footer/contacto. Si no hay fijo, se usa el celular.',
                    ],
                    'mobile'  => [
                        'type'        => 'text',
                        'label'       => 'Celular',
                        'public'      => true,
                        'description' => 'Texto visible en footer/contacto. Distinto del WhatsApp de Redes (ese alimenta el botón flotante).',
                    ],
                    'address' => [
                        'type'        => 'text',
                        'label'       => 'Dirección legal (HQ)',
                        'public'      => true,
                        'description' => 'Dirección fiscal / sede principal. Los puntos de venta van en Sedes abajo.',
                    ],
                    ...self::addressPartFields('de la dirección legal'),
                    'locations' => [
                        'type'     => 'group',
                        'label'    => 'Sedes',
                        'children' => [
                            'location_1' => self::locationFields('Sede 1'),
                            'location_2' => self::locationFields('Sede 2'),
                            'location_3' => self::locationFields('Sede 3'),
                            'location_4' => self::locationFields('Sede 4'),
                        ],
                    ],
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
                    'currency'          => [
                        'type'        => 'select',
                        'label'       => 'Moneda',
                        'default'     => 'EUR',
                        'public'      => true,
                        'options'     => self::currencyOptions(),
                        'description' => 'Moneda de la tienda (ISO-4217). Al guardar se sincroniza con la moneda nativa de WooCommerce (Ajustes → General).',
                    ],
                    'country'           => ['type' => 'text',    'label' => 'País por defecto',      'default' => 'ES',   'public' => true],
                    'products_per_page' => ['type' => 'text',    'label' => 'Productos por página',  'default' => '12',   'public' => true],
                    'reviews_enabled'   => ['type' => 'boolean', 'label' => 'Habilitar reseñas',    'default' => false,  'public' => true],
                    'wishlist_enabled'  => ['type' => 'boolean', 'label' => 'Habilitar wishlist',   'default' => false,  'public' => true],
                    'coupons_enabled'   => ['type' => 'boolean', 'label' => 'Habilitar cupones',    'default' => false,  'public' => true],
                    'search_enabled'    => ['type' => 'boolean', 'label' => 'Habilitar búsqueda',   'default' => true,   'public' => true],
                    'checkout_login_required' => [
                        'type'        => 'boolean',
                        'label'       => 'Exigir inicio de sesión para comprar',
                        'default'     => true,
                        'public'      => true,
                        'description' => 'Activado = solo los clientes con cuenta pueden hacer un pedido. Desactivado = también se puede comprar como invitado. Al guardar se sincroniza con el ajuste nativo de WooCommerce «Permitir a los clientes realizar pedidos sin una cuenta».',
                    ],
                    'free_shipping' => [
                        'type'     => 'group',
                        'label'    => 'Envío gratis',
                        'children' => [
                            'enabled'    => [
                                'type'        => 'boolean',
                                'label'       => 'Activar envío gratis',
                                'default'     => false,
                                'description' => 'El envío del pedido sale gratis cuando la compra llega al valor mínimo con productos de los que aplican. Requiere el módulo `shipping` (ver `hwe_shipping_free_rule`).',
                            ],
                            'min_amount' => [
                                'type'        => 'number',
                                'label'       => 'Valor mínimo de compra',
                                'default'     => '0',
                                'min'         => 0,
                                'description' => 'En la moneda de la tienda, sin puntos ni decimales. 0 = sin mínimo.',
                            ],
                            'categories' => [
                                'type'        => 'product_multiselect',
                                'label'       => 'Categorías a las que aplica',
                                'default'     => '',
                                'options'     => FreeShippingRule::categoryOptions(),
                                'description' => 'Sin ninguna categoría ni producto marcado, aplica a todo el catálogo.',
                            ],
                            'products'   => [
                                'type'        => 'product_multiselect',
                                'label'       => 'Productos a los que aplica',
                                'default'     => '',
                                'options'     => FreeShippingRule::productOptions(),
                                'description' => 'Productos sueltos que también suman, además de los de las categorías de arriba.',
                            ],
                        ],
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* BANNERS (activación; la autoría vive en el plugin HWE Banners)      */
            /* ------------------------------------------------------------------ */
            'banners' => [
                'type'     => 'group',
                'label'    => 'Banners',
                'children' => [
                    'enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar sistema de banners',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'Activa el carrusel/banners en el frontend. La autoría de los banners vive en el plugin "HWE Banners" (menú Banners de wp-admin).',
                    ],
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
                            'noop'    => 'Sandbox (noop) — sin cobro real',
                            'avalpay' => 'Aval Pay (Colombia)',
                            'wompi'   => 'Wompi (Colombia)',
                            'payu'    => 'PayU (LATAM)',
                            'bold'    => 'Bold (Colombia)',
                        ],
                        'description' => 'La pasarela con la que se cobra el «Pago en línea». Si un campo de credenciales está vacío, el frontend usa su variable de entorno equivalente; la variable PAYMENT_PROVIDER del entorno, si existe, anula esta elección (solo desarrollo o emergencias).',
                    ],

                    'gateway_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar pago en línea',
                        'default'     => true,
                        'public'      => true,
                        'description' => 'Ofrece «Pago en línea» en el checkout con la pasarela activa.',
                    ],

                    'whatsapp_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar link de pago por WhatsApp',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'Ofrece «Link de pago por WhatsApp» en el checkout, independiente de la pasarela. Sin cobro en línea: el pedido queda «En espera» y la pantalla de gracias abre WhatsApp (número de Redes → WhatsApp) con el pedido ya escrito.',
                    ],

                    'cash_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar pago en efectivo',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'Ofrece «Efectivo» en el checkout: el cliente paga al recibir el pedido. Usa la pasarela nativa de WooCommerce «Contra reembolso», que se activa o desactiva al guardar aquí.',
                    ],

                    'avalpay' => [
                        'type'     => 'group',
                        'label'    => 'Aval Pay',
                        'children' => [
                            'login'      => ['type' => 'text',   'label' => 'Login (Identificador)',         'public' => false, 'description' => 'Campo "Identificador" de la consola de Aval Pay.'],
                            'secret_key' => ['type' => 'secret', 'label' => 'Secret Key (Llave transaccional)', 'public' => false, 'description' => 'Se almacena cifrada (AES-256-GCM). Déjalo en blanco al guardar para conservar el valor actual.'],
                            'base_url'   => ['type' => 'text',   'label' => 'URL base de la API',            'public' => false, 'description' => 'Producción: https://checkout.avalpaycenter.com — Pruebas: https://checkout.test.avalpaycenter.com. Las credenciales son distintas en cada entorno.'],
                        ],
                    ],

                    'wompi' => [
                        'type'     => 'group',
                        'label'    => 'Wompi',
                        'children' => [
                            'public_key'       => ['type' => 'text',   'label' => 'Llave pública',       'public' => false, 'description' => 'Empieza por pub_test_ (sandbox) o pub_prod_ (producción); el prefijo decide el entorno.'],
                            'integrity_secret' => ['type' => 'secret', 'label' => 'Secreto de integridad', 'public' => false, 'description' => 'Firma la redirección al checkout. Se almacena cifrado; déjalo en blanco al guardar para conservar el valor actual.'],
                            'events_secret'    => ['type' => 'secret', 'label' => 'Secreto de eventos',    'public' => false, 'description' => 'Verifica cada notificación en el webhook. Se almacena cifrado; déjalo en blanco al guardar para conservar el valor actual.'],
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
                        'label'       => 'Logo de la organización (JSON-LD)',
                        'public'      => true,
                        'description' => 'Logo usado en el schema Organization (reconocimiento de entidad por buscadores/IA). Selecciónalo desde la medioteca. Vacío = se usa el icono generado dinámicamente desde la marca.',
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
                    'alternate_names'           => [
                        'type'        => 'textarea',
                        'label'       => 'Otros nombres de la marca',
                        'public'      => true,
                        'description' => 'Nombres anteriores o alternativos por los que se conoce a la marca, uno por línea o separados por comas. Se declaran en el schema Organization (alternateName).',
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
                        'translatable' => true,
                        'description' => 'Una pregunta y respuesta por línea, separadas por "|". Se publican como JSON-LD FAQPage (muy citado por la IA).',
                    ],
                    'faq_payments'     => [
                        'type'        => 'textarea',
                        'label'       => 'Preguntas frecuentes sobre pagos electrónicos',
                        'default'     => '',
                        'public'      => true,
                        'translatable' => true,
                        'description' => 'Mismo formato "pregunta | respuesta". Se muestran como segunda columna de la FAQ y entran en el mismo JSON-LD FAQPage. Vacío = una sola columna.',
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* INSTANCIA                                                           */
            /* Vacío en el núcleo. Una instancia añade aquí sus ajustes con el      */
            /* filtro `hwe_control_center_schema` (ver «CONTRATO CON LA INSTANCIA»). */
            /* El panel lo pinta como la pestaña «Instancia» en cuanto tiene hijos.  */
            /* ------------------------------------------------------------------ */
            'instance' => [
                'type'     => 'group',
                'label'    => 'Instancia',
                'children' => [],
            ],

        ];
    }

    /**
     * Opciones del select de moneda: todas las que reconoce WooCommerce
     * (`get_woocommerce_currencies()`, el mismo catálogo que usa internamente
     * para checkout/pedidos/pasarelas), para que el código elegido aquí sea
     * siempre uno que WooCommerce también entienda al sincronizarlo
     * (ver `WooCurrencySync`).
     *
     * @return array<string,string> código ISO-4217 => "CÓDIGO – Nombre"
     */
    private static function currencyOptions(): array {
        if (!function_exists('get_woocommerce_currencies')) {
            return ['EUR' => 'EUR'];
        }

        $options = [];
        foreach (get_woocommerce_currencies() as $code => $name) {
            $options[$code] = $code . ' – ' . $name;
        }
        ksort($options);

        return $options;
    }

    /**
     * Partes de una dirección para datos estructurados (addressLocality…).
     *
     * @return array<string,array>
     */
    private static function addressPartFields(string $of): array {
        return [
            'city'        => [
                'type'        => 'text',
                'label'       => 'Ciudad / municipio',
                'public'      => true,
                'description' => "Ciudad {$of} para los datos estructurados (addressLocality).",
            ],
            'region'      => [
                'type'        => 'text',
                'label'       => 'Departamento / región',
                'public'      => true,
                'description' => 'Para los datos estructurados (addressRegion).',
            ],
            'postal_code' => [
                'type'        => 'text',
                'label'       => 'Código postal',
                'public'      => true,
                'description' => 'Para los datos estructurados (postalCode).',
            ],
        ];
    }

    /**
     * Campos de una sede (nombre + dirección + Maps + contacto + horario + coordenadas).
     *
     * @return array{type:string,label:string,children:array<string,array>}
     */
    private static function locationFields(string $label): array {
        return [
            'type'     => 'group',
            'label'    => $label,
            'children' => [
                'label'   => [
                    'type'   => 'text',
                    'label'  => 'Nombre',
                    'public' => true,
                ],
                'address' => [
                    'type'   => 'text',
                    'label'  => 'Dirección',
                    'public' => true,
                ],
                ...self::addressPartFields('de la sede'),
                'maps'    => [
                    'type'        => 'url',
                    'label'       => 'URL de Google Maps',
                    'public'      => true,
                    'description' => 'Enlace «Abrir en Maps». Vacío = la sede no se enlaza.',
                ],
                'phone'   => [
                    'type'   => 'text',
                    'label'  => 'Teléfono',
                    'public' => true,
                ],
                'hours'   => [
                    'type'        => 'textarea',
                    'label'       => 'Horario de atención',
                    'public'      => true,
                    'description' => 'Una franja por línea, formato schema.org: '
                        . '"Mo-Fr 08:00-18:00" (abreviaturas en inglés: Mo,Tu,We,Th,Fr,Sa,Su).',
                ],
                'latitude' => [
                    'type'        => 'text',
                    'label'       => 'Latitud',
                    'public'      => true,
                    'description' => 'Se ignora si no es un número válido entre -90 y 90.',
                ],
                'longitude' => [
                    'type'        => 'text',
                    'label'       => 'Longitud',
                    'public'      => true,
                    'description' => 'Se ignora si no es un número válido entre -180 y 180.',
                ],
            ],
        ];
    }
}
