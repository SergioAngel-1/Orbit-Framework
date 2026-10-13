import { getLocale, getTranslations } from "next-intl/server";
import { getSiteConfig } from "@/lib/config";
import { whatsappHref } from "@/lib/config/social";
import { formatDate, formatPrice } from "@/lib/format";
import { orderSupportAction } from "@/lib/account/order-support";
import { formatOrderItemsForMessage } from "@/lib/payments/checkout-options";
import type { WooOrder } from "@/types/woocommerce";

/**
 * Ayuda con un pedido por WhatsApp: «Solicitar cancelación» si está En espera y
 * «Tuve un problema con mi pedido» si está Completado (`orderSupportAction`).
 * Abre el chat del negocio (`social.whatsapp`) con el pedido ya escrito. Sin
 * número configurado no se pinta nada.
 */
export async function OrderSupportLink({ order }: { order: WooOrder }) {
  const action = orderSupportAction(order.status);
  if (!action) return null;

  const [t, locale, config] = await Promise.all([
    getTranslations("account.orderSupport"),
    getLocale(),
    getSiteConfig(),
  ]);
  const href = whatsappHref(
    config.social.whatsapp,
    t(`${action}.message`, {
      order: order.number || String(order.id),
      date: formatDate(order.date_created, locale),
      total: formatPrice(
        order.total,
        order.currency || config.ecommerce.currency,
        locale,
      ),
      items: formatOrderItemsForMessage(
        (order.line_items ?? []).map((item) => ({
          name: item.name,
          quantity: item.quantity,
        })),
      ),
    }),
  );
  if (!href) return null;

  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-900"
    >
      {t(`${action}.cta`)}
      <span className="sr-only"> {t("newTab")}</span>
    </a>
  );
}
