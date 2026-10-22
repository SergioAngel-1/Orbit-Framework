"use client";
import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { reviewApi } from "@/lib/client/store-api";
import type { ReviewEligibility } from "@/lib/account/review-eligibility";
import { ReviewForm } from "./review-form";

/**
 * Qué se ofrece en la ficha para reseñar el producto, según la elegibilidad del
 * cliente (`GET /api/store/reviews/[productId]`). Se consulta desde el cliente
 * para no forzar render dinámico en la ficha (ISR). El servidor vuelve a
 * comprobar la compra al publicar: esto solo evita ofrecer un formulario que
 * rechazaría.
 */
export function ReviewGate({ productId }: { productId: number }) {
  const t = useTranslations("products");
  const [state, setState] = useState<ReviewEligibility | null>(null);
  const [confirming, setConfirming] = useState(false);

  const load = useCallback(async () => {
    setState(await reviewApi.eligibility(productId));
  }, [productId]);

  useEffect(() => {
    let cancelled = false;
    void reviewApi.eligibility(productId).then((result) => {
      if (!cancelled) setState(result);
    });
    return () => {
      cancelled = true;
    };
  }, [productId]);

  if (!state) return null;

  if (state.canReview) {
    return (
      <ReviewForm
        productId={productId}
        reviewerName={state.reviewerName}
        otherProducts={state.order?.otherProducts ?? 0}
      />
    );
  }

  switch (state.reason) {
    case "auth":
      return (
        <p className="text-sm text-gray-600 dark:text-gray-300">
          <Link
            href="/login"
            className="font-medium text-brand dark:text-accent hover:underline"
          >
            {t("reviewLogin")}
          </Link>
        </p>
      );
    case "not_purchased":
      return <p className="text-sm text-gray-500">{t("reviewNotPurchased")}</p>;
    case "not_received":
      return (
        <div className="space-y-2">
          <p className="text-sm text-gray-500">{t("reviewNotReceived")}</p>
          {state.order && (
            <button
              type="button"
              disabled={confirming}
              onClick={async () => {
                setConfirming(true);
                const ok = await reviewApi.confirmReceived(state.order!.id);
                if (ok) await load();
                setConfirming(false);
              }}
              className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
            >
              {t("reviewConfirmReceived")}
            </button>
          )}
        </div>
      );
    case "already_reviewed":
      return <p className="text-sm text-gray-500">{t("reviewAlready")}</p>;
    default:
      return null;
  }
}
