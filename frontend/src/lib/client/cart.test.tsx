// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, renderHook } from "@testing-library/react";
import { cartApi } from "@/lib/client/store-api";
import type { StoreCart } from "@/types/woocommerce";
import type { ReactNode } from "react";
import { CartStoreProvider, useCartStore, type CartStoreProviderProps } from "./cart";

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
let afterAdd: CartStoreProviderProps["afterAdd"];
function mountHook() {
  const wrapper = ({ children }: { children: ReactNode }) => (
    <CartStoreProvider onItemAdded={onItemAdded} afterAdd={afterAdd}>
      {children}
    </CartStoreProvider>
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
  afterAdd = undefined;
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

    let a!: Promise<boolean>;
    let b!: Promise<boolean>;
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

    let a!: Promise<boolean>;
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

    let b!: Promise<boolean>;
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

  it("run conserva lo que lanzó la acción en `failure`, no solo su texto", async () => {
    await mount();
    // Un fallo estructurado de la instancia (no tiene por qué ser un Error).
    const failure = { kind: "coins", missing: 120 };
    await act(async () => {
      await current.run(async () => {
        throw failure;
      });
    });
    expect(current.failure).toBe(failure);
    expect(current.error).toBe("Error en el carrito.");

    await act(async () => {
      await current.run(async () => cartWith(1));
    });
    expect(current.failure).toBeNull();
    expect(current.error).toBeNull();
  });

  it("un fallo al leer el carrito se expone en `readError` y se limpia al leer bien", async () => {
    vi.mocked(cartApi.get).mockRejectedValueOnce(new Error("Error 503"));
    await mount();
    expect(current.loading).toBe(false);
    expect(current.cart).toBeNull();
    expect(current.readError?.message).toBe("Error 503");

    vi.mocked(cartApi.get).mockRejectedValueOnce(new Error("Error 502"));
    await act(async () => {
      await current.refresh();
    });
    expect(current.readError?.message).toBe("Error 502");

    await act(async () => {
      await current.refresh();
    });
    expect(current.readError).toBeNull();
    expect(current.count).toBe(0);
  });

  it("cada mutación dice si el servidor la aceptó", async () => {
    await mount();
    vi.mocked(cartApi.addItem).mockResolvedValueOnce(cartWith(1));
    vi.mocked(cartApi.updateItem).mockRejectedValueOnce(new Error("sin stock"));
    vi.mocked(cartApi.applyCoupon).mockResolvedValueOnce(cartWith(1));
    vi.mocked(cartApi.removeItem).mockRejectedValueOnce(new Error("no"));

    const results: boolean[] = [];
    await act(async () => {
      results.push(await current.addItem(3));
      results.push(await current.updateItem("k", 9));
      results.push(await current.applyCoupon("X"));
      results.push(await current.removeItem("k"));
    });
    expect(results).toEqual([true, false, true, false]);
  });

  it("afterAdd ajusta el carrito dentro del mismo alta, antes de onItemAdded", async () => {
    afterAdd = vi.fn(async (cart: StoreCart) => ({ ...cart, items_count: 7 }));
    await mount();
    vi.mocked(cartApi.addItem).mockResolvedValueOnce(cartWith(1));

    let ok: boolean | undefined;
    await act(async () => {
      ok = await current.addItem(3, 1);
    });

    expect(ok).toBe(true);
    expect(afterAdd).toHaveBeenCalledWith(cartWith(1), 3, 1);
    expect(current.count).toBe(7);
    expect(onItemAdded).toHaveBeenCalledWith(
      expect.objectContaining({ items_count: 7 }),
      3,
      1,
    );
  });

  it("si afterAdd falla, el alta vale con el carrito sin tocar", async () => {
    afterAdd = vi.fn(async () => {
      throw new Error("destino no disponible");
    });
    await mount();
    vi.mocked(cartApi.addItem).mockResolvedValueOnce(cartWith(2));

    let ok: boolean | undefined;
    await act(async () => {
      ok = await current.addItem(3, 2);
    });

    expect(ok).toBe(true);
    expect(current.count).toBe(2);
    expect(current.error).toBeNull();
    expect((current.failure as Error).message).toBe("destino no disponible");
    expect(onItemAdded).toHaveBeenCalledWith(cartWith(2), 3, 2);
  });

  it("run con { silent: true } deja el fallo en failure sin pintar error", async () => {
    await mount();
    const failure = new Error("Coins insuficientes");
    let ok: boolean | undefined;
    await act(async () => {
      ok = await current.run(
        async () => {
          throw failure;
        },
        { silent: true },
      );
    });
    expect(ok).toBe(false);
    expect(current.failure).toBe(failure);
    expect(current.error).toBeNull();
  });

  it("fuera del proveedor avisa con un error claro", () => {
    expect(() => renderHook(() => useCartStore())).toThrow(/CartStoreProvider/);
  });
});
