import type { FaqItem } from "@/lib/seo/faq";
import { htmlToPlainText } from "@/lib/format";
import type { LandingAdvice } from "@/lib/landings/types";

/**
 * Tope POR TIPO de la sección de consejos. La lista `advice` del plugin
 * `hwe-product-landing` es mixta: filas sin `heading` son consejos, filas con
 * `heading` son preguntas. Cada grupo se pinta en su propio panel y se capa por
 * separado a este valor — por eso el `max_rows` de la lista es el doble
 * (`2 * ADVICE_MAX_PER_TYPE`; un test de paridad lo comprueba contra `Fields.php`).
 */
export const ADVICE_MAX_PER_TYPE = 10;

export interface SplitAdvice {
  /** Ítems sin `heading` → lista simple de consejos. */
  tips: LandingAdvice[];
  /** Ítems con `heading` → preguntas desplegables. */
  questions: LandingAdvice[];
}

/**
 * Reparte la lista mixta `advice` en sus dos paneles y recorta cada uno a
 * `ADVICE_MAX_PER_TYPE`. El orden del CMS se conserva dentro de cada grupo. Un
 * ítem cuenta como pregunta si trae `heading` no vacío.
 */
export function splitAdvice(items: LandingAdvice[]): SplitAdvice {
  const tips: LandingAdvice[] = [];
  const questions: LandingAdvice[] = [];
  for (const item of items) {
    if (item.heading) {
      questions.push(item);
    } else {
      tips.push(item);
    }
  }
  return {
    tips: tips.slice(0, ADVICE_MAX_PER_TYPE),
    questions: questions.slice(0, ADVICE_MAX_PER_TYPE),
  };
}

/**
 * Preguntas de la sección de consejos para el `FAQPage` JSON-LD: solo las que
 * tienen encabezado Y respuesta real, con la sección activa y recortadas igual
 * que la sección visible — el marcado y lo que se ve deben coincidir. Vacío =
 * no se emite `FAQPage`.
 */
export function advicePageFaq(advice: {
  enabled: boolean;
  items: LandingAdvice[];
}): FaqItem[] {
  if (!advice.enabled) return [];
  return splitAdvice(advice.items).questions.flatMap((item) => {
    const answer = htmlToPlainText(item.body).trim();
    return item.heading && answer ? [{ question: item.heading, answer }] : [];
  });
}
