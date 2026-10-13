import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { requireModule } from "@/lib/api/modules";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { orderReviewSchema } from "@/lib/validation/store";
import { canRateOrder, reviewableProductIds } from "@/lib/account/order-review";
import { createSiteReview } from "@/lib/account/site-review-api";
import { publishProductReviews } from "@/lib/reviews/publish-product-reviews";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer, WooOrder } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

const ERRORS = {
  already_reviewed: { status: 409, error: "Ya calificaste este pedido." },
  not_found: { status: 404, error: "No encontrado." },
  not_rateable: { status: 422, error: "Este pedido no se puede calificar." },
  invalid: { status: 422, error: "Datos de reseña inválidos." },
  unavailable: { status: 502, error: "No pudimos guardar tu calificación." },
} as const;

/**
 * POST /api/store/orders/[id]/review
 * Califica un pedido: crea la reseña del SITIO (plugin `hwe-site-reviews`) y, con
 * `products`, publica la misma nota y el mismo texto en cada producto del
 * pedido que el cliente aún no haya reseñado. Solo pedidos «Completado»
 * (`canRateOrder`), la misma regla para las dos reseñas: uno «Procesando» lo
 * completa antes el cliente con «Recibí mi pedido» (`orders/[id]/received`).
 *
 * Solo el dueño del pedido con sesión: el invitado ve en «gracias» una
 * invitación a iniciar sesión, no el formulario. Las reseñas de producto se
 * crean como las de la ficha (aprobadas, `verified`, nombre del perfil, nunca
 * del cuerpo) y en un solo lote. Si ese paso falla, la del sitio ya quedó
 * guardada: se responde 201 igualmente, con `productsReviewed: 0`.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  // Con el módulo `site-reviews` apagado, la ruta no existe.
  const disabled = requireModule(await getSiteConfig(), "site-reviews");
  if (disabled) return disabled;

  const session = await getSession();
  const blocked = await guardMutation(request, {
    rateLimit: {
      name: "order_review",
      limit: 10,
      windowSeconds: 3600,
      extraId: session ? String(session.userId) : undefined,
    },
  });
  if (blocked) return blocked;
  if (!session) {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  const { id } = await params;
  const orderId = Number(id);
  if (!Number.isInteger(orderId) || orderId <= 0) {
    return NextResponse.json({ error: "Id de pedido inválido." }, { status: 400 });
  }

  const parsed = orderReviewSchema.safeParse(await request.json().catch(() => null));
  if (!parsed.success) {
    return NextResponse.json(
      { error: ERRORS.invalid.error, details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }
  const { rating, content, products } = parsed.data;
  const userId = Number(session.userId);

  try {
    const order = await wcFetch<WooOrder>(`/orders/${orderId}`);
    if (order.customer_id !== userId) {
      logger.warn(
        { event: "orders.review.idor", userId, orderId },
        "Intento de calificar un pedido de otro usuario",
      );
      return NextResponse.json(ERRORS.not_found, { status: 404 });
    }
    if (!canRateOrder(order.status)) {
      return NextResponse.json(ERRORS.not_rateable, { status: 422 });
    }

    const created = await createSiteReview({ orderId, userId, rating, content });
    if (!created.ok) {
      const { status, error } = ERRORS[created.reason];
      logger.warn(
        { event: "orders.review.rejected", orderId, reason: created.reason },
        "Calificación de pedido rechazada",
      );
      return NextResponse.json({ error, reason: created.reason }, { status });
    }

    let productsReviewed = 0;
    if (products) {
      productsReviewed = await reviewOrderProducts(order, userId, rating, content);
    }

    logger.info(
      { event: "orders.review.success", orderId, rating, productsReviewed },
      "Pedido calificado",
    );
    return NextResponse.json(
      { review: created.review, productsReviewed },
      { status: 201 },
    );
  } catch (error) {
    logger.error(
      {
        event: "orders.review.error",
        err: error instanceof Error ? error.message : error,
        orderId,
      },
      "Error al calificar el pedido",
    );
    return handleApiError(error);
  }
}

/**
 * Publica la reseña en los productos del pedido que el cliente aún no reseñó.
 * Devuelve cuántas se crearon. Nunca lanza: la reseña del sitio ya existe.
 */
async function reviewOrderProducts(
  order: WooOrder,
  userId: number,
  rating: number,
  content: string,
): Promise<number> {
  const productIds = reviewableProductIds(order.line_items ?? []);
  if (productIds.length === 0) return 0;
  try {
    const customer = await wcFetch<WooCustomer>(`/customers/${userId}`);
    return await publishProductReviews({
      userId,
      customer,
      productIds,
      rating,
      content,
    });
  } catch (error) {
    logger.error(
      {
        event: "orders.review.products_error",
        err: error instanceof Error ? error.message : error,
        orderId: order.id,
      },
      "La reseña del pedido se guardó, pero no la de sus productos",
    );
    return 0;
  }
}
