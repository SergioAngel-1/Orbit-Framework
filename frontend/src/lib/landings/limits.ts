// ============================================================================
//  Topes de filas de las listas de la landing de producto.
//
//  Son los `max_rows` de `Fields::REPEATERS` del plugin `hwe-product-landing`:
//  el plugin recorta lo que se guarda y la vista pinta como mucho esto.
//  `tests/unit/product-landing-parity.test.ts` los compara con `Fields.php`; si uno cambia, el
//  test exige cambiar el otro (el consejo y la pregunta se cuentan por
//  separado: ver `ADVICE_MAX_PER_TYPE`).
// ============================================================================

export const LANDING_MAX_COMPONENTS = 11;
export const LANDING_MAX_QUALITIES = 11;
