// ============================================================================
//  Guarda de indexación por ENTORNO (staging, preproducción, demos).
//
//  `HWE_NOINDEX=true` en el entorno del frontend saca el sitio entero de los
//  buscadores sin tocar la config de WordPress: `robots.txt` veta todo, cada
//  página lleva `noindex, nofollow` en su `<meta>` y el proxy añade la cabecera
//  `X-Robots-Tag`. Es la guarda que no depende de que alguien se acuerde de
//  cambiar «Indexación» en el Control Center — y la que NO viaja con una copia
//  de la base de datos de staging a producción, que es justo como un sitio en
//  producción acaba fuera de Google sin que nadie lo note.
//
//  Variable de SERVIDOR (sin `NEXT_PUBLIC_`): se lee en ejecución, así que la
//  misma imagen sirve para staging y para producción. Módulo puro, apto para edge.
// ============================================================================

/** ¿El entorno pide no indexar el sitio? Acepta `true`, `1`, `yes` (sin distinguir mayúsculas). */
export function indexingBlockedByEnv(
  env: Record<string, string | undefined> = process.env,
): boolean {
  return /^(true|1|yes)$/i.test((env.HWE_NOINDEX ?? "").trim());
}

/** Valor de `X-Robots-Tag` con la guarda activa. */
export const ENV_NOINDEX_HEADER = "noindex, nofollow";
