import { NextResponse } from "next/server";
import { cookies } from "next/headers";
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
import { getGeoPack } from "@/lib/geo";
import { applyGeoPack } from "@/lib/geo/address";
import { canSubmitCheckout } from "@/lib/validation/checkout-guard";
import {
  ONLINE_PAYMENT_METHOD,
  WHATSAPP_PAYMENT_METHOD,
  enabledPaymentChoices,
  paymentChoiceFor,
} from "@/lib/payments/checkout-options";
import { markOrderAwaitingPaymentLink } from "@/lib/payments/orders";
import { getClientIp } from "@/lib/http/request-ip";
import { routing } from "@/i18n/routing";
import { checkoutReturnUrl } from "@/lib/checkout/return-url";
import { CONSENT_RECEIPT_COOKIE } from "@/lib/analytics/consent-record";
import {
  orderTrackingMetaData,
  type OrderTrackingContext,
} from "@/lib/analytics/order-tracking";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
  releaseIdempotencyKey,
} from "@/lib/security/idempotency";
import {
  STORE_API_IDENTITY_NAMESPACE,
  identityFromMeta,
  orderIdentityMetaData,
  storeApiIdentityExtension,
  type CustomerIdentity,
} from "@/lib/customer/identity";
import { attachIdentity } from "@/lib/customer/identity-api";
import { getShippingMethods } from "@/lib/shipping";
import { isModuleEnabled } from "@/lib/config/modules";
import { identityPolicy } from "@/lib/customer/policy";
import { resolveCheckoutIdentity } from "@/lib/checkout/identity";
import type {
  StoreCart,
  StoreCheckoutResponse,
  WooCustomer,
} from "@/types/woocommerce";

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

  // Direcciones según el paquete de país de la tienda: el estado se valida contra
  // la lista del paquete (y contra la ciudad) y sale normalizado a lo que exige
  // WooCommerce; nunca se rellena una división por defecto.
  const [geoPack, config] = await Promise.all([getGeoPack(), getSiteConfig()]);
  const geo = applyGeoPack(geoPack, {
    billing_address: parsed.data.billing_address,
    shipping_address: parsed.data.shipping_address,
  });
  if (Object.keys(geo.details).length > 0) {
    logger.warn(
      { event: "checkout.address_invalid" },
      "Dirección inválida en checkout",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: geo.details },
      { status: 422 },
    );
  }
  const input = { ...parsed.data, ...geo.addresses };

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
  if (!enabledPaymentChoices(config).includes(choice)) {
    logger.warn(
      { event: "checkout.payment_method_disabled", paymentMethod: requestedMethod },
      "Medio de pago desactivado en checkout",
    );
    return NextResponse.json(
      { error: "Ese medio de pago no está disponible." },
      { status: 422 },
    );
  }

  // --- Documento + aceptación de términos (módulo `customer-identity`) ---
  // Con el módulo apagado no se pide nada. Con sesión, lo que la cuenta ya tenga
  // manda (ver `resolveCheckoutIdentity`); lo que falte sale del formulario y se
  // guarda en la cuenta ANTES de crear el pedido: un documento de otra cuenta no
  // puede acabar en una compra.
  const policy = identityPolicy(config, geoPack);
  let orderIdentity: CustomerIdentity | null = null;
  if (policy.enabled) {
    try {
      const stored = session
        ? identityFromMeta(
            (await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`))
              .meta_data,
          )
        : null;
      const resolved = resolveCheckoutIdentity(stored, parsed.data, policy);
      if (!resolved.ok) {
        return NextResponse.json(
          { error: resolved.error, details: { [resolved.field]: [resolved.error] } },
          { status: 422 },
        );
      }
      orderIdentity = resolved.identity;

      if (session && resolved.persist) {
        const saved = await attachIdentity(Number(session.userId), resolved.persist);
        if (
          !saved.ok &&
          (saved.reason === "document_taken" || saved.reason === "document_locked")
        ) {
          const message = "Este documento ya está registrado en otra cuenta.";
          return NextResponse.json(
            { error: message, details: { identity: [message] } },
            { status: 409 },
          );
        }
        if (!saved.ok) {
          // WordPress no respondió: el pedido lleva igualmente su documento y su
          // aceptación; la cuenta los volverá a pedir en la próxima compra.
          logger.error(
            {
              event: "checkout.identity_persist_failed",
              userId: session.userId,
              reason: saved.reason,
            },
            "No se pudo guardar el documento/aceptación en la cuenta",
          );
        }
      }
    } catch (error) {
      logger.error(
        {
          event: "checkout.identity_error",
          err: error instanceof Error ? error.message : error,
        },
        "No se pudo leer la identidad del cliente",
      );
      return handleApiError(error);
    }
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
      const charging = preferred.find((id) => available.includes(id));
      // Si Woo no ofrece ninguno, tampoco se cae a lo primero que ofrezca (`cod`,
      // WhatsApp): el pedido nacería como efectivo y «Pagar ahora» fallaría después.
      if (!charging) throw new PaymentMethodUnavailableError();
      paymentMethod = charging;
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

    // Campos explícitos: `identity`, `terms_accepted` y `tracking` son del BFF y no viajan
    // a la Store API tal cual; lo que el BFF resolvió va en `extensions` para que el
    // pedido NAZCA con su documento (ver `storeApiIdentityExtension`).
    const { data, cartToken } = await storeFetch<StoreCheckoutResponse>("/checkout", {
      method: "POST",
      body: {
        billing_address: input.billing_address,
        shipping_address: input.shipping_address,
        payment_data: input.payment_data,
        customer_note: input.customer_note,
        payment_method: paymentMethod,
        ...(orderIdentity
          ? {
              extensions: {
                [STORE_API_IDENTITY_NAMESPACE]:
                  storeApiIdentityExtension(orderIdentity),
              },
            }
          : {}),
      },
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

    // Extras de envío (módulo `shipping`): cargos aditivos del pedido. Van ANTES del paso a
    // «En espera», que es el que dispara los correos, y un fallo no pierde el pedido.
    const methodIds = isModuleEnabled(config, "shipping")
      ? (parsed.data.shipping_method_ids ?? [])
      : [];
    if (data.order_id && methodIds.length > 0) {
      try {
        await applyShippingMethodFees(data.order_id, methodIds);
      } catch (feeError) {
        logger.error(
          {
            event: "checkout.shipping_fees_failed",
            order_id: data.order_id,
            err: feeError instanceof Error ? feeError.message : feeError,
          },
          "No se pudieron aplicar los extras de envío al pedido; requiere conciliación",
        );
      }
    }

    // Documento y aceptación en el pedido (salvaguarda: ya llegaron en `extensions`
    // al crearlo) y contexto de medición, en una sola escritura. Lo que hace falta para mandar la compra desde el
    // servidor cuando la pasarela confirme (consentimiento, cookies del píxel,
    // IP y navegador del comprador). Va ANTES del paso a «En espera», que es el
    // que dispara los correos. Un fallo aquí no pierde el pedido.
    if (data.order_id) {
      try {
        const context = await trackingContext(
          request,
          parsed.data.tracking,
          data.order_id,
        );
        await wcFetch(`/orders/${data.order_id}`, {
          method: "PUT",
          body: {
            meta_data: [
              ...(orderIdentity ? orderIdentityMetaData(orderIdentity) : []),
              ...orderTrackingMetaData(context),
            ],
          },
        });
      } catch (trackingError) {
        logger.error(
          {
            event: "checkout.order_tracking_failed",
            order_id: data.order_id,
            err: trackingError instanceof Error ? trackingError.message : trackingError,
          },
          "No se pudo guardar el contexto de medición en el pedido",
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

/**
 * Suma al pedido los métodos de envío extra elegidos como líneas de cargo (`fee_lines`).
 * Los importes se leen frescos de WordPress en este instante (`fresh`), no de la caché ISR:
 * lo cobrado es lo vigente, y un id que ya no existe o está inactivo simplemente no cuenta.
 */
async function applyShippingMethodFees(
  orderId: number,
  methodIds: number[],
): Promise<void> {
  const methods = await getShippingMethods({ fresh: true });
  const selected = new Set(methodIds);
  const feeLines = methods
    .filter((m) => selected.has(m.id))
    .map((m) => ({ name: m.name, total: String(m.addedValue), tax_status: "none" }));

  if (feeLines.length === 0) {
    if (methods.length === 0) {
      logger.warn(
        {
          event: "checkout.shipping_fees_empty_fetch",
          order_id: orderId,
          method_ids: methodIds,
        },
        "No se leyó ningún método de envío al aplicar los extras: posible fallo de WordPress",
      );
    }
    return;
  }

  await wcFetch(`/orders/${orderId}`, { method: "PUT", body: { fee_lines: feeLines } });
  logger.info(
    {
      event: "checkout.shipping_fees_applied",
      order_id: orderId,
      count: feeLines.length,
    },
    "Extras de envío aplicados al pedido",
  );
}

/** Medio activado en el panel pero no ofrecido por WooCommerce para este carrito. */
class PaymentMethodUnavailableError extends Error {
  constructor() {
    super("payment_method_unavailable");
  }
}

/**
 * Lo que necesita la Conversions API para mandar la compra cuando la pasarela
 * la confirme: el consentimiento que declara el navegador y, solo con él, las
 * cookies del píxel, la IP y el navegador del comprador (el pedido lo crea el
 * BFF, así que WooCommerce guarda la IP del servidor, no la suya) y la URL de
 * «gracias» en su idioma.
 */
async function trackingContext(
  request: Request,
  tracking: { consent: boolean; locale: (typeof routing.locales)[number] } | undefined,
  orderId: number,
): Promise<OrderTrackingContext> {
  if (!tracking?.consent) return { consent: false };
  const [jar, config] = await Promise.all([cookies(), getSiteConfig()]);
  return {
    consent: true,
    fbp: jar.get("_fbp")?.value,
    fbc: jar.get("_fbc")?.value,
    ip: getClientIp(request),
    userAgent: request.headers.get("user-agent") ?? undefined,
    sourceUrl: checkoutReturnUrl(config.brand.url, tracking.locale, orderId),
    // Comprobante del registro de consentimientos (módulo `consent-log`): para
    // comprobar al confirmarse el pago que no se retiró entre medias.
    consentReceipt: jar.get(CONSENT_RECEIPT_COOKIE)?.value,
  };
}
