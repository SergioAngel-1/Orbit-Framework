import "server-only";
import { getRedis } from "@/lib/redis/client";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Eventos de pedido de WooCommerce (webhooks order.created / order.updated).
//
//  El payload de `order.updated` de WooCommerce SOLO trae el estado ACTUAL del
//  pedido, nunca el anterior. Para razonar sobre la TRANSICIÓN (p. ej. enviar
//  email solo cuando pasa a "completed") necesitamos recordar el último estado
//  visto. Lo persistimos en Redis con TTL.
//
//  Diseño fail-open: si Redis no está disponible, `previousStatus` es null y el
//  dispatcher trata el evento como no-diferencial (no rompe la entrega).
// ============================================================================

const STATUS_PREFIX = "order:status:";
const STATUS_TTL = 60 * 60 * 24 * 30; // 30 días

/**
 * Devuelve el último estado conocido del pedido y registra el actual.
 * @returns el estado previo almacenado, o null si no había (o sin Redis).
 */
export async function recordAndDiffStatus(
  orderId: number | string,
  currentStatus: string,
): Promise<string | null> {
  const redis = getRedis();
  if (!redis) return null;

  const key = `${STATUS_PREFIX}${orderId}`;
  try {
    const previous = await redis.get(key);
    await redis.set(key, currentStatus, "EX", STATUS_TTL);
    return previous;
  } catch {
    return null;
  }
}

export interface OrderEffectContext {
  /** Nombre del webhook de origen. */
  event: "order.created" | "order.updated";
  orderId: number | string;
  status: string;
  /** Estado anterior conocido (null en creación o si no se pudo determinar). */
  previousStatus: string | null;
  /** Payload crudo ya parseado, por si una integración necesita más campos. */
  payload: Record<string, unknown>;
}

/** Estados que representan una venta confirmada (gatillo típico de fulfilment). */
const PAID_STATUSES = new Set(["processing", "completed"]);

/**
 * Punto de extensión para las reacciones a cambios de pedido.
 *
 * Hoy registra la transición de forma estructurada y resuelve qué efectos
 * CORRESPONDERÍAN, dejando los envíos reales (email transaccional, sincronía de
 * inventario, ERP/facturación) como ganchos explícitos a implementar según el
 * negocio. Mantener esta función como ÚNICO lugar donde se decide "qué pasa
 * cuando un pedido cambia" evita lógica dispersa entre handlers.
 */
export async function dispatchOrderEffects(ctx: OrderEffectContext): Promise<void> {
  const { event, orderId, status, previousStatus } = ctx;
  const changed = previousStatus !== null && previousStatus !== status;

  logger.info(
    {
      event: "order.effects.dispatch",
      source: event,
      orderId,
      status,
      previousStatus,
      changed,
    },
    "Evaluando efectos del pedido",
  );

  // Solo reaccionamos a transiciones reales (evita reprocesar reentregas del
  // mismo estado). En creación, `previousStatus` es null → tratamos el alta.
  const becamePaid =
    PAID_STATUSES.has(status) &&
    (previousStatus === null || !PAID_STATUSES.has(previousStatus));

  const becameTerminalNegative =
    (status === "cancelled" || status === "failed" || status === "refunded") &&
    previousStatus !== status;

  // -------------------------------------------------------------------------
  //  Email transaccional AL CLIENTE: lo emite WooCommerce de forma NATIVA.
  //  Cuando el pedido cambia de estado (p. ej. a `processing` desde el webhook
  //  de pago vía `markOrderPaid` → `set_paid`), WooCommerce dispara su propio
  //  email con plantilla (ver `woocommerce-email-branding.php`). NO lo
  //  duplicamos aquí para evitar correos dobles. Este dispatcher cubre los
  //  efectos OPERATIVOS/integraciones que Woo no hace por sí mismo.
  // -------------------------------------------------------------------------

  if (event === "order.created") {
    await notifyOps({ kind: "order_created", orderId, status });
  }

  if (becamePaid) {
    // Efecto operativo: avisar a operaciones/ERP de que hay venta confirmada.
    await notifyOps({ kind: "order_paid", orderId, status });
  }

  if (becameTerminalNegative) {
    await notifyOps({ kind: "order_negative", orderId, status, previousStatus });
  }
}

interface OpsNotification {
  kind: "order_created" | "order_paid" | "order_negative";
  orderId: number | string;
  status: string;
  previousStatus?: string | null;
}

/**
 * Notificación OPERATIVA (no al cliente): envía el evento a un webhook interno
 * configurable (`ORDER_NOTIFICATION_WEBHOOK_URL`) — p. ej. Slack/Teams, un ERP
 * o una cola. Best-effort y server-only: un fallo aquí no afecta al ACK del
 * webhook de Woo. Si la variable no está, solo se registra (gancho explícito).
 */
async function notifyOps(payload: OpsNotification): Promise<void> {
  const url = process.env.ORDER_NOTIFICATION_WEBHOOK_URL;
  if (!url) {
    logger.info(
      { event: `order.effects.${payload.kind}`, ...payload },
      "Efecto de pedido (sin webhook configurado)",
    );
    return;
  }
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 5000);
    await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ source: "hwe", ...payload, at: new Date().toISOString() }),
      signal: controller.signal,
    });
    clearTimeout(timer);
    logger.info(
      { event: `order.effects.${payload.kind}.notified`, orderId: payload.orderId },
      "Notificación operativa enviada",
    );
  } catch (err) {
    logger.warn(
      {
        event: "order.effects.notify_failed",
        kind: payload.kind,
        orderId: payload.orderId,
        err: err instanceof Error ? err.message : err,
      },
      "No se pudo enviar la notificación operativa",
    );
  }
}
