import "server-only";
import { cookies } from "next/headers";
import { CART_TOKEN_COOKIE, CART_TOKEN_MAX_AGE } from "./constants";
import {
  sessionCookieOptions,
  expiredCookieOptions,
} from "@/lib/security/cookies";

// ============================================================================
//  Persistencia del Cart-Token de la Store API en una cookie httpOnly.
//  El navegador nunca manipula el token: el carrito siempre pasa por el BFF.
// ============================================================================

export async function readCartToken(): Promise<string | null> {
  const store = await cookies();
  return store.get(CART_TOKEN_COOKIE)?.value ?? null;
}

export async function writeCartToken(token: string | null): Promise<void> {
  if (!token) return;
  const store = await cookies();
  store.set(CART_TOKEN_COOKIE, token, sessionCookieOptions(CART_TOKEN_MAX_AGE));
}

export async function clearCartToken(): Promise<void> {
  const store = await cookies();
  store.set(CART_TOKEN_COOKIE, "", expiredCookieOptions("/"));
}
