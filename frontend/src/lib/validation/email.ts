/** Normaliza un email para compararlo o enviarlo (trim + minúsculas). */
export function normalizeEmail(raw: string): string {
  return (raw || "").trim().toLowerCase();
}
