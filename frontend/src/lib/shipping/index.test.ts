import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logReadFailure } from "@/lib/observability/read-failure";
import { EMPTY_FREE_SHIPPING_SCOPE } from "./free-shipping-scope";
import { getFreeShippingScope, getShippingMethods } from "./index";

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));
vi.mock("@/lib/http/fetch-retry", () => ({
  fetchWithRetry: async (request: () => Promise<Response>) => request(),
}));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
  vi.mocked(logReadFailure).mockReset();
});

describe("getShippingMethods", () => {
  it("lee los métodos activos con el tag ISR `shipping`", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json([{ id: 3, name: "Seguro", added_value: 5 }]),
    );

    expect(await getShippingMethods()).toEqual([
      { id: 3, name: "Seguro", addedValue: 5 },
    ]);
    expect(wpFetch).toHaveBeenCalledWith("/wp-json/hwe-shipping/v1/methods", {
      next: { revalidate: 3600, tags: ["shipping"] },
    });
  });

  it("`fresh` se salta la caché: el importe cobrado es el vigente", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json([]));

    await getShippingMethods({ fresh: true });

    expect(wpFetch).toHaveBeenCalledWith("/wp-json/hwe-shipping/v1/methods", {
      cache: "no-store",
    });
  });

  it("con el módulo apagado no llama a WordPress", async () => {
    expect(await getShippingMethods({ enabled: false })).toEqual([]);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("si WordPress falla, no se ofrecen extras y el fallo queda registrado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({}, 503));
    expect(await getShippingMethods()).toEqual([]);

    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));
    expect(await getShippingMethods()).toEqual([]);
    expect(logReadFailure).toHaveBeenCalledTimes(2);
  });
});

describe("getFreeShippingScope", () => {
  it("lee el alcance con los tags `shipping` y `site-config`, en el idioma pedido", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({
        categories: [
          {
            slug: "velas",
            name: "Velas",
            translations: { en: { name: "Candles", slug: "" } },
          },
        ],
        products: [],
        cities: ["Madrid"],
      }),
    );

    const scope = await getFreeShippingScope("en");

    expect(scope.categories[0].name).toBe("Candles");
    expect(scope.cities).toEqual(["Madrid"]);
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe-shipping/v1/free-shipping/scope",
      {
        next: { revalidate: 3600, tags: ["shipping", "site-config"] },
      },
    );
  });

  it("con el módulo apagado devuelve el alcance vacío sin llamar a WordPress", async () => {
    expect(await getFreeShippingScope("es", { modules: {} as never })).toBe(
      EMPTY_FREE_SHIPPING_SCOPE,
    );
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("encendido lee; si WordPress falla, alcance vacío y registrado", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));

    expect(
      await getFreeShippingScope("es", { modules: { shipping: true } as never }),
    ).toBe(EMPTY_FREE_SHIPPING_SCOPE);
    expect(logReadFailure).toHaveBeenCalledWith(
      "shipping.scope_failed",
      expect.any(Error),
      expect.anything(),
    );
  });
});
