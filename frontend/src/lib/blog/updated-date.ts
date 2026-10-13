/**
 * ¿Merece la entrada un «Actualizado el…» visible? Solo si `modified` va al
 * menos un día por detrás de la publicación: WordPress mueve `modified` con
 * cualquier guardado (una errata, una traducción añadida) y, sin umbral, casi
 * toda entrada diría «actualizada» el mismo día que salió.
 *
 * Las dos fechas son la hora local del sitio, sin zona (`2026-03-10T08:00:00`):
 * se parsean igual, así que la resta es válida.
 */
const ONE_DAY_MS = 24 * 60 * 60 * 1000;

export function showUpdatedDate(
  published: string,
  modified: string | null | undefined,
): boolean {
  if (!published || !modified) return false;
  const start = Date.parse(published);
  const end = Date.parse(modified);
  if (Number.isNaN(start) || Number.isNaN(end)) return false;
  return end - start >= ONE_DAY_MS;
}
