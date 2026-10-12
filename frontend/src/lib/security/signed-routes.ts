// ============================================================================
//  Rutas SERVER-TO-SERVER que el proxy exime de la barrera de Origin.
//
//  Las llama WooCommerce, el Control Center o la pasarela de pago, nunca un
//  navegador. Una petición así NO trae `Origin` ni `Referer` (no existe un
//  documento que la origine) y la barrera trata esa ausencia como origen no
//  permitido: 403 antes de que el handler llegue a verificar nada. Con
//  `ALLOWED_ORIGIN` configurado —es decir, en producción— eso rompía la
//  revalidación ISR, el dispatcher de pedidos y, lo más grave, la confirmación
//  de la pasarela, que es LA prueba de que un pedido se cobró. Y WooCommerce
//  desactiva un webhook tras cinco entregas fallidas, así que el efecto era
//  permanente hasta que alguien lo reactivaba a mano.
//
//  Eximir una ruta NO la deja abierta: cada una verifica una FIRMA HMAC del
//  cuerpo antes de hacer nada, y ninguna actúa en nombre del visitante ni lee su
//  sesión, así que el vector que cubre el Origin —CSRF desde el navegador de un
//  tercero— no les aplica. La firma es una barrera estrictamente más fuerte.
//
//  Por eso la lista es CERRADA: rutas exactas y un único patrón, no prefijos. Un
//  prefijo abierto eximiría en silencio cualquier handler futuro que cuelgue de
//  él, lo verifique o no. Para añadir una ruta hay que tocar esta lista a
//  propósito, y `tests/unit/signed-routes.test.ts` comprueba contra los
//  `route.ts` reales que toda ruta eximida verifica su firma antes de actuar.
//
//  Módulo puro y edge-compatible: lo importa `src/proxy.ts`.
// ============================================================================

/** Rutas exactas (webhooks de WooCommerce y revalidación). */
const SIGNED_PATHS: ReadonlySet<string> = new Set([
  "/api/revalidate", // Woo (catálogo) + Control Center (config, banners, envío, posts…)
  "/api/webhooks/woocommerce/order-created",
  "/api/webhooks/woocommerce/order-updated",
]);

/** Webhook de pago: `/api/payments/webhook/<proveedor>`, un único segmento. */
const PAYMENT_WEBHOOK = /^\/api\/payments\/webhook\/[a-z0-9_-]+$/;

/** ¿Se autentica esta ruta por firma, y por tanto queda fuera de la barrera de Origin? */
export function isSignedRoute(pathname: string): boolean {
  return SIGNED_PATHS.has(pathname) || PAYMENT_WEBHOOK.test(pathname);
}
