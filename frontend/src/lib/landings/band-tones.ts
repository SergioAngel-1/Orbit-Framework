// ============================================================================
//  Alternancia de banda del molde editorial.
//
//  El orquestador conoce el color FIJO de cada sección que lo trae en su
//  className (hero, lead, las únicas, closing…) y deja que esta función
//  decida el de las secciones de contenido, que son las únicas configurables.
//  Se recorre el flujo YA RESUELTO —bases consumidas fuera, únicas dentro—
//  porque es el único orden que refleja lo que ve el lector.
//
//  `"other"` = la banda tiene un color propio que no participa de la pareja
//  surface/background (la cita en `bg-accent`, el cierre en `bg-brand-light`,
//  una sección de contenido con layout `full` en `bg-brand-light`): no recibe
//  tono y tampoco corta la cadena, para que el siguiente contenido siga
//  contrastando con la última banda de la pareja.
// ============================================================================

export type BandTone = "surface" | "background";
export type FixedTone = BandTone | "other";

/**
 * Color fijo del siguiente nodo que lo traiga, o `null` si antes aparece una
 * sección de contenido (cuyo tono se decide después y por tanto no restringe)
 * o se acaba el flujo. Los nodos `"other"` se saltan: no participan de la
 * pareja surface/background.
 */
function nextFixedTone(
  ordered: { id: string }[],
  from: number,
  fixed: Record<string, FixedTone>,
): BandTone | null {
  for (let i = from; i < ordered.length; i += 1) {
    const known = fixed[ordered[i].id];
    if (known === "other") continue;
    if (known === "surface" || known === "background") return known;
    return null; // sección de contenido: ella se adaptará a esta.
  }
  return null;
}

export function assignContentTones(
  ordered: { id: string }[],
  fixed: Record<string, FixedTone>,
): Map<string, BandTone> {
  const out = new Map<string, BandTone>();
  // El hero abre en `bg-background`; si el flujo empieza por otra cosa, el
  // primer nodo con color fijo corrige el arranque de inmediato.
  let previous: BandTone = "background";

  for (const [index, node] of ordered.entries()) {
    const known = fixed[node.id];
    if (known === "other") continue;
    if (known === "surface" || known === "background") {
      previous = known;
      continue;
    }
    let tone: BandTone = previous === "surface" ? "background" : "surface";
    // Mirar también hacia adelante: alternar solo con la banda anterior deja
    // dos bandas iguales seguidas cuando la SIGUIENTE trae color fijo (el caso
    // real de `s2` justo antes de la banda de consulta, ambas surface).
    if (nextFixedTone(ordered, index + 1, fixed) === tone) {
      tone = tone === "surface" ? "background" : "surface";
    }
    out.set(node.id, tone);
    previous = tone;
  }

  return out;
}
