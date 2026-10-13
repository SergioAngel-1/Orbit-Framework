// ============================================================================
//  Etiquetas de una entrada cuando el espacio es de UNA fila (el destacado del
//  blog): las `limit` más usadas en el blog (`count` de WordPress) y cuántas
//  quedan fuera, que la vista pinta como «+N». Así no hay que recortar el
//  contenedor —recortarlo partía la segunda fila de píldoras a la mitad—.
//
//  Módulo puro.
// ============================================================================

export interface TopTags<T> {
  visible: T[];
  hidden: T[];
}

/**
 * Las `limit` etiquetas con más entradas. Un empate (o sin `count`) conserva
 * el orden en que llegan de WordPress (`sort` es estable).
 */
export function pickTopTags<T extends { count?: number | null }>(
  tags: readonly T[],
  limit: number,
): TopTags<T> {
  const ranked = [...tags].sort((a, b) => (b.count ?? 0) - (a.count ?? 0));
  return { visible: ranked.slice(0, limit), hidden: ranked.slice(limit) };
}
