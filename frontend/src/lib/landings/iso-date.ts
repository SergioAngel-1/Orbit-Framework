// ============================================================================
//  Validación de las fechas que el CMS escribe a mano.
//
//  Todos los campos de fecha del sistema de landings son de TEXTO LIBRE: ni el
//  Control Center ni la caja de producto tienen tipo fecha. Por eso se validan
//  aquí antes de que viajen a un JSON-LD: una fecha malformada no degrada el
//  nodo, lo INVALIDA entero para el buscador — que es peor que no declarar
//  ninguna.
//
//  Vive en su propio módulo porque lo comparten la landing de producto y las
//  landings editoriales, y las dos tienen ya sus propias razones para no
//  importarse entre sí.
// ============================================================================

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;
const ISO_MONTH = /^\d{4}-\d{2}$/;

/** ¿`AAAA-MM-DD` existe de verdad? `2026-02-31` casa con el patrón y no es un día. */
function realDay(raw: string): boolean {
  const parsed = new Date(`${raw}T00:00:00Z`);
  if (Number.isNaN(parsed.getTime())) return false;
  return parsed.toISOString().slice(0, 10) === raw;
}

/**
 * `AAAA-MM-DD` válida, o `null`.
 *
 * Para las fechas del artículo (`datePublished` / `dateModified`), donde la
 * precisión de día importa: el buscador las compara con lo que ya vio.
 */
export function isoDate(value: unknown): string | null {
  const raw = typeof value === "string" ? value.trim() : "";
  if (!raw || !ISO_DAY.test(raw)) return null;
  return realDay(raw) ? raw : null;
}

/**
 * `AAAA-MM-DD` **o** `AAAA-MM` válida, o `null`.
 *
 * Para el `uploadDate` de un vídeo, donde el día muchas veces no se sabe: el
 * editor recuerda el mes en que lo subió y no la fecha exacta. `AAAA-MM` es ISO
 * 8601 legítimo y schema.org lo admite, así que es preferible a inventar un día
 * 1 que nadie ha comprobado. La contrapartida está documentada en el manual: con
 * fecha parcial el vídeo no opta al resultado enriquecido, que pide fecha
 * completa.
 */
export function isoDateOrMonth(value: unknown): string | null {
  const raw = typeof value === "string" ? value.trim() : "";
  if (!raw) return null;
  if (ISO_MONTH.test(raw)) {
    const month = Number(raw.slice(5, 7));
    return month >= 1 && month <= 12 ? raw : null;
  }
  return isoDate(raw);
}
