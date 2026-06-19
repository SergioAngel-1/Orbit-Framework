import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { Link, redirect } from "@/i18n/navigation";
import { getSession } from "@/lib/auth/session";
import { getOrder, isOrderPaid } from "@/lib/payments/orders";
import { formatStoreAmount } from "@/lib/format";
import { PurchaseTracker } from "@/components/analytics/purchase-tracker";

export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "checkout" });
  return { title: t("return.title") };
}

/**
 * Página de retorno tras el checkout alojado. Muestra el estado del pedido para
 * la UX. NO confirma el pago: la confirmación real llega por webhook firmado;
 * si aún no ha llegado, el pedido se ve "pendiente" y se invita a refrescar.
 */
export default async function CheckoutReturnPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ ref?: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const t = await getTranslations("checkout");

  const session = await getSession();
  if (!session) {
    redirect({ href: "/login", locale });
  }

  const { ref } = await searchParams;

  let paid = false;
  let status: string | null = null;
  let total: string | null = null;
  let currency = "";
  let notFound = false;

  if (ref && /^\d+$/.test(ref)) {
    try {
      const order = await getOrder(ref);
      if (order.customer_id === Number(session!.userId)) {
        paid = isOrderPaid(order);
        status = order.status;
        total = order.total;
        currency = order.currency;
      } else {
        notFound = true;
      }
    } catch {
      notFound = true;
    }
  } else {
    notFound = true;
  }

  return (
    <div className="mx-auto max-w-xl text-center">
      <h1 className="mb-6 text-3xl font-extrabold tracking-tight">
        {t("return.title")}
      </h1>

      {notFound ? (
        <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {t("return.notFound")}
        </p>
      ) : paid ? (
        <div className="rounded-xl border border-green-200 bg-green-50 p-8 dark:border-green-900 dark:bg-green-950">
          <PurchaseTracker orderId={ref!} amount={Number(total)} currency={currency} />
          <h2 className="text-xl font-bold text-green-800 dark:text-green-200">
            {t("return.paid")}
          </h2>
          <p className="mt-2 text-green-700 dark:text-green-300">
            {t("orderNumber")} <strong>#{ref}</strong>
            {total ? ` · ${formatStoreAmount(total, 2, currency)}` : ""}
          </p>
        </div>
      ) : (
        <div className="rounded-xl border border-blue-200 bg-blue-50 p-8 dark:border-blue-900 dark:bg-blue-950">
          <h2 className="text-xl font-bold text-blue-800 dark:text-blue-200">
            {t("return.pending")}
          </h2>
          <p className="mt-2 text-blue-700 dark:text-blue-300">
            {t("orderNumber")} <strong>#{ref}</strong> · {t("status")}: {status}
          </p>
          <p className="mt-3 text-sm text-blue-600 dark:text-blue-400">
            {t("return.pendingHint")}
          </p>
        </div>
      )}

      <div className="mt-8 flex justify-center gap-4 text-sm">
        <Link href="/account/orders" className="font-medium text-brand hover:underline">
          {t("return.viewOrders")}
        </Link>
        <Link href="/products" className="font-medium text-brand hover:underline">
          {t("return.continueShopping")}
        </Link>
      </div>
    </div>
  );
}
