"use client";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { cartApi, type ShippingDestination } from "./store-api";
import type { ItemExtensions, StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Estado del carrito en el navegador: capa de datos del núcleo, sin UI.
//
//  El carrito real vive en el servidor (Store API vía BFF); aquí se mantiene
//  la copia que pinta la vista. Lo delicado ya está resuelto para cualquier
//  diseño: mutaciones superpuestas (doble clic en «+») pueden resolverse
//  fuera de orden y gana siempre la que se DISPARÓ última; una respuesta
//  vieja se descarta. Lo visual (cajón, avisos, analítica) lo añade la vista
//  con `onItemAdded` o envolviendo el proveedor (`components/cart/cart-context`).
// ============================================================================

export interface CartStore {
  cart: StoreCart | null;
  count: number;
  /** Arranca en `true`: el carrito todavía no se ha leído del servidor. */
  loading: boolean;
  /** Mensaje de la última mutación fallida (lo da el BFF). */
  error: string | null;
  /**
   * Lo que lanzó la última mutación fallida, tal cual: un `BffError` (estado, código y
   * cuerpo) en las de serie, o el fallo propio de una acción de `run`. `null` tras una que
   * sale bien.
   */
  failure: unknown;
  /** Fallo de la última lectura del carrito (carga inicial o `refresh`); `null` si leyó bien. */
  readError: Error | null;
  /** `extensions`: detalles de la línea declarados por la instancia (`order-item-details`). */
  addItem: (
    id: number,
    quantity?: number,
    extensions?: ItemExtensions,
  ) => Promise<void>;
  updateItem: (key: string, quantity: number) => Promise<void>;
  removeItem: (key: string) => Promise<void>;
  clear: () => Promise<void>;
  refresh: () => Promise<void>;
  applyCoupon: (code: string) => Promise<void>;
  removeCoupon: (code: string) => Promise<void>;
  selectShippingRate: (packageId: number, rateId: string) => Promise<void>;
  /** Módulo `shipping`: fija la ciudad de destino y recalcula las tarifas. */
  setShippingDestination: (destination: ShippingDestination) => Promise<void>;
  /**
   * Aplica una mutación propia que devuelve el carrito (p. ej. una ruta BFF de la
   * instancia), con el mismo orden y manejo de error que las de serie.
   */
  run: (action: () => Promise<StoreCart>) => Promise<boolean>;
}

function asError(e: unknown): Error {
  return e instanceof Error ? e : new Error("No se pudo leer el carrito.");
}

const CartStoreContext = createContext<CartStore | null>(null);

export function useCartStore(): CartStore {
  const ctx = useContext(CartStoreContext);
  if (!ctx) throw new Error("useCartStore debe usarse dentro de <CartStoreProvider>.");
  return ctx;
}

export interface CartStoreProviderProps {
  children: ReactNode;
  /** Tras añadir con éxito: el carrito que devolvió el servidor (analítica, abrir un cajón…). */
  onItemAdded?: (cart: StoreCart, productId: number, quantity: number) => void;
}

export function CartStoreProvider({ children, onItemAdded }: CartStoreProviderProps) {
  const [cart, setCart] = useState<StoreCart | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [failure, setFailure] = useState<unknown>(null);
  const [readError, setReadError] = useState<Error | null>(null);
  const requestIdRef = useRef(0);
  const onItemAddedRef = useRef(onItemAdded);
  useEffect(() => {
    onItemAddedRef.current = onItemAdded;
  }, [onItemAdded]);

  const refresh = useCallback(async () => {
    const requestId = ++requestIdRef.current;
    const isLatest = () => requestId === requestIdRef.current;
    setLoading(true);
    try {
      const result = await cartApi.get();
      if (isLatest()) {
        setCart(result);
        setReadError(null);
      }
    } catch (e) {
      // Carrito no disponible: se reintenta en la próxima acción; la vista decide si lo dice.
      if (isLatest()) setReadError(asError(e));
    } finally {
      if (isLatest()) setLoading(false);
    }
  }, []);

  // Carga inicial. Equivale a `refresh()` sin `setState` síncrono en el cuerpo
  // del efecto; `active` descarta el resultado si el proveedor se desmontó.
  useEffect(() => {
    let active = true;
    const requestId = ++requestIdRef.current;
    const isLatest = () => active && requestId === requestIdRef.current;
    void cartApi
      .get()
      .then((result) => {
        if (isLatest()) setCart(result);
      })
      .catch((e: unknown) => {
        if (isLatest()) setReadError(asError(e));
      })
      .finally(() => {
        if (isLatest()) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  const run = useCallback(async (action: () => Promise<StoreCart>) => {
    const requestId = ++requestIdRef.current;
    const isLatest = () => requestId === requestIdRef.current;
    setError(null);
    setFailure(null);
    setLoading(true);
    try {
      const result = await action();
      if (isLatest()) {
        setCart(result);
        setReadError(null);
      }
      return true;
    } catch (e) {
      if (isLatest()) {
        setError(e instanceof Error ? e.message : "Error en el carrito.");
        setFailure(e);
      }
      return false;
    } finally {
      if (isLatest()) setLoading(false);
    }
  }, []);

  const value = useMemo<CartStore>(
    () => ({
      cart,
      count: cart?.items_count ?? 0,
      loading,
      error,
      failure,
      readError,
      addItem: async (id, quantity = 1, extensions) => {
        const added: { cart?: StoreCart } = {};
        const ok = await run(async () => {
          added.cart = await cartApi.addItem(id, quantity, extensions);
          return added.cart;
        });
        if (ok && added.cart) onItemAddedRef.current?.(added.cart, id, quantity);
      },
      updateItem: async (key, quantity) => {
        await run(() => cartApi.updateItem(key, quantity));
      },
      removeItem: async (key) => {
        await run(() => cartApi.removeItem(key));
      },
      clear: async () => {
        await run(() => cartApi.clear());
      },
      refresh,
      applyCoupon: async (code) => {
        await run(() => cartApi.applyCoupon(code));
      },
      removeCoupon: async (code) => {
        await run(() => cartApi.removeCoupon(code));
      },
      selectShippingRate: async (pkg, rate) => {
        await run(() => cartApi.selectShippingRate(pkg, rate));
      },
      setShippingDestination: async (destination) => {
        await run(() => cartApi.setShippingDestination(destination));
      },
      run,
    }),
    [cart, loading, error, failure, readError, refresh, run],
  );

  return (
    <CartStoreContext.Provider value={value}>{children}</CartStoreContext.Provider>
  );
}
