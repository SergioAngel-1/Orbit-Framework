<?php
/**
 * Ejemplo mínimo de instancia (30): un tag ISR propio.
 *
 * WordPress solo envía al frontend los tags registrados con `hwe_revalidation_tags`, y el
 * frontend solo acepta los declarados en `src/instance/registry.ts` (`revalidationTags`).
 * Ambos lados deben declarar el mismo tag (`example` aquí y allí).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declara el tag ISR `example` (función nombrada para poder probarla; ver el test).
 *
 * @param list<string> $tags
 * @return list<string>
 */
function hwe_example_tags(array $tags): array {
    return [...$tags, 'example'];
}

add_filter('hwe_revalidation_tags', 'hwe_example_tags');
