// ============================================================================
//  Mensajes de error de WooCommerce → texto plano para la UI.
//
//  WooCommerce escapa sus mensajes para HTML (`esc_html__`): el código de un
//  cupón llega como `&quot;bienvenida10&quot;` y algunos mensajes traen
//  etiquetas. La UI los pinta como TEXTO (React escapa), así que lo que se ve
//  era el `&quot;` literal. Se quitan las etiquetas y se decodifican las
//  entidades, en ese orden: decodificar primero convertiría `&lt;b&gt;` en una
//  etiqueta «nueva» que luego se borraría.
// ============================================================================

const NAMED: Record<string, string> = {
  quot: '"',
  amp: "&",
  lt: "<",
  gt: ">",
  apos: "'",
  nbsp: " ",
};

export function wcMessageToText(message: string): string {
  return message
    .replace(/<[^>]*>/g, "")
    .replace(/&(?:#(\d+)|#x([0-9a-f]+)|([a-z]+));/gi, (whole, dec, hex, name) => {
      if (dec) return String.fromCodePoint(Number(dec));
      if (hex) return String.fromCodePoint(parseInt(hex, 16));
      return NAMED[String(name).toLowerCase()] ?? whole;
    })
    .trim();
}
