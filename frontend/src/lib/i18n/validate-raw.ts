/**
 * Valida en runtime la forma de los datos devueltos por `t.raw()` de next-intl.
 * Lanza un error legible si `raw` no tiene la forma esperada, en vez de fallar en
 * silencio en producción.
 */
export function parseRawList<T>(
  raw: unknown,
  guard: (item: unknown) => item is T,
  context: string,
): T[] {
  if (!Array.isArray(raw)) {
    throw new Error(
      `i18n: se esperaba un array en "${context}", se recibió ${raw === null ? "null" : typeof raw}`,
    );
  }
  const invalidIndex = raw.findIndex((item) => !guard(item));
  if (invalidIndex !== -1) {
    throw new Error(`i18n: item inválido en "${context}"[${invalidIndex}]`);
  }
  return raw as T[];
}
