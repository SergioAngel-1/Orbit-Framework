"use client";
import { useTranslations } from "next-intl";
import { useFormatStoreAmount } from "@/components/ui/currency-context";
import { resolveFreeShippingNotice } from "@/lib/shipping/free-shipping";
import { cn } from "@/lib/utils";
import { FreeShippingCities } from "./free-shipping-cities";
import { FreeShippingScopeHelp } from "./free-shipping-scope-help";
import type { StoreCart } from "@/types/woocommerce";

/**
 * Aviso de «Envío gratis» del carrito (módulo `shipping`): lo que falta para el
 * mínimo (con barra de progreso), que ya se alcanzó a falta de la ciudad, o que el
 * pedido ya no paga envío. Lo comparten el cajón, la página de carrito y el checkout.
 *
 * No decide nada: pinta el estado que WordPress publica en el carrito
 * (`resolveFreeShippingNotice`). Sin regla activa —o con el módulo apagado, que no
 * publica nada— no pinta nada; si la ciudad de destino no la admite, solo dice en
 * qué ciudades aplica (sin barra: a ese pedido no se le promete nada).
 */
export function FreeShippingNotice({
  cart,
  className,
}: {
  cart: StoreCart | null | undefined;
  className?: string;
}) {
  const t = useTranslations("cart.freeShipping");
  const formatStoreAmount = useFormatStoreAmount();
  const notice = resolveFreeShippingNotice(cart);
  if (!cart || !notice) return null;

  if (notice.kind === "otherCities") {
    return <FreeShippingCities variant="other" className={className} />;
  }

  if (notice.kind === "reached") {
    return (
      <p
        role="status"
        className={cn(
          "flex items-center gap-2 text-sm font-semibold text-green-700 dark:text-green-400",
          className,
        )}
      >
        <TruckIcon />
        {t("reached")}
      </p>
    );
  }

  const { selectedProducts, selectedCities } = notice.hints;
  const percent = notice.kind === "remaining" ? Math.round(notice.progress * 100) : 100;

  return (
    <div role="status" className={cn("flex flex-col gap-1.5", className)}>
      <p className="flex items-center gap-2 text-sm font-semibold">
        <TruckIcon />
        {notice.kind === "remaining"
          ? t("remaining", {
              amount: formatStoreAmount(
                notice.remaining,
                cart.totals.currency_minor_unit,
                cart.totals.currency_code,
              ),
            })
          : t("pendingCity")}
      </p>
      <div
        role="progressbar"
        aria-label={t("progressLabel")}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
        className="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800"
      >
        <div
          className="h-full rounded-full bg-brand dark:bg-accent transition-[width] duration-500 ease-out motion-reduce:transition-none"
          style={{ width: `${percent}%` }}
        />
      </div>
      {/* `div`, no `p`: el «?» trae su panel (un bloque) como hermano. */}
      {(selectedProducts || selectedCities) && (
        <div className="text-xs text-gray-500">
          {selectedProducts && (
            <>
              {t("hintProducts")} <FreeShippingScopeHelp />
            </>
          )}
          {selectedProducts && selectedCities && " "}
          {selectedCities && <FreeShippingCities variant="hint" />}
        </div>
      )}
    </div>
  );
}

function TruckIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      className="size-4 shrink-0"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M2.5 6.5h11v10h-11zM13.5 10h4l3 3v3.5h-7z" />
      <circle cx="7" cy="17.5" r="1.8" />
      <circle cx="17" cy="17.5" r="1.8" />
    </svg>
  );
}
