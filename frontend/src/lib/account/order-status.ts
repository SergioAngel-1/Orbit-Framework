// Estados de pedido de WooCommerce core y su presentación (color del badge).
// La etiqueta legible se traduce en la vista con el namespace account.orderStatus,
// indexado dinámicamente por `t(\`orderStatus.${order.status}\`)` — por eso
// "on-hold" se mantiene en kebab-case en vez de camelCase: es literalmente el
// string que devuelve la API de WooCommerce, no una elección de estilo nuestra.
export const ORDER_STATUSES = [
  "pending",
  "processing",
  "on-hold",
  "completed",
  "cancelled",
  "refunded",
  "failed",
] as const;

export type OrderStatus = (typeof ORDER_STATUSES)[number];

export function isKnownOrderStatus(status: string): status is OrderStatus {
  return (ORDER_STATUSES as readonly string[]).includes(status);
}

/** Clases Tailwind para el badge según el estado. */
export function orderStatusClasses(status: string): string {
  switch (status) {
    case "completed":
      return "border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200";
    case "processing":
      return "border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200";
    case "on-hold":
    case "pending":
      return "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200";
    case "cancelled":
    case "failed":
      return "border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200";
    default:
      return "border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300";
  }
}
