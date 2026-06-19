// Utilitaria `cn`: fusiona clases Tailwind sin dependencias externas.
// Acepta strings, objetos condicionales y valores falsy (los ignora).
type ClassInput =
  | string
  | undefined
  | null
  | false
  | Record<string, boolean | undefined | null>;

export function cn(...inputs: ClassInput[]): string {
  const tokens: string[] = [];
  for (const input of inputs) {
    if (!input) continue;
    if (typeof input === "string") {
      tokens.push(input);
    } else {
      for (const [cls, ok] of Object.entries(input)) {
        if (ok) tokens.push(cls);
      }
    }
  }
  return tokens.join(" ");
}
