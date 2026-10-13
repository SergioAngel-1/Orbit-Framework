"use client";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { cartApi } from "@/lib/client/store-api";
import { trackEvent } from "@/components/analytics/track-event";
import { addToCartParams } from "@/lib/analytics/ecommerce";
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
  const [cart, setCart] = useState<StoreCart | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  // Mutaciones superpuestas (doble clic en «+», cambiar de cantidad dos veces)
  // pueden RESOLVERSE fuera de orden. Gana siempre la que se DISPARÓ última: la
  // respuesta de una anterior que llega tarde se descarta, o el cajón enseñaba
  // un carrito que ya no es el del servidor.
  const requestIdRef = useRef(0);

  const refresh = useCallback(async () => {
    const requestId = ++requestIdRef.current;
    const isLatest = () => requestId === requestIdRef.current;
    setLoading(true);
    try {
      const result = await cartApi.get();
      if (isLatest()) setCart(result);
    } catch {
      /* carrito no disponible: se reintenta en la próxima acción */
    } finally {
      if (isLatest()) setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const run = useCallback(async (action: () => Promise<StoreCart>) => {
    const requestId = ++requestIdRef.current;
    const isLatest = () => requestId === requestIdRef.current;
    setError(null);
    setLoading(true);
    try {
      const result = await action();
      if (isLatest()) setCart(result);
    } catch (e) {
      if (isLatest()) {
        setError(e instanceof Error ? e.message : "Error en el carrito.");
      }
    } finally {
      if (isLatest()) setLoading(false);
    }
  }, []);

  const value: CartState = {
    cart,
    count: cart?.items_count ?? 0,
    loading,
    error,
    drawerOpen,
    openDrawer: () => setDrawerOpen(true),
    closeDrawer: () => setDrawerOpen(false),
    toggleDrawer: () => setDrawerOpen((o) => !o),
    addItem: (id, quantity = 1) =>
      run(async () => {
        const updated = await cartApi.addItem(id, quantity);
        // Evento de analítica con LO AÑADIDO, leído del carrito que devolvió el
        // servidor (no-op sin consentimiento/proveedor). Solo si la petición salió bien.
        const params = addToCartParams(updated, id, quantity);
        if (params) trackEvent("add_to_cart", params);
        return updated;
      }).then(() => {
        // Abrir el drawer automáticamente al añadir al carrito
        setDrawerOpen(true);
      }),
    updateItem: (key, quantity) => run(() => cartApi.updateItem(key, quantity)),
    removeItem: (key) => run(() => cartApi.removeItem(key)),
    clear: () => run(() => cartApi.clear()),
    refresh,
    applyCoupon: (code) => run(() => cartApi.applyCoupon(code)),
    removeCoupon: (code) => run(() => cartApi.removeCoupon(code)),
    selectShippingRate: (pkg, rate) => run(() => cartApi.selectShippingRate(pkg, rate)),
  };

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}
