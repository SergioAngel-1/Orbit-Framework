import { useTranslations } from "next-intl";
import { isKnownOrderStatus, orderStatusClasses } from "@/lib/account/order-status";

/** Estado de un pedido, traducido (`account.orderStatus.*`) y con su color. */
export function OrderStatusBadge({ status }: { status: string }) {
  const t = useTranslations("account.orderStatus");
  return (
    <span
      className={`inline-block rounded-full border px-2.5 py-0.5 text-xs font-medium ${orderStatusClasses(status)}`}
    >
      {isKnownOrderStatus(status) ? t(status) : status}
    </span>
  );
}
