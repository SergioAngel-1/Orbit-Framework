import "server-only";
import { getSession } from "@/lib/auth/session";
import { storeFetch } from "@/lib/woocommerce/store-client";
import {
  readCartToken,
  writeCartToken,
  clearCartToken,
} from "@/lib/woocommerce/cart-cookie";
import { planCartStockAdjustments } from "@/lib/woocommerce/cart-stock";
import { logger } from "@/lib/observability/logger";
import type { StoreCart, StoreCartItem } from "@/types/woocommerce";

/**
 * Auth del carrito para Store API.
 *
 * - Invitado: solo `Cart-Token` (cookie del dispositivo).
 * - Autenticado: JWT + cookie del carrito del customer (multi-dispositivo).
 */
export async function getCartRequestAuth(): Promise<{
  cartToken: string | null;
  authToken: string | null;
  /** Id del cliente con sesión; `null` si es invitado. */
  userId: string | null;
}> {
  const [cartToken, session] = await Promise.all([readCartToken(), getSession()]);
  return {
    cartToken,
    authToken: session?.token ?? null,
    userId: session?.userId != null ? String(session.userId) : null,
  };
}

/**
 * Quita o reduce líneas sin stock / por encima del máximo disponible.
 * Mutaciones best-effort: un fallo en una línea no aborta el resto.
 */
export async function applyCartStockAdjustments(
  cart: StoreCart,
  auth: { cartToken: string | null; authToken: string | null },
): Promise<{ cart: StoreCart; cartToken: string | null; adjusted: number }> {
  let current = cart;
  let token = auth.cartToken;
  let adjusted = 0;

  // Completa `quantity_limits` si Woo no los mandó (versiones viejas / edge).
  current = {
    ...current,
    items: await enrichMissingQuantityLimits(current.items, auth),
  };

  const plans = planCartStockAdjustments(current.items);
  if (plans.length === 0) {
    return { cart: current, cartToken: token, adjusted: 0 };
  }

  for (const plan of plans) {
    try {
      if (plan.action === "remove") {
        const res = await storeFetch<StoreCart>("/cart/remove-item", {
          method: "POST",
          body: { key: plan.key },
          cartToken: token,
          authToken: auth.authToken,
        });
        current = res.data;
        token = res.cartToken;
        adjusted += 1;
      } else {
        const res = await storeFetch<StoreCart>("/cart/update-item", {
          method: "POST",
          body: { key: plan.key, quantity: plan.quantity },
          cartToken: token,
          authToken: auth.authToken,
        });
        current = res.data;
        token = res.cartToken;
        adjusted += 1;
      }
    } catch (error) {
      logger.warn(
        {
          event: "cart.stock_adjust.error",
          action: plan.action,
          key: plan.key,
          err: error instanceof Error ? error.message : error,
        },
        "No se pudo ajustar línea sin stock",
      );
    }
  }

  if (adjusted > 0) {
    logger.info(
      { event: "cart.stock_adjust", adjusted, plans: plans.length },
      "Carrito reconciliado con existencias",
    );
  }

  return { cart: current, cartToken: token, adjusted };
}

/**
 * Tras login/registro/2FA: carga el carrito del customer (API) y descarta el
 * guest local. **Prevalece el de la API** — no se fusiona el Cart-Token guest.
 * Luego valida existencias y limpia líneas sin stock.
 *
 * Punto de extensión (módulo `shipping`, tarea 54): aquí es donde se fijará el
 * destino de envío del carrito con la dirección predeterminada de la cuenta.
 */
export async function syncAuthenticatedCart(authToken: string): Promise<void> {
  try {
    const hadGuestToken = Boolean(await readCartToken());

    // Sin Cart-Token guest: Woo no mergea el carrito del navegador sobre el del
    // customer. El carrito autenticado (otros dispositivos / sesión previa) gana.
    const { data, cartToken } = await storeFetch<StoreCart>("/cart", {
      authToken,
    });

    const reconciled = await applyCartStockAdjustments(data, {
      cartToken,
      authToken,
    });

    if (reconciled.cartToken) {
      await writeCartToken(reconciled.cartToken);
    } else {
      await clearCartToken();
    }

    logger.info(
      {
        event: "cart.sync_auth",
        hadGuestToken,
        discardedGuest: hadGuestToken,
        apiItems: data.items_count,
        stockAdjusted: reconciled.adjusted,
      },
      "Carrito del customer cargado tras autenticación (API prevalece)",
    );
  } catch (error) {
    logger.warn(
      {
        event: "cart.sync_auth.error",
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo sincronizar el carrito tras autenticación (best-effort)",
    );
  }
}

/**
 * Vacía el carrito del customer en Woo + borra la cookie local.
 * Usado por logout-all (no por logout normal).
 */
export async function clearAuthenticatedCart(authToken: string): Promise<void> {
  try {
    const existing = await readCartToken();
    await storeFetch<StoreCart>("/cart/items", {
      method: "DELETE",
      cartToken: existing,
      authToken,
    });
  } catch (error) {
    logger.warn(
      {
        event: "cart.clear_auth.error",
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo vaciar el carrito remoto en logout-all (best-effort)",
    );
  }
  await clearCartToken();
}

type StoreProductStock = {
  is_in_stock?: boolean;
  is_purchasable?: boolean;
  add_to_cart?: { maximum?: number; minimum?: number; multiple_of?: number };
};

async function enrichMissingQuantityLimits(
  items: StoreCartItem[],
  auth: { cartToken: string | null; authToken: string | null },
): Promise<StoreCartItem[]> {
  const missing = items.filter((i) => !i.quantity_limits);
  if (missing.length === 0) return items;

  const limitsById = new Map<number, NonNullable<StoreCartItem["quantity_limits"]>>();

  await Promise.all(
    missing.map(async (item) => {
      if (limitsById.has(item.id)) return;
      try {
        const { data } = await storeFetch<StoreProductStock>(`/products/${item.id}`, {
          cartToken: auth.cartToken,
          authToken: auth.authToken,
        });
        const purchasable = data.is_purchasable !== false && data.is_in_stock !== false;
        const maxRaw = data.add_to_cart?.maximum;
        const max = purchasable
          ? Number.isFinite(Number(maxRaw))
            ? Number(maxRaw)
            : 9999
          : 0;
        limitsById.set(item.id, {
          minimum: Number(data.add_to_cart?.minimum ?? 1) || 1,
          maximum: max,
          multiple_of: Number(data.add_to_cart?.multiple_of ?? 1) || 1,
          editable: true,
        });
      } catch {
        // Sin datos: no inventamos límites (la línea se deja intacta).
      }
    }),
  );

  if (limitsById.size === 0) return items;

  return items.map((item) =>
    item.quantity_limits
      ? item
      : limitsById.has(item.id)
        ? { ...item, quantity_limits: limitsById.get(item.id) }
        : item,
  );
}
