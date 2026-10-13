// ============================================================================
//  Ritmo del prerender, por variable de entorno.
//
//  `next build` prerenderiza cada página estática y cada una dispara varias
//  consultas a WordPress. En un servidor pequeño eso ocurre en la MISMA máquina
//  que Apache, MariaDB, Redis y el Next que sirve la web: a la concurrencia por
//  defecto el build se convierte en un ataque de carga contra su propio CMS
//  (`500` en /graphql, luego `408`, y Apache colgado).
//
//  Se expone por entorno y no como constante porque los dos builds son
//  OPUESTOS: el del CI habla con un stub que responde al instante y no debe
//  pagar ninguna throttle; el de producción habla con WordPress real y sí. Sin
//  las variables, la configuración queda exactamente como estaba.
//
//  `staticGenerationMaxConcurrency` es el que importa aquí: son las páginas en
//  vuelo POR WORKER, y el número de workers ya lo acota la CPU.
// ============================================================================

/** Entero positivo, o `null` si el valor no sirve (ausente, vacío, NaN, ≤ 0). */
function positiveInt(raw) {
  if (raw === undefined || raw === null || String(raw).trim() === "") return null;
  const value = Number(raw);
  return Number.isInteger(value) && value > 0 ? value : null;
}

/**
 * Opciones `experimental.staticGeneration*` derivadas del entorno.
 *
 * Devuelve SOLO las claves cuya variable trae un entero positivo: una clave con
 * `NaN` o `undefined` no es lo mismo que no pasar la clave — Next la leería y
 * el build cambiaría de comportamiento sin que nadie se entere.
 *
 * @param {Record<string, string | undefined>} env
 * @returns {{ staticGenerationMaxConcurrency?: number, staticGenerationMinPagesPerWorker?: number }}
 */
export function staticGenerationOptions(env = {}) {
  const options = {};

  const maxConcurrency = positiveInt(env.NEXT_STATIC_GENERATION_MAX_CONCURRENCY);
  if (maxConcurrency !== null) options.staticGenerationMaxConcurrency = maxConcurrency;

  const minPagesPerWorker = positiveInt(
    env.NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER,
  );
  if (minPagesPerWorker !== null)
    options.staticGenerationMinPagesPerWorker = minPagesPerWorker;

  return options;
}

/**
 * Segundos que Next concede a UNA página antes de abandonarla
 * (`staticPageGenerationTimeout`, opción de primer nivel — no va en
 * `experimental`). Su default es **60**, y es literalmente el «took more than
 * 60 seconds» del log de un build fallido.
 *
 * Por qué se sube con WordPress lento: la primera página que dispara un barrido
 * completo de un catálogo grande (varias páginas por cursor) paga un fetch en
 * frío. Y lo que lo convierte en cascada es que un fetch abortado NO se cachea:
 * la siguiente página vuelve a intentarlo desde cero, hasta que el build se
 * rinde. Basta con que UNA página tenga margen para completarlo: a partir de ahí
 * queda en el Data Cache y las demás lo leen de ahí. Subir el techo no hace el
 * build más lento — solo evita que muera en la única página que paga el viaje.
 *
 * @param {Record<string, string | undefined>} env
 * @returns {number | undefined} segundos, o `undefined` para dejar el default
 */
export function staticPageGenerationTimeout(env = {}) {
  return positiveInt(env.NEXT_STATIC_PAGE_GENERATION_TIMEOUT) ?? undefined;
}
