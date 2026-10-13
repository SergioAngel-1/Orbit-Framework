import { NextResponse } from "next/server";
import { getSiteConfig } from "@/lib/config";
import { requireFlag } from "@/lib/api/flags";
import { revalidateTag } from "next/cache";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSession } from "@/lib/auth/session";
import { findProductPurchase } from "@/lib/account/data";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { productReviewSchema } from "@/lib/validation/store";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";
import {
  readCachedEligibility,
  writeCachedEligibility,
  invalidateCachedEligibility,
  type ReviewEligibility,
} from "@/lib/account/review-eligibility";
import { logger } from "@/lib/observability/logger";
import { resolveReviewerName } from "@/lib/reviews/reviewer-name";
import {
  invalidateOrderEligibility,
  publishProductReviews,
  reviewedProductIds,
} from "@/lib/reviews/publish-product-reviews";
import type { WooCustomer, WooProductReview } from "@/types/woocommerce";

export type { ReviewEligibilityReason } from "@/lib/account/review-eligibility";

export const dynamic = "force-dynamic";

/**
 * GET /api/store/reviews/[productId]
 * Elegibilidad para reseñar (estado por-usuario). Se consulta desde el cliente
 * para no forzar render dinámico en la ficha de producto (ISR). Nunca 401: sin
 * sesión responde `canReview:false` con `reason:"auth"` para que la UI muestre
 * el prompt de login.
 *
 * `reason`: "auth" (sin sesión) · "not_purchased" (sin compra) ·
 * "not_received" (solo un pedido «Procesando» con el producto: la ficha ofrece
 * «Recibí mi producto», que lo completa; viene con `order`) ·
 * "already_reviewed" (ya dejó una reseña — evita que el form reaparezca tras
 * recargar) · null (puede reseñar: viene con `order`, el pedido completado del
 * que sale, y cuántos otros productos suyos aún no reseñó).
 */
export async function GET(
  request: Request,
  { params }: { params: Promise<{ productId: string }> },
) {
  const disabled = requireFlag(await getSiteConfig(), "reviews_enabled");
  if (disabled) return disabled;
  const { productId } = await params;
  const pid = Number(productId);
  if (!Number.isInteger(pid) || pid <= 0) {
    return NextResponse.json({ canReview: false, reason: null }, { status: 400 });
  }
  const session = await getSession();
  if (!session) {
    return NextResponse.json({ canReview: false, reason: "auth" });
  }

  // Caché por (usuario, producto): `ReviewGate` se monta en TODA ficha, así que
  // sin esto cada vista de PDP de un usuario con sesión costaba tres llamadas a
  // la REST de administración de WooCommerce para resolver un booleano.
  const cached = await readCachedEligibility(session.userId, pid);
  if (cached) {
    return NextResponse.json(cached);
  }

  // Rate-limit del cálculo (no del acierto de caché): este GET dispara trabajo
  // en WordPress y no pasa por `guardMutation` (es una lectura). Se acota por
  // usuario, no por IP, para no castigar a compradores tras CGNAT — mismo
  // criterio que el `extraId` del POST.
  const rl = await rateLimit(
    `review_elig:${session.userId}:${getClientIp(request)}`,
    60,
    60,
  );
  if (!rl.success) {
    logger.warn(
      { event: "reviews.eligibility.rate_limited", userId: session.userId },
      "Rate-limit en la comprobación de elegibilidad de reseña",
    );
    return NextResponse.json(
      { canReview: false, reason: null },
      { status: 429, headers: { "Retry-After": String(rl.retryAfter) } },
    );
  }

  const eligibility = await resolveEligibility(session.userId, pid);
  await writeCachedEligibility(session.userId, pid, eligibility);
  return NextResponse.json(eligibility);
}

/**
 * Cálculo real de la elegibilidad: los pedidos del cliente con el producto
 * (`findProductPurchase`), su perfil y sus reseñas de ese pedido (una consulta
 * para todos sus productos).
 */
async function resolveEligibility(
  userId: string,
  productId: number,
): Promise<ReviewEligibility> {
  const purchase = await findProductPurchase(productId);
  const order = purchase.completed ?? purchase.processing;
  if (!order) {
    return { canReview: false, reason: "not_purchased" };
  }

  const customer = await wcFetch<WooCustomer>(`/customers/${Number(userId)}`);
  const others = (purchase.completed?.productIds ?? []).filter(
    (id) => id !== productId,
  );
  const reviewed = await reviewedProductIds(customer, [productId, ...others]);
  if (reviewed.has(productId)) {
    return { canReview: false, reason: "already_reviewed" };
  }
  if (!purchase.completed) {
    return {
      canReview: false,
      reason: "not_received",
      order: { id: order.id, number: order.number },
    };
  }

  return {
    canReview: true,
    reason: null,
    reviewerName: resolveReviewerName(customer),
    order: {
      id: purchase.completed.id,
      number: purchase.completed.number,
      otherProducts: others.filter((id) => !reviewed.has(id)).length,
    },
  };
}

