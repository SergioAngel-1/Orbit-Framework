import type { Metadata } from "next";
import { getTranslations, getLocale } from "next-intl/server";
import { getCustomerOrders } from "@/lib/account/data";
import { formatDate } from "@/lib/format";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("account");
  return { title: t("ordersTitle") };
}

export const dynamic = "force-dynamic";

export default async function OrdersPage() {
  const t = await getTranslations("account");
  const locale = await getLocale();
  let orders: Awaited<ReturnType<typeof getCustomerOrders>> = [];
  let error: string | null = null;

  try {
    orders = await getCustomerOrders();
  } catch (e) {
    error = e instanceof Error ? e.message : t("dataError");
  }

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold">{t("ordersTitle")}</h1>

      {error ? (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {error}
        </div>
      ) : orders.length === 0 ? (
        <p className="text-gray-500">{t("ordersEmpty")}</p>
      ) : (
        <ul className="divide-y divide-gray-200 dark:divide-gray-800">
          {orders.map((order) => (
            <li key={order.id} className="flex items-center justify-between py-4">
              <div>
                <p className="font-medium">
                  {t("orders")} #{order.id}
                </p>
                <p className="text-sm text-gray-500">
                  {formatDate(order.date_created, locale)} · {order.line_items.length}{" "}
                  {t("articles")}
                </p>
              </div>
              <div className="text-right">
                <span className="inline-block rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium capitalize text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                  {order.status}
                </span>
                <p className="mt-1 font-semibold">
                  {order.total} {order.currency}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
