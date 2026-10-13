/**
 * Serializa JSON para embeber en `<script type="application/ld+json">`.
 * `JSON.stringify` no escapa `<`, así un valor con `</script>` rompe el HTML.
 */
export function safeJsonLd(data: unknown): string {
  return JSON.stringify(data)
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026")
    .replace(/\u2028/g, "\\u2028")
    .replace(/\u2029/g, "\\u2029");
}
