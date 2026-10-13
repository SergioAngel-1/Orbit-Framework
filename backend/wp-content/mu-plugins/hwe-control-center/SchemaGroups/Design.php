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

        ];
    }
}
