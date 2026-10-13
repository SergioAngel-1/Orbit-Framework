import "server-only";
import { cookies } from "next/headers";
import { sessionCookieOptions } from "@/lib/security/cookies";
import {
  parseGuestOrderCredential,
  serializeGuestOrderCredential,
  type GuestOrderCredential,
} from "./order-access";

// ============================================================================
//  Cookie httpOnly con la credencial del ÚLTIMO pedido de invitado de este
//  navegador (`<orderId>:<order_key>`). La leen `/api/payments/create`,
//  `/api/payments/return` y `/checkout/return` para autorizar a quien acaba
//  de comprar sin cuenta. `SameSite=Lax` basta: el retorno de la pasarela es
//  una navegación de primer nivel (GET), que sí la lleva.
// ============================================================================

const GUEST_ORDER_COOKIE = "hwe_guest_order";

/** 7 días: cubre un pago que se retoma horas después desde el correo. */
const GUEST_ORDER_MAX_AGE = 7 * 24 * 60 * 60;

export async function rememberGuestOrder(
  credential: GuestOrderCredential,
): Promise<void> {
  const store = await cookies();
  store.set(
    GUEST_ORDER_COOKIE,
    serializeGuestOrderCredential(credential),
    sessionCookieOptions(GUEST_ORDER_MAX_AGE),
  );
}

export async function readGuestOrderCredential(): Promise<GuestOrderCredential | null> {
  const store = await cookies();
  return parseGuestOrderCredential(store.get(GUEST_ORDER_COOKIE)?.value);
}

/**
 * Retira la credencial: el pedido ya es de una cuenta (`claimGuestOrder`) y
 * la cookie no volvería a abrir nada (`canAccessOrder` solo la acepta en
 * pedidos de invitado).
 */
export async function forgetGuestOrder(): Promise<void> {
  const store = await cookies();
  store.set(GUEST_ORDER_COOKIE, "", sessionCookieOptions(0));
}
