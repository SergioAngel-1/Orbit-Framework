import "server-only";
import { getRedis } from "@/lib/redis/client";
import { logRedisFailure } from "@/lib/redis/log";

// ============================================================================
//  Caché de la elegibilidad para reseñar (por usuario y producto).
//
//  `ReviewGate` se monta en TODA ficha de producto —no está detrás de una
//  pestaña—, así que cada vista de PDP de un usuario con sesión disparaba tres
//  llamadas sin caché a la REST de administración de WooCommerce
//  (`/orders`, `/customers/{id}`, `/products/reviews`) para resolver un
//  booleano. La PDP es la página más visitada de la tienda.
//
//  El caso dominante es además el más barato de cachear: un usuario navega
//  muchos productos que NO ha comprado, y para todos ellos la respuesta es la
//  misma (`not_purchased`) hasta que compre.
//
//  Fail-open, como el resto de usos de Redis: sin almacén se recalcula. Nunca
//  es una barrera de autorización — el POST vuelve a comprobar la compra
//  contra WooCommerce, así que un valor cacheado no puede autorizar nada.
// ============================================================================

/**
 * Estado por-usuario respecto a la reseña de un producto. `not_received`: solo
 * hay un pedido «Procesando» con él; la ficha ofrece «Recibí mi producto».
 */
export type ReviewEligibilityReason =
  "auth" | "not_purchased" | "not_received" | "already_reviewed" | null;

/** El pedido del que sale la reseña (o el que falta marcar como recibido). */
export interface ReviewOrderRef {
  id: number;
  number: string;
  /**
   * Con `canReview`: cuántos OTROS productos de ese pedido completado aún no
   * reseñó (el check «misma reseña para los productos del pedido»).
   */
  otherProducts?: number;
}

export interface ReviewEligibility {
  canReview: boolean;
  reason: ReviewEligibilityReason;
  reviewerName?: string;
  order?: ReviewOrderRef;
}

// `v2`: desde que reseñar exige un pedido COMPLETADO, una entrada vieja podía
// decir `canReview` a quien solo tiene uno en preparación.
const PREFIX = "review-elig:v2:";

/**
 * Ventana corta a propósito. El precio de cachear es que, tras comprar, el
 * formulario puede tardar hasta este tiempo en aparecer; el POST invalida la
 * entrada, así que el caso que sí importa —publicar y que el form desaparezca—
 * es inmediato.
 */
const TTL_SECONDS = 180;

function key(userId: string | number, productId: number): string {
  return `${PREFIX}${userId}:${productId}`;
}

export async function readCachedEligibility(
  userId: string | number,
  productId: number,
): Promise<ReviewEligibility | null> {
  const redis = getRedis();
  if (!redis) return null;
  try {
    const raw = await redis.get(key(userId, productId));
    return raw ? (JSON.parse(raw) as ReviewEligibility) : null;
  } catch (error) {
    // Sin caché: se recalcula.
    logRedisFailure("review_eligibility", "read", error, { effect: "se recalcula" });
    return null;
  }
}

export async function writeCachedEligibility(
  userId: string | number,
  productId: number,
  value: ReviewEligibility,
): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.set(key(userId, productId), JSON.stringify(value), "EX", TTL_SECONDS);
  } catch (error) {
    logRedisFailure("review_eligibility", "write", error, { effect: "sin caché" });
  }
}

/** Tras publicar una reseña: la entrada cacheada diría todavía `canReview`. */
export async function invalidateCachedEligibility(
  userId: string | number,
  productId: number,
): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.del(key(userId, productId));
  } catch (error) {
    logRedisFailure("review_eligibility", "invalidate", error, {
      effect: "la caché puede mostrar la elegibilidad anterior hasta que caduque",
    });
  }
}
