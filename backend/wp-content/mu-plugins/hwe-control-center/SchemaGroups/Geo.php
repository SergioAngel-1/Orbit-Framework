<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: GEO (optimización para agentes de IA: crawlers, llms.txt, FAQ).
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Geo {

    /**
     * @return array<string,array>
     */
    private static function geoGroups(): array {
        return [

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

        ];
    }
}
