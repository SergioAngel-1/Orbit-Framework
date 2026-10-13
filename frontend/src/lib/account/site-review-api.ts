import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import type { SiteReview } from "./order-review";
import { logReadFailure } from "@/lib/observability/read-failure";

// ============================================================================
//  Llamada server-to-server al plugin `hwe-site-reviews` (módulo
//  `site-reviews`), con el secreto interno (`wpFetch`). Las reglas —el pedido
//  es de ese usuario, admite reseña y no tiene ya una— las vuelve a comprobar
//  WordPress; aquí solo se traducen sus respuestas.
// ============================================================================

export type CreateSiteReviewResult =
  | { ok: true; review: SiteReview }
  | {
      ok: false;
      reason:
        "already_reviewed" | "not_found" | "not_rateable" | "invalid" | "unavailable";
    };

export async function createSiteReview(input: {
  orderId: number;
  userId: number;
  rating: number;
  content: string;
}): Promise<CreateSiteReviewResult> {
  let res: Response;
  try {
    res = await wpFetch("/wp-json/hwe/v1/site-reviews", {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        order_id: input.orderId,
        user_id: input.userId,
        rating: input.rating,
        content: input.content,
      }),
    });
  } catch (error) {
    logReadFailure("site_reviews.create_unavailable", error, {
      message: "WordPress no respondió al guardar la calificación del pedido",
      context: { orderId: input.orderId },
    });
    return { ok: false, reason: "unavailable" };
  }

  const data = (await res.json().catch(() => ({}))) as {
    error?: string;
    review?: { rating?: number; content?: string; date?: string };
  };
  if (res.status === 201 && data.review) {
    return {
      ok: true,
      review: {
        rating: Number(data.review.rating),
        content: String(data.review.content ?? ""),
        date: String(data.review.date ?? ""),
      },
    };
  }
  if (res.status === 409) return { ok: false, reason: "already_reviewed" };
  if (res.status === 404) return { ok: false, reason: "not_found" };
  if (data.error === "order_not_rateable") return { ok: false, reason: "not_rateable" };
  if (res.status === 422 || res.status === 400) return { ok: false, reason: "invalid" };
  return { ok: false, reason: "unavailable" };
}
