import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { Link, redirect } from "@/i18n/navigation";
import { getSession } from "@/lib/auth/session";
import { canAccessOrder } from "@/lib/checkout/order-access";
import { readGuestOrderCredential } from "@/lib/checkout/guest-order-cookie";
import { getOrder, isOrderPaid } from "@/lib/payments/orders";
import {
  ONLINE_PAYMENT_METHOD,
  paymentChoiceFor,
} from "@/lib/payments/checkout-options";
import { formatPrice } from "@/lib/format";
import { PurchaseTracker } from "@/components/analytics/purchase-tracker";
import { purchaseParams } from "@/lib/analytics/ecommerce";
import { countsAsPurchase } from "@/lib/analytics/purchase";
import { PaymentStatusWatcher } from "@/components/checkout/payment-status-watcher";
import { GuestAccountInvite } from "@/components/checkout/guest-account-invite";
import { PayOrderButton } from "@/components/checkout/pay-order-button";

export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "checkout" });
  return { title: t("return.title"), robots: { index: false, follow: false } };
}

/**
 * «Gracias» tras el checkout. Muestra el estado del pedido para la UX. NO
 * confirma el pago: la confirmación real llega por webhook firmado; si aún no
 * ha llegado, el pedido se ve «pendiente» y `PaymentStatusWatcher` repinta la
 * página cuando cambia (la pasarela devuelve al comprador a la vez que manda su
 * webhook, sin orden garantizado).
 *
 * Lo ve quien creó el pedido: el cliente con sesión o el invitado con la
 * credencial (`order_key`) de su cookie (`canAccessOrder`).
 */
export default async function CheckoutReturnPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ ref?: string; retry?: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const t = await getTranslations("checkout");

  const [session, guest] = await Promise.all([
    getSession(),
    readGuestOrderCredential(),
  ]);
  if (!session && !guest) {
    redirect({ href: "/login", locale });
  }

  const { ref, retry } = await searchParams;

  let paid = false;
  let status: string | null = null;
  let total: string | null = null;
  let currency = "";
  let method = "";
  let lineItems: Awaited<ReturnType<typeof getOrder>>["line_items"] = [];
  let measured = false;
  let notFound = false;

  if (ref && /^\d+$/.test(ref)) {
    try {
      const order = await getOrder(ref);
      if (canAccessOrder(order, { userId: session?.userId, guest })) {
        paid = isOrderPaid(order);
        status = order.status;
        total = order.total;
        currency = order.currency;
        method = order.payment_method;
        lineItems = order.line_items ?? [];
        // Solo un pedido PAGADO EN LÍNEA cuenta como compra para la medición:
        // el efectivo y el link por WhatsApp no han cobrado nada.
        measured = countsAsPurchase(order);
      } else {
        notFound = true;
      }
    } catch {
      notFound = true;
    }
  } else {
    notFound = true;
  }

  const purchaseTrackerParams = measured
    ? purchaseParams(ref!, total, currency, lineItems)
    : null;
  const choice = paymentChoiceFor(method);
  // Solo el pago en línea espera a la pasarela: el efectivo y el link por
  // WhatsApp no la tienen detrás.
  const awaitingGateway = !paid && method === ONLINE_PAYMENT_METHOD;

  return (
    <div className="mx-auto max-w-xl space-y-6 text-center">
      <h1 className="text-3xl font-extrabold tracking-tight">{t("return.title")}</h1>

      {notFound ? (
        <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {t("return.notFound")}
        </p>
      ) : paid ? (
        <div className="rounded-xl border border-green-200 bg-green-50 p-8 dark:border-green-900 dark:bg-green-950">
          {measured && purchaseTrackerParams && (
            <PurchaseTracker params={purchaseTrackerParams} />
          )}
          <h2 className="text-xl font-bold text-green-800 dark:text-green-200">
            {t("return.paid")}
          </h2>
          <p className="mt-2 text-green-700 dark:text-green-300">
            {t("orderNumber")} <strong>#{ref}</strong>
            {/* `order.total` (WC REST) va en unidades mayores, no en céntimos. */}
            {total ? ` · ${formatPrice(total, currency, locale)}` : ""}
          </p>
        </div>
      ) : awaitingGateway ? (
        <div className="rounded-xl border border-blue-200 bg-blue-50 p-8 dark:border-blue-900 dark:bg-blue-950">
          <PaymentStatusWatcher orderId={ref!} status={status ?? ""} />
          <h2 className="text-xl font-bold text-blue-800 dark:text-blue-200">
            {t("return.pending")}
          </h2>
          <p className="mt-2 text-blue-700 dark:text-blue-300">
            {t("orderNumber")} <strong>#{ref}</strong> · {t("status")}: {status}
          </p>
          <p className="mt-3 text-sm text-blue-600 dark:text-blue-400">
            {t("return.pendingHint")}
          </p>
          {retry === "1" && (
            <p
              role="alert"
              className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
              {t("paymentNotStarted")}
            </p>
          )}
          <div className="mt-5 flex justify-center">
            <PayOrderButton orderId={Number(ref)} />
          </div>
        </div>
      ) : (
        <div className="rounded-xl border border-green-200 bg-green-50 p-8 dark:border-green-900 dark:bg-green-950">
          <h2 className="text-xl font-bold text-green-800 dark:text-green-200">
            {t("return.received")}
          </h2>
          <p className="mt-2 text-green-700 dark:text-green-300">
            {t("orderNumber")} <strong>#{ref}</strong>
          </p>
          {choice === "whatsapp" && (
            <p className="mt-3 text-sm text-green-700 dark:text-green-300">
              {t("resultWhatsapp")}
            </p>
          )}
          {choice === "cash" && (
            <p className="mt-3 text-sm text-green-700 dark:text-green-300">
              {t("resultCash")}
            </p>
          )}
        </div>
      )}

      {!notFound && !session && (
        <GuestAccountInvite
          copy={{
            title: t("return.guestInviteTitle"),
            body: t("return.guestInviteBody"),
            login: t("return.guestInviteLogin"),
            register: t("return.guestInviteRegister"),
          }}
        />
      )}

      <div className="flex justify-center gap-4 text-sm">
        {session && (
          <Link
            href="/account/orders"
            className="font-medium text-brand dark:text-accent hover:underline"
          >
            {t("return.viewOrders")}
          </Link>
        )}
        <Link
          href="/products"
          className="font-medium text-brand dark:text-accent hover:underline"
        >
          {t("return.continueShopping")}
        </Link>
      </div>
    </div>
  );
}
