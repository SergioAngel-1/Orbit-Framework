import "server-only";
import { wcFetch } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { canAccessOrder } from "@/lib/checkout/order-access";
import {
  forgetGuestOrder,
  readGuestOrderCredential,
} from "@/lib/checkout/guest-order-cookie";
import type { WooCustomer, WooOrder } from "@/types/woocommerce";

// ============================================================================
//  Pedido de invitado → cuenta, al abrir sesión.
//
//  Quien compra como invitado lleva la credencial `hwe_guest_order` (id +
//  `order_key` del pedido, cookie httpOnly que solo pone el BFF al crearlo).
//  Si en ese dispositivo inicia sesión o se registra, el pedido pasa a su
//  cuenta: lo ve en «Mis pedidos» y cuenta como compra para reseñar. Lo
//  invita a hacerlo la tarjeta de «gracias» (`GuestAccountInvite`).
//
//  Hacen falta las DOS pruebas: la credencial (el pedido se hizo en este
//  navegador) y que el correo del pedido sea el de la cuenta. Sin la segunda,
//  en un dispositivo compartido el pedido de otra persona acabaría —con su
//  dirección— en la cuenta de quien inicie sesión después.
//
//  Lo llaman los puntos que abren sesión (login, verify-2fa, register), igual
//  que `linkConsentReceipt`: si añades otro, añádelo ahí también. Va ANTES de
//  responder y no en `after()`: la tarjeta devuelve a «Mis pedidos», que tiene
//  que mostrar ya el pedido, y la cookie solo se puede retirar en la
//  respuesta. Solo cuesta algo cuando la credencial existe, y nunca lanza.
// ============================================================================

export type GuestOrderClaim =
  "linked" | "no_credential" | "not_claimable" | "email_mismatch" | "error";

/** Las llamadas van dentro del login: cortas y sin reintentos. */
const WC_OPTIONS = { timeoutMs: 5_000, retries: 0 } as const;

function sameEmail(
  a: string | null | undefined,
  b: string | null | undefined,
): boolean {
  const x = (a ?? "").trim().toLowerCase();
  return x !== "" && x === (b ?? "").trim().toLowerCase();
}

export async function claimGuestOrder(
  userId: string | number,
): Promise<GuestOrderClaim> {
  const id = Number(userId);
  if (!Number.isInteger(id) || id <= 0) return "no_credential";
  try {
    const guest = await readGuestOrderCredential();
    if (!guest) return "no_credential";

    const order = await wcFetch<WooOrder>(`/orders/${guest.orderId}`, WC_OPTIONS);
    // Ya no es de invitado (se ligó antes) o la clave no casa: la cookie no
    // abre nada. Si ya es de esta cuenta, se retira.
    if (!canAccessOrder(order, { guest })) {
      if (order.customer_id === id) await forgetGuestOrder();
      return "not_claimable";
    }

    const customer = await wcFetch<WooCustomer>(`/customers/${id}`, WC_OPTIONS);
    if (!sameEmail(order.billing?.email, customer.email)) {
      logger.info(
        {
          event: "checkout.guest_order.claim_email_mismatch",
          orderId: order.id,
          userId: id,
        },
        "El pedido de invitado no se liga: su correo no es el de la cuenta",
      );
      return "email_mismatch";
    }

    await wcFetch<WooOrder>(`/orders/${order.id}`, {
      ...WC_OPTIONS,
      method: "PUT",
      body: { customer_id: id },
    });
    await forgetGuestOrder();
    logger.info(
      { event: "checkout.guest_order.claimed", orderId: order.id, userId: id },
      "Pedido de invitado ligado a la cuenta",
    );
    return "linked";
  } catch (error) {
    logger.warn(
      {
        event: "checkout.guest_order.claim_failed",
        userId: id,
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo ligar el pedido de invitado a la cuenta",
    );
    return "error";
  }
}
