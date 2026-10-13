// ============================================================================
//  Partir el cuerpo de una sección por su PRIMER párrafo.
//
//  Lo usa la disposición `mirror-pair` de las landings editoriales, que reparte
//  el mismo cuerpo entre dos tarjetas enfrentadas: la primera acompaña al
//  titular y la segunda cierra la composición al otro lado.
//
//  Hermano de `splitByH3` (`prose-blocks.ts`) y con el mismo alcance: solo
//  TROCEA, no sanea — el HTML ya viene de `sanitizeHtml` cuando se construye la
//  landing. Y por el mismo motivo que aquel, corta por texto y no con un
//  parser: en el servidor no hay DOM, y el cuerpo del CMS es una lista plana de
//  `<p>` / `<ul>` sin anidar.
// ============================================================================

export interface SplitBody {
  /** Primer bloque. Cadena vacía solo si `html` lo estaba. */
  first: string;
  /** Lo que sigue. Vacío si el cuerpo era un solo bloque. */
  rest: string;
}

/** Cierres que cuentan como final del primer bloque, en orden de aparición. */
const CLOSERS = ["</p>", "</ul>", "</ol>", "</blockquote>"];

/**
 * @param html Cuerpo ya saneado de una sección.
 * @returns El primer bloque y el resto. Sin ningún cierre reconocible devuelve
 *   todo en `first` — que es lo que deja a la disposición degradar a una sola
 *   tarjeta en vez de partir la frase por la mitad.
 */
export function splitFirstBlock(html: string | null | undefined): SplitBody {
  const src = (html ?? "").trim();
  if (!src) return { first: "", rest: "" };

  let cut = -1;
  for (const closer of CLOSERS) {
    const at = src.indexOf(closer);
    if (at !== -1 && (cut === -1 || at + closer.length < cut)) {
      cut = at + closer.length;
    }
  }
  if (cut === -1) return { first: src, rest: "" };

  return { first: src.slice(0, cut).trim(), rest: src.slice(cut).trim() };
}
