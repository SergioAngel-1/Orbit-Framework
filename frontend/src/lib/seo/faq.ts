export interface FaqItem {
  question: string;
  answer: string;
}

/**
 * Parsea el textarea de FAQ del Control Center.
 * Formato: una pregunta y respuesta por línea, separadas por "|".
 *   ¿Hacéis envíos internacionales? | Sí, enviamos a toda Europa.
 * Líneas sin "|" o incompletas se ignoran.
 */
export function parseFaq(raw?: string | null): FaqItem[] {
  if (!raw) return [];
  return raw
    .split("\n")
    .map((line) => {
      const idx = line.indexOf("|");
      if (idx === -1) return null;
      const question = line.slice(0, idx).trim();
      const answer = line.slice(idx + 1).trim();
      if (!question || !answer) return null;
      return { question, answer };
    })
    .filter((item): item is FaqItem => item !== null);
}
