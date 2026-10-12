import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { createReviewSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer, WooProductReview } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * POST /api/store/reviews/[productId]
 * Crea una reseña de producto autenticada.
 *
 * La identidad del autor (`reviewer` y `reviewer_email`) sale SIEMPRE del perfil
 * WooCommerce del usuario autenticado, nunca del cuerpo: si no, cualquier petición
 * directa podría publicar una reseña atribuida al nombre o al email de un tercero.
 * Un `name`/`email` enviado se ignora. El campo `reviewer_email` no se expone
 * públicamente en WooCommerce.
 *
 * Por defecto se crean con status "hold" (pendiente de moderación). Cambia a
 * "approved" si confías en los usuarios autenticados.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ productId: string }> },
) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "review", limit: 5, windowSeconds: 3600 },
  });
  if (blocked) return blocked;

  // Requerir sesión para crear reseñas (evita spam).
  let session;
  try {
    session = await requireSession();
  } catch {
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

  const parsed = createReviewSchema.safeParse(body);
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

  try {
    // Identidad fija del perfil autenticado (anti-suplantación).
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
    const reviewerName =
      [customer.first_name, customer.last_name].filter(Boolean).join(" ").trim() ||
      "Cliente";

    const review = await wcFetch<WooProductReview>("/products/reviews", {
      method: "POST",
      body: {
        product_id: pid,
        review: parsed.data.content,
        reviewer: reviewerName,
        reviewer_email: customer.email,
        rating: parsed.data.rating,
        status: "hold",
      },
    });
    logger.info(
      { event: "reviews.create.success", productId: pid, reviewId: review.id },
      "Reseña creada correctamente",
    );
    return NextResponse.json({ id: review.id, status: review.status }, { status: 201 });
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
