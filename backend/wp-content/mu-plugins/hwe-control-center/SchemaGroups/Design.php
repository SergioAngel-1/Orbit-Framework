<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: design tokens: paleta, tipografías y medidas.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Design {

    /**
     * @return array<string,array>
     */
    private static function designGroups(): array {
        return [

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
                                'default' => '#111111',
                                'token'   => '--color-brand',
                                'public'  => true,
                            ],
                            'brand_dark'  => [
                                'type'    => 'color',
                                'label'   => 'Color oscuro (hover/activo)',
                                'default' => '#000000',
                                'token'   => '--color-brand-dark',
                                'public'  => true,
                            ],
                            'brand_light' => [
                                'type'    => 'color',
                                'label'   => 'Color claro (énfasis suave)',
                                'default' => '#3a3a3a',
                                'token'   => '--color-brand-light',
                                'public'  => true,
                            ],
                            'secondary'      => [
                                'type'    => 'color',
                                'label'   => 'Color secundario',
                                'default' => '#2a3fd6',
                                'token'   => '--color-secondary',
                                'public'  => true,
                            ],
                            'secondary_dark' => [
                                'type'    => 'color',
                                'label'   => 'Color secundario (hover/activo)',
                                'default' => '#1f2fa3',
                                'token'   => '--color-secondary-dark',
                                'public'  => true,
                            ],
                            'accent'         => [
                                'type'    => 'color',
                                'label'   => 'Color de énfasis',
                                'default' => '#f2c200',
                                'token'   => '--color-accent',
                                'public'  => true,
                            ],
                            'surface'        => [
                                'type'    => 'color',
                                'label'   => 'Superficie (tarjetas/paneles)',
                                'default' => '#e9e9e6',
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
                                'description' => 'Carga la fuente en los correos. La web no la importa: si la fuente no está cargada en el frontend, se usa la que este traiga con next/font.',
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
                                'description' => 'Carga la fuente de titulares en los correos. La web no la importa (ver la URL de la fuente principal).',
                            ],
                        ],
                    ],

                ],
            ],

        ];
    }
}
