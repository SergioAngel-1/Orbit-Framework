"use client";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { cartApi } from "@/lib/client/store-api";
import { trackEvent } from "@/components/analytics/analytics-provider";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Estado global del carrito (cliente). El carrito real vive en el servidor
//  (Store API vía BFF); aquí mantenemos una copia para la UI.
//  También gestiona el estado del CartDrawer (mini-cart deslizante).
// ============================================================================

interface CartState {
  cart: StoreCart | null;
  count: number;
  loading: boolean;
  error: string | null;
  drawerOpen: boolean;
  openDrawer: () => void;
  closeDrawer: () => void;
  toggleDrawer: () => void;
  addItem: (id: number, quantity?: number) => Promise<void>;
  updateItem: (key: string, quantity: number) => Promise<void>;
  removeItem: (key: string) => Promise<void>;
  clear: () => Promise<void>;
  refresh: () => Promise<void>;
  applyCoupon: (code: string) => Promise<void>;
  removeCoupon: (code: string) => Promise<void>;
  selectShippingRate: (packageId: number, rateId: string) => Promise<void>;
}

const CartContext = createContext<CartState | null>(null);

export function useCart(): CartState {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart debe usarse dentro de <CartProvider>.");
  return ctx;
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [cart,       setCart]       = useState<StoreCart | null>(null);
  const [loading,    setLoading]    = useState(false);
  const [error,      setError]      = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      setCart(await cartApi.get());
    } catch {
      /* carrito no disponible: se reintenta en la próxima acción */
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const run = useCallback(async (action: () => Promise<StoreCart>) => {
    setError(null);
    setLoading(true);
    try {
      setCart(await action());
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error en el carrito.");
    } finally {
      setLoading(false);
    }
  }, []);

  const value: CartState = {
    cart,
    count:       cart?.items_count ?? 0,
    loading,
    error,
    drawerOpen,
    openDrawer:  () => setDrawerOpen(true),
    closeDrawer: () => setDrawerOpen(false),
    toggleDrawer:() => setDrawerOpen((o) => !o),
    addItem: (id, quantity = 1) =>
      run(() => cartApi.addItem(id, quantity)).then(() => {
        // Evento de analítica (no-op sin consentimiento/proveedor).
        trackEvent("add_to_cart", { product_id: id, quantity });
        // Abrir el drawer automáticamente al añadir al carrito
        setDrawerOpen(true);
      }),
    updateItem: (key, quantity) => run(() => cartApi.updateItem(key, quantity)),
    removeItem:  (key)          => run(() => cartApi.removeItem(key)),
    clear:       ()             => run(() => cartApi.clear()),
    refresh,
    applyCoupon: (code)         => run(() => cartApi.applyCoupon(code)),
    removeCoupon:(code)         => run(() => cartApi.removeCoupon(code)),
    selectShippingRate: (pkg, rate) => run(() => cartApi.selectShippingRate(pkg, rate)),
  };

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}
