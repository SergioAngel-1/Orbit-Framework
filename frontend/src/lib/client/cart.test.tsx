// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, renderHook } from "@testing-library/react";
import { cartApi } from "@/lib/client/store-api";
import type { StoreCart } from "@/types/woocommerce";
import type { ReactNode } from "react";
import { CartStoreProvider, useCartStore } from "./cart";

// ============================================================================
//  Estado del carrito del núcleo (`useCartStore`), sin UI. Las mutaciones
//  concurrentes pueden resolverse FUERA de orden (cambiar la cantidad dos veces
//  seguidas): la respuesta vieja no puede pisar a la nueva, o la vista enseñaba
//  una cantidad que ya no es la del servidor.
// ============================================================================

vi.mock("@/lib/client/store-api", () => ({
  cartApi: {
    get: vi.fn(),
    updateItem: vi.fn(),
    addItem: vi.fn(),
    removeItem: vi.fn(),
    clear: vi.fn(),
    applyCoupon: vi.fn(),
    removeCoupon: vi.fn(),
    selectShippingRate: vi.fn(),
  },
}));

const cartWith = (count: number) =>
  ({ items: [], items_count: count }) as unknown as StoreCart;

/** Promesa que se resuelve a mano, para ordenar las respuestas a voluntad. */
function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => (resolve = r));
  return { promise, resolve };
}

let hook: ReturnType<typeof mountHook>;
const onItemAdded = vi.fn();
function mountHook() {
  const wrapper = ({ children }: { children: ReactNode }) => (
    <CartStoreProvider onItemAdded={onItemAdded}>{children}</CartStoreProvider>
  );
  return renderHook(() => useCartStore(), { wrapper });
}
/** El estado más reciente del contexto. */
const current = new Proxy({} as ReturnType<typeof useCartStore>, {
  get: (_target, key) =>
    hook.result.current[key as keyof ReturnType<typeof useCartStore>],
});

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(cartApi.get).mockResolvedValue(cartWith(0));
});
afterEach(cleanup);

async function mount() {
  await act(async () => {
    hook = mountHook();
  });
}

describe("CartStoreProvider", () => {
  it("una respuesta vieja que llega tarde no pisa a la más reciente", async () => {
    await mount();
    const first = deferred<StoreCart>();
    const second = deferred<StoreCart>();
    vi.mocked(cartApi.updateItem)
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);

    let a!: Promise<void>;
    let b!: Promise<void>;
    await act(async () => {
      a = current.updateItem("k", 2);
      b = current.updateItem("k", 5);
    });
    await act(async () => {
      second.resolve(cartWith(5));
      await b;
    });
    await act(async () => {
      first.resolve(cartWith(2));
      await a;
    });

    expect(current.count).toBe(5);
  });

  it("si la mutación más reciente falla, la respuesta vieja tampoco se aplica", async () => {
    await mount();
    const first = deferred<StoreCart>();
    vi.mocked(cartApi.updateItem)
      .mockReturnValueOnce(first.promise)
      .mockRejectedValueOnce(new Error("sin stock"));

    let a!: Promise<void>;
    await act(async () => {
      a = current.updateItem("k", 2);
      await current.updateItem("k", 99);
    });
    await act(async () => {
      first.resolve(cartWith(2));
      await a;
    });

    expect(current.count).toBe(0);
    expect(current.error).toBe("sin stock");
  });

  it("la carga termina con la mutación más reciente, aunque una vieja siga en vuelo", async () => {
    await mount();
    const first = deferred<StoreCart>();
    const second = deferred<StoreCart>();
    vi.mocked(cartApi.updateItem)
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);

    let b!: Promise<void>;
    await act(async () => {
      void current.updateItem("k", 2);
      b = current.updateItem("k", 5);
    });
    expect(current.loading).toBe(true);

    await act(async () => {
      second.resolve(cartWith(5));
      await b;
    });

    expect(current.loading).toBe(false);
    first.resolve(cartWith(2));
  });
});

describe("CartStoreProvider: ganchos de la vista", () => {
  it("onItemAdded recibe el carrito del servidor solo si añadir salió bien", async () => {
    await mount();
    vi.mocked(cartApi.addItem).mockResolvedValueOnce(cartWith(2));
    await act(async () => {
      await current.addItem(3, 2);
    });
    expect(onItemAdded).toHaveBeenCalledWith(cartWith(2), 3, 2);

    onItemAdded.mockClear();
    vi.mocked(cartApi.addItem).mockRejectedValueOnce(new Error("sin stock"));
    await act(async () => {
      await current.addItem(3, 1);
    });
    expect(onItemAdded).not.toHaveBeenCalled();
    expect(current.error).toBe("sin stock");
  });

  it("run aplica una mutación propia de la instancia y dice si salió bien", async () => {
    await mount();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await current.run(async () => cartWith(4));
    });
    expect(ok).toBe(true);
    expect(current.count).toBe(4);

    await act(async () => {
      ok = await current.run(async () => {
        throw new Error("Canje no válido.");
      });
    });
    expect(ok).toBe(false);
    expect(current.error).toBe("Canje no válido.");
  });

  it("fuera del proveedor avisa con un error claro", () => {
    expect(() => renderHook(() => useCartStore())).toThrow(/CartStoreProvider/);
  });
});
