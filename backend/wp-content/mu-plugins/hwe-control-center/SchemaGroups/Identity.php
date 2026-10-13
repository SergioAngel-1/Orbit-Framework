<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: marca, idiomas y acceso (login por teléfono).
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Identity {

    /**
     * @return array<string,array>
     */
    private static function identityGroups(): array {
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
            /* ACCESO                                                              */
            /* ------------------------------------------------------------------ */
            'auth' => [
                'type'     => 'group',
                'label'    => 'Acceso',
                'children' => [
                    'phone_login_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Permitir iniciar sesión y registrarse con el teléfono',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'El teléfono de la cuenta (el de facturación, único por cuenta) sirve como usuario. Apagado, solo email o nombre de usuario.',
                    ],
                    'phone_default_dial_code' => [
                        'type'        => 'text',
                        'label'       => 'Prefijo telefónico por defecto',
                        'default'     => '',
                        'public'      => true,
                        'description' => 'P. ej. «+34». Permite escribir el número sin prefijo al iniciar sesión y es el prefijo inicial del registro. Vacío = el número se escribe siempre con su prefijo (+…).',
                    ],
                ],
            ],

        ];
    }
}