/**
 * POST /api/store/reviews/[productId]
 * Crea una reseña de producto autenticada.
 *
 * La identidad del autor (`reviewer` y `reviewer_email`) sale SIEMPRE del perfil
 * de la sesión, nunca del cuerpo: si no, una petición directa podría publicar una
 * reseña «verificada» atribuida a un tercero. El `reviewer_email` no se expone
 * públicamente en WooCommerce.
 *
 * Solo pueden reseñar usuarios con un pedido COMPLETADO que contenga el
 * producto (uno «Procesando» responde 409 `not_received`: primero «Recibí mi
 * producto»). La reseña se crea con status "approved" y `verified: true`: la
 * compra verificada actúa como filtro anti-spam, así que no se pasa por
 * moderación (decisión de producto; una instancia que quiera moderar cambia
 * `status` a "hold" aquí y en `publish-product-reviews.ts`). Con `sameForOrder`, la misma reseña se
 * publica en los demás productos de ese pedido aún sin reseñar
 * (`othersReviewed` en la respuesta); si ese paso falla, la principal ya quedó.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ productId: string }> },
) {
  const disabled = requireFlag(await getSiteConfig(), "reviews_enabled");
  if (disabled) return disabled;
  // Se resuelve la sesión ANTES del guard (no lanza, a diferencia de
  // requireSession) para poder acotar el rate-limit también por usuario vía
  // `extraId` — si no, "review:${ip}" se comparte entre TODOS los usuarios
  // detrás de la misma IP (oficina, wifi compartido, CGNAT), y unos pocos
  // compradores legítimos bastan para bloquear a sus vecinos de red.
  const session = await getSession();
  const blocked = await guardMutation(request, {
    rateLimit: {
      name: "review",
      limit: 5,
      windowSeconds: 3600,
      extraId: session ? String(session.userId) : undefined,
    },
  });
  if (blocked) return blocked;

  if (!session) {
    logger.warn(
      { event: "reviews.create.unauthorized" },
      "Intento de reseña sin autenticación",
    );
    return NextResponse.json(
      { error: "Debes iniciar sesión para dejar una reseña." },
      { status: 401 },
    );
  }

  const { productId } = await params;
  const pid = Number(productId);
  if (!Number.isInteger(pid) || pid <= 0) {
    logger.warn(
      { event: "reviews.create.invalid_product" },
      "ID de producto inválido para reseña",
    );
    return NextResponse.json({ error: "ID de producto inválido." }, { status: 400 });
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = productReviewSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn({ event: "reviews.create.validation" }, "Datos de reseña inválidos");
    return NextResponse.json(
      {
        error: "Datos de reseña inválidos.",
        details: parsed.error.flatten().fieldErrors,
      },
      { status: 422 },
    );
  }

  // Solo compradores con el pedido ya recibido (completado).
  const purchase = await findProductPurchase(pid);
  if (!purchase.completed) {
    logger.warn(
      {
        event: "reviews.create.not_purchaser",
        productId: pid,
        processing: Boolean(purchase.processing),
      },
      "Reseña rechazada: sin un pedido completado con el producto",
    );
    return purchase.processing
      ? NextResponse.json(
          {
            error: "Confirma primero que recibiste tu pedido.",
            reason: "not_received",
          },
          { status: 409 },
        )
      : NextResponse.json(
          { error: "Solo puedes reseñar productos que has comprado." },
          { status: 403 },
        );
  }

  try {
    // Identidad fija del perfil autenticado (anti-suplantación): igual que
    // /api/contact, el nombre/email de la reseña NUNCA viene del body — si no,
    // un comprador podría publicar una reseña "verificada" y aprobada
    // automáticamente atribuida al nombre/email de un tercero.
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
    const reviewerName = resolveReviewerName(customer);

    // Una reseña por cliente y producto. El formulario no sale si ya reseñó,
    // pero otra pestaña (o una petición a mano) podía crear una segunda:
    // WooCommerce solo rechaza un texto IDÉNTICO (`comment_duplicate`).
    if ((await reviewedProductIds(customer, [pid])).has(pid)) {
      await invalidateCachedEligibility(session.userId, pid);
      return NextResponse.json(
        { error: "Ya dejaste tu reseña de este producto.", reason: "already_reviewed" },
        { status: 409 },
      );
    }

    const review = await wcFetch<WooProductReview>("/products/reviews", {
      method: "POST",
      body: {
        product_id: pid,
        review: parsed.data.content,
        reviewer: reviewerName,
        reviewer_email: customer.email,
        rating: parsed.data.rating,
        status: "approved",
        verified: true,
      },
    });
    logger.info(
      { event: "reviews.create.success", productId: pid, reviewId: review.id },
      "Reseña creada correctamente",
    );
    // La ficha de producto tiene ISR (revalidate=300, ver page.tsx): sin esto
    // la reseña recién aprobada quedaría invisible hasta 5 min después.
    revalidateTag("products", "max");
    // La entrada cacheada seguiría diciendo `canReview: true` y el formulario
    // reaparecería al recargar; la de los demás productos del pedido contaría
    // este como pendiente en su check «misma reseña para el pedido».
    await invalidateOrderEligibility(session.userId, purchase.completed.productIds);

    let othersReviewed = 0;
    if (parsed.data.sameForOrder) {
      try {
        othersReviewed = await publishProductReviews({
          userId: Number(session.userId),
          customer,
          productIds: purchase.completed.productIds.filter((id) => id !== pid),
          rating: parsed.data.rating,
          content: parsed.data.content,
        });
      } catch (error) {
        logger.error(
          {
            event: "reviews.create.order_products_error",
            err: error instanceof Error ? error.message : error,
            orderId: purchase.completed.id,
          },
          "La reseña se guardó, pero no la de los demás productos del pedido",
        );
      }
    }
    return NextResponse.json(
      { id: review.id, status: review.status, othersReviewed },
      { status: 201 },
    );
  } catch (error) {
    logger.error(
      {
        event: "reviews.create.error",
        err: error instanceof Error ? error.message : error,
        productId: pid,
      },
      "Error al crear reseña",
    );
    return handleApiError(error);
  }
}
