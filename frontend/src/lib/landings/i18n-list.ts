/**
 * Lee un array de datos de i18n (`t.raw(...)`, que devuelve `unknown`).
 *
 * next-intl devuelve la propia clave como texto cuando falta, y `undefined` cuando el mensaje
 * existe pero no es un array. Las secciones únicas por código usan el resultado para degradar a
 * una lista vacía en vez de reventar el render de toda la landing.
 */
export function asList<T>(raw: unknown): T[] {
  return Array.isArray(raw) ? (raw as T[]) : [];
}
