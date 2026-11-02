"use client";
import { createContext, useContext, useMemo, useState, type ReactNode } from "react";
import { CartStoreProvider, useCartStore, type CartStore } from "@/lib/client/cart";
import { trackEvent } from "@/components/analytics/track-event";
import { addToCartParams } from "@/lib/analytics/ecommerce";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Carrito de la vista heredada: el estado y las mutaciones son del núcleo
//  (`lib/client/cart`, `useCartStore`); aquí solo se añade lo visual: el
//  CartDrawer (mini-cart deslizante) y el evento de analítica al añadir.
// ============================================================================

interface CartState extends Omit<CartStore, "run"> {
  drawerOpen: boolean;
  openDrawer: () => void;
  closeDrawer: () => void;
  toggleDrawer: () => void;
}

const DrawerContext = createContext<{
  open: boolean;
  setOpen: (update: (open: boolean) => boolean) => void;
} | null>(null);

export function useCart(): CartState {
  const store = useCartStore();
  const drawer = useContext(DrawerContext);
  if (!drawer) throw new Error("useCart debe usarse dentro de <CartProvider>.");
  return {
    ...store,
    drawerOpen: drawer.open,
    openDrawer: () => drawer.setOpen(() => true),
    closeDrawer: () => drawer.setOpen(() => false),
    toggleDrawer: () => drawer.setOpen((open) => !open),
    // Abrir el cajón al añadir, también si falló: enseña el error del carrito.
    addItem: (id, quantity, extensions) =>
      store.addItem(id, quantity, extensions).then((ok) => {
        drawer.setOpen(() => true);
        return ok;
      }),
  };
}

/** Evento de analítica con LO AÑADIDO (no-op sin consentimiento/proveedor). */
function trackAdded(cart: StoreCart, productId: number, quantity: number) {
  const params = addToCartParams(cart, productId, quantity);
  if (params) trackEvent("add_to_cart", params);
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [open, setOpenState] = useState(false);
  const drawer = useMemo(
    () => ({
      open,
      setOpen: (update: (open: boolean) => boolean) => setOpenState(update),
    }),
    [open],
  );
  return (
    <CartStoreProvider onItemAdded={trackAdded}>
      <DrawerContext.Provider value={drawer}>{children}</DrawerContext.Provider>
    </CartStoreProvider>
  );
}
