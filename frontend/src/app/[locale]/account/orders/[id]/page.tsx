import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getCustomer, getOrderById } from "@/lib/account/data";
import { formatDate, formatPrice } from "@/lib/format";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { isOrderPayable } from "@/lib/account/order-payable";
import { orderReviewState } from "@/lib/account/order-review";
import { reviewedProductsForOrders } from "@/lib/reviews/publish-product-reviews";
import {
  enabledPaymentChoices,
  ONLINE_PAYMENT_METHOD,
} from "@/lib/payments/checkout-options";
import { OrderActions } from "@/components/account/order-actions";
import { OrderStatusBadge } from "@/components/account/order-status-badge";
import { OrderSupportLink } from "@/components/account/order-support-link";
import { PayOrderButton } from "@/components/checkout/pay-order-button";

export const dynamic = "force-dynamic";

export default async function OrderDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;
  const t = await getTranslations("account");

  let order: Awaited<ReturnType<typeof getOrderById>>;
  try {
    order = await getOrderById(Number(id));
  } catch {
    notFound();
  }

  const config = await getSiteConfig();
  const siteReviews = isModuleEnabled(config, "site-reviews");
  const productReviews = config.ecommerce.reviews_enabled;
  // Los productos que el cliente ya reseñó solo importan si algo los ofrece.
  const reviewed =
    siteReviews || productReviews
      ? await reviewedProductsForOrders(await getCustomer().catch(() => null), [order])
      : new Set<number>();
  const state = orderReviewState(order, reviewed);
  // «Calificar» es del módulo `site-reviews`; «Recibí mi pedido» también sirve
  // para habilitar la reseña de producto, así que basta con cualquiera de los dos.
  const reviewMode =
    state.mode === "rate"
      ? siteReviews
        ? "rate"
        : null
      : state.mode === "receive" && (siteReviews || productReviews)
        ? "receive"
        : null;
  const payable =
    isOrderPayable(order.status) &&
    order.payment_method === ONLINE_PAYMENT_METHOD &&
    enabledPaymentChoices(config).includes("online");

  return (
    <div>
      <Link
        href="/account/orders"
        className="mb-4 inline-block text-sm text-brand transition-colors hover:text-brand-dark"
      >
        {t("backToOrders")}
      </Link>

      <h1 className="mb-6 text-2xl font-bold">
        {t("orderDetail").replace("%s", order.number)}
      </h1>

      {/* Cabecera */}
      <div className="mb-6 flex flex-wrap gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
        <div className="flex-1">
          <p className="text-xs text-gray-500">{t("created")}</p>
          <p className="font-medium">{formatDate(order.date_created, locale)}</p>
        </div>
        <div className="flex-1">
          <p className="text-xs text-gray-500">{t("status")}</p>
          <OrderStatusBadge status={order.status} />
        </div>
        <div className="flex-1">
          <p className="text-xs text-gray-500">{t("total")}</p>
          <p className="font-semibold">
            {order.total} {order.currency}
          </p>
        </div>
      </div>

      {/* Acciones */}
      <section className="mb-6 space-y-3">
        {payable && <PayOrderButton orderId={order.id} />}
        <OrderActions
          orderId={order.id}
          reorder
          review={
            reviewMode
              ? {
                  mode: reviewMode,
                  productCount: productReviews ? state.productCount : 0,
                }
              : null
          }
        />
        <OrderSupportLink order={order} />
      </section>

      {/* Calificación del pedido (módulo site-reviews) */}
      {siteReviews && state.review && (
        <section className="mb-6 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h2 className="mb-1 text-sm font-bold">{t("orderReview.title")}</h2>
          <p className="text-yellow-500" aria-label={`${state.review.rating} / 5`}>
            {"★".repeat(state.review.rating)}
          </p>
          {state.review.content && (
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
              {state.review.content}
            </p>
          )}
        </section>
      )}

      {/* Artículos */}
      <section className="mb-6">
        <h2 className="mb-3 text-lg font-bold">{t("items")}</h2>
        <div className="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
          {order.line_items.map((item) => (
            <div key={item.id} className="flex items-center gap-4 p-4">
              {item.image?.src && (
                <Image
                  src={item.image.src}
                  alt={item.name}
                  width={56}
                  height={56}
                  className="h-14 w-14 rounded-lg object-cover"
                />
              )}
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{item.name}</p>
                <p className="text-xs text-gray-500">
                  {t("quantity")}: {item.quantity}
                </p>
              </div>
              <p className="text-sm font-semibold">
                {formatPrice(String(item.price), order.currency, locale)}
              </p>
            </div>
          ))}
        </div>
      </section>

      {/* Direcciones */}
      <div className="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
        <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h2 className="mb-2 text-sm font-bold">{t("shippingAddress")}</h2>
          <p className="text-sm text-gray-600 dark:text-gray-400">
            {order.shipping.first_name} {order.shipping.last_name}
            <br />
            {order.shipping.address_1}
            <br />
            {order.shipping.city}, {order.shipping.postcode}
            <br />
            {order.shipping.country}
          </p>
        </section>
        <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h2 className="mb-2 text-sm font-bold">{t("billingAddress")}</h2>
          <p className="text-sm text-gray-600 dark:text-gray-400">
            {order.billing.first_name} {order.billing.last_name}
            <br />
            {order.billing.address_1}
            <br />
            {order.billing.city}, {order.billing.postcode}
            <br />
            {order.billing.country}
            <br />
            {order.billing.email}
          </p>
        </section>
      </div>
    </div>
  );
}
