import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSession } from "@/lib/auth/session";
import { rememberGuestOrder } from "@/lib/checkout/guest-order-cookie";
import {
  readCartToken,
  writeCartToken,
  clearCartToken,
} from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { checkoutSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import { getSiteConfig } from "@/lib/config";
import { canSubmitCheckout } from "@/lib/validation/checkout-guard";
import {
  ONLINE_PAYMENT_METHOD,
  WHATSAPP_PAYMENT_METHOD,
  enabledPaymentChoices,
  paymentChoiceFor,
} from "@/lib/payments/checkout-options";
import { markOrderAwaitingPaymentLink } from "@/lib/payments/orders";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
  releaseIdempotencyKey,
} from "@/lib/security/idempotency";
import type { StoreCart, StoreCheckoutResponse } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * POST /api/store/checkout
 * Crea el pedido a partir del carrito actual (Store API).
 *
 * Idempotencia: si el cliente envía la cabecera `Idempotency-Key`, reintentos
 * con la misma clave no crean pedidos duplicados (replay de la respuesta).
 *
 * El cobro real se confirma vía webhook de la pasarela; aquí solo se crea el
 * pedido.
 *
 * Sesión: obligatoria salvo que `ecommerce.checkout_login_required` esté
 * apagado en el Control Center (compra de invitado).
 *
 * Con sesión, vinculación de propietario por DOS vías complementarias (defensa
 * en profundidad):
 *   1. Se reenvía el JWT a la Store API (Woo liga el `customer_id` de forma
 *      nativa cuando la petición está autenticada), y
 *   2. tras crear el pedido se confirma/fija el `customer_id` vía wc/v3.
 * Sin sesión, el pedido queda de invitado (`customer_id = 0`) y su `order_key`
 * se guarda en una cookie httpOnly: es la prueba de propiedad que luego autoriza
 * a pagarlo y ver su confirmación (`lib/checkout/order-access.ts`).
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "checkout", limit: 10, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  // El flag se consulta solo sin sesión: con ella el checkout siempre vale. Con
  // WordPress caído `getSiteConfig` cae al valor por defecto (login obligatorio):
  // no se abre la compra de invitado sin que nadie lo haya pedido.
  const session = await getSession();
  if (!session && (await getSiteConfig()).ecommerce.checkout_login_required) {
    return handleApiError(new Error("UNAUTHENTICATED"));
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    logger.warn({ event: "checkout.invalid_json" }, "JSON inválido en checkout");
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = checkoutSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "checkout.validation_error" },
      "Validación fallida en checkout",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // Medio de pago: solo los del checkout (pago en línea, link por WhatsApp,
  // efectivo), cada uno si está activado en el Control Center. Cualquier otro
  // gateway que WooCommerce tenga activo no se ofrece en el sitio, y se
  // rechaza ANTES de reservar la clave de idempotencia.
  const requestedMethod = parsed.data.payment_method;
  const choice = paymentChoiceFor(requestedMethod);
  if (!choice) {
    logger.warn(
      { event: "checkout.payment_method_invalid", paymentMethod: requestedMethod },
      "Medio de pago no válido en checkout",
    );
    return NextResponse.json({ error: "Medio de pago no válido." }, { status: 422 });
  }
  if (!enabledPaymentChoices(await getSiteConfig()).includes(choice)) {
    logger.warn(
      { event: "checkout.payment_method_disabled", paymentMethod: requestedMethod },
      "Medio de pago desactivado en checkout",
    );
    return NextResponse.json(
      { error: "Ese medio de pago no está disponible." },
      { status: 422 },
    );
  }

  // --- Idempotencia (opcional pero recomendada) ---
  const idemKey = request.headers.get("Idempotency-Key");
  const useIdempotency = isValidIdempotencyKey(idemKey);

  if (useIdempotency) {
    const state = await reserveIdempotencyKey(idemKey);
    if (state.status === "replay") {
      logger.info(
        { event: "checkout.idempotent_replay" },
        "Replay de checkout idempotente",
      );
      return NextResponse.json(state.body, { status: state.statusCode });
    }
    if (state.status === "conflict") {
      logger.warn(
        { event: "checkout.idempotent_conflict" },
        "Conflicto de idempotencia en checkout",
      );
      return NextResponse.json(
        { error: "Un pedido con esta clave ya se está procesando o se completó." },
        { status: 409 },
      );
    }
  }

  try {
    const token = await readCartToken();

    // Guard de envío del servidor: si el carrito necesita envío y Woo no ofrece
    // ninguna tarifa (zona mal configurada, país no cubierto), no se crea el
    // pedido con envío 0. El cliente ya aplica la misma regla, pero una petición
    // manual podría saltársela.
    const cartCheck = await storeFetch<StoreCart>("/cart", {
      cartToken: token,
      authToken: session?.token,
    });
    if (!canSubmitCheckout(cartCheck.data)) {
      // No se creó ningún pedido: libera la reserva para que un reintento
      // legítimo no choque con un «processing» fantasma.
      if (useIdempotency) await releaseIdempotencyKey(idemKey);
      logger.warn(
        { event: "checkout.shipping_guard_blocked", userId: session?.userId ?? null },
        "Checkout bloqueado: el carrito necesita envío y no hay tarifa disponible",
      );
      return NextResponse.json(
        { error: "No hay tarifas de envío disponibles para esta dirección." },
        { status: 422 },
      );
    }

    // Medios que Woo ofrece para ESTE carrito (salen del propio carrito: el
    // `GET /checkout` no los trae y crea un pedido borrador en cada llamada).
    // Lista vacía = desconocida: decide la Store API.
    const available = cartCheck.data.payment_methods ?? [];
    let paymentMethod: string = requestedMethod;
    if (
      paymentMethod !== ONLINE_PAYMENT_METHOD &&
      available.length > 0 &&
      !available.includes(paymentMethod)
    ) {
      // Efectivo o WhatsApp pedido y Woo no lo ofrece (ajuste desalineado):
      // sustituirlo cambiaría el medio de pago sin que el cliente lo sepa.
      throw new PaymentMethodUnavailableError();
    }
    if (available.length > 0 && !available.includes(paymentMethod)) {
      // Nunca `cod` ni WhatsApp: un pago en línea no puede acabar sin cobro.
      const preferred = [ONLINE_PAYMENT_METHOD, "bacs", "cheque"] as const;
      paymentMethod = preferred.find((id) => available.includes(id)) ?? available[0]!;
      logger.info(
        {
          event: "checkout.payment_method_fallback",
          requested: requestedMethod,
          used: paymentMethod,
          available,
        },
        "payment_method sustituido por uno disponible",
      );
    }

    const { data, cartToken } = await storeFetch<StoreCheckoutResponse>("/checkout", {
      method: "POST",
      body: { ...parsed.data, payment_method: paymentMethod },
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

    if (data.order_id && !session && data.order_key) {
      // Invitado: sin cuenta a la que ligarlo, la order_key es la prueba de
      // propiedad para pagar y ver la confirmación desde este navegador.
      await rememberGuestOrder({ orderId: data.order_id, orderKey: data.order_key });
    } else if (session && data.order_id) {
      // Vía 2 (salvaguarda): garantizamos que el pedido quede ligado al usuario.
      // Si la Store API ya lo asoció (vía 1), este PUT es idempotente; si no lo
      // hizo, lo corrige aquí. Un fallo aquí NO invalida el pedido ya creado:
      // se registra para conciliación manual en vez de romper la compra.
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

    // Link de pago por WhatsApp: el pedido pasa a «En espera» (ver
    // `markOrderAwaitingPaymentLink`), que es lo que dispara los correos. Un
    // fallo aquí no invalida el pedido ya creado: se registra para conciliar.
    if (data.order_id && paymentMethod === WHATSAPP_PAYMENT_METHOD) {
      try {
        await markOrderAwaitingPaymentLink(data.order_id);
      } catch (holdError) {
        logger.error(
          {
            event: "checkout.on_hold_failed",
            order_id: data.order_id,
            err: holdError instanceof Error ? holdError.message : holdError,
          },
          "No se pudo pasar el pedido a «En espera»; no se enviaron sus correos",
        );
      }
    }

    if (useIdempotency) {
      await storeIdempotentResult(idemKey, data, 201);
    }

    logger.info(
      {
        event: "checkout.success",
        order_id: data.order_id,
        userId: session?.userId ?? null,
        guest: !session,
      },
      "Pedido creado en checkout",
    );
    return NextResponse.json(data, { status: 201 });
  } catch (error) {
    // Libera la reserva para permitir un reintento legítimo.
    if (useIdempotency) {
      await releaseIdempotencyKey(idemKey);
    }
    if (error instanceof PaymentMethodUnavailableError) {
      return NextResponse.json(
        { error: "Ese medio de pago no está disponible." },
        { status: 422 },
      );
    }
    logger.error(
      { event: "checkout.error", err: error instanceof Error ? error.message : error },
      "Error en checkout",
    );
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

/** Medio activado en el panel pero no ofrecido por WooCommerce para este carrito. */
class PaymentMethodUnavailableError extends Error {
  constructor() {
    super("payment_method_unavailable");
  }
}
