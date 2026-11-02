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
  /*
   * Las mutaciones resuelven `true` si el servidor las aceptó y `false` si fallaron (el
   * detalle queda en `error` y `failure`). Nunca lanzan.
   */
  /** `extensions`: detalles de la línea declarados por la instancia (`order-item-details`). */
  addItem: (
    id: number,
    quantity?: number,
    extensions?: ItemExtensions,
  ) => Promise<boolean>;
  updateItem: (key: string, quantity: number) => Promise<boolean>;
  removeItem: (key: string) => Promise<boolean>;
  clear: () => Promise<boolean>;
  refresh: () => Promise<void>;
  applyCoupon: (code: string) => Promise<boolean>;
  removeCoupon: (code: string) => Promise<boolean>;
  selectShippingRate: (packageId: number, rateId: string) => Promise<boolean>;
  /** Módulo `shipping`: fija la ciudad de destino y recalcula las tarifas. */
  setShippingDestination: (destination: ShippingDestination) => Promise<boolean>;
  /**
   * Aplica una mutación propia que devuelve el carrito (p. ej. una ruta BFF de la
   * instancia), con el mismo orden y manejo de error que las de serie. Con
   * `{ silent: true }` un fallo queda solo en `failure`, sin rellenar `error` (para un
   * fallo que la vista explica a su manera, no como error general del carrito).
   */
  run: (action: () => Promise<StoreCart>, options?: RunOptions) => Promise<boolean>;
}

export interface RunOptions {
  /** No rellenar `error`: el fallo queda solo en `failure`. */
  silent?: boolean;
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
  /**
   * Ajuste del carrito DENTRO del alta, antes de darla por buena (p. ej. fijar el destino
   * de envío recordado): recibe el carrito del alta y devuelve el que se pinta y llega a
   * `onItemAdded`. Comparte el orden de respuestas del alta. Si falla, el alta vale con el
   * carrito sin tocar y su error queda en `failure` (sin `error`).
   */
  afterAdd?: (
    cart: StoreCart,
    productId: number,
    quantity: number,
  ) => Promise<StoreCart>;
}

export function CartStoreProvider({
  children,
  onItemAdded,
  afterAdd,
}: CartStoreProviderProps) {
  const [cart, setCart] = useState<StoreCart | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [failure, setFailure] = useState<unknown>(null);
  const [readError, setReadError] = useState<Error | null>(null);
  const requestIdRef = useRef(0);
  const onItemAddedRef = useRef(onItemAdded);
  const afterAddRef = useRef(afterAdd);
  useEffect(() => {
    onItemAddedRef.current = onItemAdded;
    afterAddRef.current = afterAdd;
  }, [onItemAdded, afterAdd]);

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

  const run = useCallback(
    async (action: () => Promise<StoreCart>, options?: RunOptions) => {
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
          if (!options?.silent)
            setError(e instanceof Error ? e.message : "Error en el carrito.");
          setFailure(e);
        }
        return false;
      } finally {
        if (isLatest()) setLoading(false);
      }
    },
    [],
  );

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
          const requestId = requestIdRef.current;
          added.cart = await cartApi.addItem(id, quantity, extensions);
          const adjust = afterAddRef.current;
          if (adjust) {
            try {
              added.cart = await adjust(added.cart, id, quantity);
            } catch (e) {
              // El alta ya la aceptó el servidor: vale con su carrito; el ajuste se informa.
              if (requestId === requestIdRef.current) setFailure(e);
            }
          }
          return added.cart;
        });
        if (ok && added.cart) onItemAddedRef.current?.(added.cart, id, quantity);
        return ok;
      },
      updateItem: (key, quantity) => run(() => cartApi.updateItem(key, quantity)),
      removeItem: (key) => run(() => cartApi.removeItem(key)),
      clear: () => run(() => cartApi.clear()),
      refresh,
      applyCoupon: (code) => run(() => cartApi.applyCoupon(code)),
      removeCoupon: (code) => run(() => cartApi.removeCoupon(code)),
      selectShippingRate: (pkg, rate) =>
        run(() => cartApi.selectShippingRate(pkg, rate)),
      setShippingDestination: (destination) =>
        run(() => cartApi.setShippingDestination(destination)),
      run,
    }),
    [cart, loading, error, failure, readError, refresh, run],
  );

  return (
    <CartStoreContext.Provider value={value}>{children}</CartStoreContext.Provider>
  );
}
