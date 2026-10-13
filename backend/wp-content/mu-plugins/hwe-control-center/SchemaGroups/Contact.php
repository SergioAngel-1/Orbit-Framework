<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: redes sociales y datos legales (incluidas las sedes).
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Contact {

    /**
     * @return array<string,array>
     */
    private static function contactGroups(): array {
        return [

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

        ];
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
