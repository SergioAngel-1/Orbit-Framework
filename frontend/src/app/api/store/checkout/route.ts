import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSession } from "@/lib/auth/session";
import {
  readCartToken,
  writeCartToken,
  clearCartToken,
} from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { checkoutSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
  releaseIdempotencyKey,
} from "@/lib/security/idempotency";
import type { StoreCheckoutResponse } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * POST /api/store/checkout
 * Crea el pedido a partir del carrito actual (Store API).
 *
 * Idempotencia: si el cliente envía la cabecera `Idempotency-Key`, reintentos
 * con la misma clave no crean pedidos duplicados (replay de la respuesta).
 *
 * NOTA (Fase 6): el cobro real se confirma vía webhook de la pasarela. Aquí se
 * crea el pedido; la integración de pago server-side llega en la Fase 6.
 *
 * Vinculación de propietario: si hay sesión, el pedido se asocia al usuario por
 * DOS vías complementarias (defensa en profundidad):
 *   1. Se reenvía el JWT a la Store API (Woo liga el `customer_id` de forma
 *      nativa cuando la petición está autenticada), y
 *   2. tras crear el pedido se confirma/fija el `customer_id` vía wc/v3.
 * Sin esto, el checkout generaría pedidos de invitado (`customer_id = 0`) y las
 * comprobaciones anti-IDOR de pagos/pedidos/cuenta nunca casarían con el comprador.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "checkout", limit: 10, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  // Sesión opcional: el checkout de invitado sigue permitido (session === null).
  const session = await getSession();

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    logger.warn({ event: "checkout.invalid_json" }, "JSON inválido en checkout");
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = checkoutSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn({ event: "checkout.validation_error" }, "Validación fallida en checkout");
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // --- Idempotencia (opcional pero recomendada) ---
  const idemKey = request.headers.get("Idempotency-Key");
  const useIdempotency = isValidIdempotencyKey(idemKey);

  if (useIdempotency) {
    const state = await reserveIdempotencyKey(idemKey);
    if (state.status === "replay") {
      logger.info({ event: "checkout.idempotent_replay" }, "Replay de checkout idempotente");
      return NextResponse.json(state.body, { status: state.statusCode });
    }
    if (state.status === "conflict") {
      logger.warn({ event: "checkout.idempotent_conflict" }, "Conflicto de idempotencia en checkout");
      return NextResponse.json(
        { error: "Un pedido con esta clave ya se está procesando o se completó." },
        { status: 409 },
      );
    }
  }

  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCheckoutResponse>("/checkout", {
      method: "POST",
      body: parsed.data,
      cartToken: token,
      // Vía 1: si hay sesión, autenticamos la creación del pedido como el usuario.
      authToken: session?.token,
    });

    // El carrito queda consumido: reiniciamos el token para empezar uno nuevo.
    if (cartToken) {
      await writeCartToken(cartToken);
    } else {
      await clearCartToken();
    }

    // Vía 2 (salvaguarda): garantizamos que el pedido quede ligado al usuario.
    // Si la Store API ya lo asoció (vía 1), este PUT es idempotente; si no lo
    // hizo, lo corrige aquí. Un fallo aquí NO invalida el pedido ya creado:
    // se registra para conciliación manual en vez de romper la compra.
    if (session && data.order_id) {
      try {
        await ensureOrderOwner(data.order_id, Number(session.userId));
      } catch (linkError) {
        logger.error(
          {
            event: "checkout.link_owner_failed",
            order_id: data.order_id,
            userId: session.userId,
            err: linkError instanceof Error ? linkError.message : linkError,
          },
          "No se pudo ligar el pedido al cliente; requiere conciliación",
        );
      }
    }

    if (useIdempotency) {
      await storeIdempotentResult(idemKey, data, 201);
    }

    logger.info(
      { event: "checkout.success", order_id: data.order_id, userId: session?.userId ?? null },
      "Pedido creado en checkout",
    );
    return NextResponse.json(data, { status: 201 });
  } catch (error) {
    // Libera la reserva para permitir un reintento legítimo.
    if (useIdempotency) {
      await releaseIdempotencyKey(idemKey);
    }
    logger.error({ event: "checkout.error", err: error instanceof Error ? error.message : error }, "Error en checkout");
    return handleApiError(error);
  }
}

/**
 * Garantiza que el pedido pertenezca al usuario indicado. Solo escribe si el
 * pedido sigue como invitado (`customer_id = 0`) o pertenece a otro id, de modo
 * que sea idempotente cuando la Store API ya lo asoció correctamente.
 */
async function ensureOrderOwner(orderId: number, userId: number): Promise<void> {
  if (!Number.isInteger(userId) || userId <= 0) return;

  const order = await wcFetch<{ customer_id: number }>(`/orders/${orderId}`);
  if (order.customer_id === userId) {
    return; // Ya está ligado (probablemente vía la Store API autenticada).
  }

  await wcFetch(`/orders/${orderId}`, {
    method: "PUT",
    body: { customer_id: userId },
  });
  logger.info(
    { event: "checkout.link_owner", order_id: orderId, userId },
    "Pedido ligado al cliente autenticado",
  );
}
