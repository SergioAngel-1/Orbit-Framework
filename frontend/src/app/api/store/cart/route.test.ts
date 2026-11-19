import { beforeEach, describe, expect, it, vi } from "vitest";
import { rateLimit } from "@/lib/security/rate-limit";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { getSession } from "@/lib/auth/session";
import { ensureCartDestination } from "@/lib/woocommerce/cart-destination";
import { resetAnonymousCartCache } from "@/lib/woocommerce/anonymous-cart";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { DELETE, GET } from "./route";

// ============================================================================
//  GET /api/store/cart — rate-limit de la lectura.
//
//  La lectura no pasa por `guardMutation` (no es una escritura) y cada una acaba
//  en la Store API de WordPress, sin caché: PHP pesado que ocupa un worker de
//  Apache. Un navegador en bucle la pidió ~70 veces por minuto y dejó a WordPress
//  sin workers para el resto del sitio.
// ============================================================================

vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  readCartToken: vi.fn(),
  writeCartToken: vi.fn(),
}));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/cart-destination", () => ({
  ensureCartDestination: vi.fn(async () => null),
}));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn(), debug: vi.fn() },
}));

const OK = { success: true, remaining: 10, retryAfter: 0, limit: 30 };
const CART = { items: [], items_count: 0, totals: { total_price: "0" } };
/** Forma canónica que la Store API da a un carrito vacío sin token. */
const EMPTY_CART = {
  items: [],
  items_count: 0,
  coupons: [],
  shipping_rates: [],
  needs_payment: false,
  needs_shipping: false,
  payment_methods: [],
  totals: { total_price: "0", currency_code: "EUR", currency_minor_unit: 2 },
};

const request = (ip = "203.0.113.9") =>
  new Request("https://tienda.example/api/store/cart", {
    headers: { "x-forwarded-for": ip },
  });

beforeEach(() => {
  vi.clearAllMocks();
  // `clearAllMocks` no descarta las respuestas `...Once` que un test dejó sin
  // consumir: sin este reset, un test en rojo contaminaría al siguiente.
  vi.mocked(storeFetch).mockReset();
  // La copia del carrito vacío vive en memoria del proceso: cada test arranca limpio.
  resetAnonymousCartCache();
  vi.mocked(rateLimit).mockResolvedValue(OK);
  vi.mocked(readCartToken).mockResolvedValue(undefined as never);
  vi.mocked(storeFetch).mockResolvedValue({ data: CART, cartToken: "nuevo" } as never);
  vi.mocked(getSession).mockResolvedValue(null);
  vi.mocked(ensureCartDestination).mockResolvedValue(null);
});

describe("GET /api/store/cart", () => {
  it("con cookie de carrito, lee la Store API y persiste el token devuelto", async () => {
    vi.mocked(readCartToken).mockResolvedValue("cookie-del-carrito" as never);

    const res = await GET(request());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(storeFetch).toHaveBeenCalledWith(
      "/cart",
      expect.objectContaining({ cartToken: "cookie-del-carrito" }),
    );
    expect(writeCartToken).toHaveBeenCalledWith("nuevo");
  });

  it("la respuesta es privada y no se guarda en ninguna caché", async () => {
    const res = await GET(request());

    expect(res.headers.get("Cache-Control")).toBe("private, no-store");
  });

  it("superar el límite da 429 con Retry-After ANTES de tocar WordPress", async () => {
    vi.mocked(rateLimit).mockResolvedValue({ ...OK, success: false, retryAfter: 17 });

    const res = await GET(request());

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("17");
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("sin carrito, la clave es por IP y «anon»", async () => {
    await GET(request("198.51.100.4"));

    expect(rateLimit).toHaveBeenCalledWith("cart_read:198.51.100.4:anon", 30, 60);
  });

  it("con carrito, la clave lleva el token HASHEADO: la credencial no queda en claro en Redis", async () => {
    vi.mocked(readCartToken).mockResolvedValue("TOKEN-SECRETO-DEL-CARRITO" as never);

    await GET(request("198.51.100.4"));

    const key = vi.mocked(rateLimit).mock.calls[0]![0];
    expect(key).toMatch(/^cart_read:198\.51\.100\.4:[0-9a-f]{16}$/);
    expect(key).not.toContain("TOKEN-SECRETO-DEL-CARRITO");
  });

  it("dos carritos distintos tras la misma IP (CGNAT) no comparten límite", async () => {
    vi.mocked(readCartToken).mockResolvedValueOnce("carrito-A" as never);
    await GET(request());
    vi.mocked(readCartToken).mockResolvedValueOnce("carrito-B" as never);
    await GET(request());

    const [a, b] = vi.mocked(rateLimit).mock.calls.map((c) => c[0]);
    expect(a).not.toBe(b);
  });
});

describe("GET /api/store/cart — sesión y existencias", () => {
  it("con sesión, el JWT va a la Store API: el carrito es el de la cuenta", async () => {
    vi.mocked(getSession).mockResolvedValue({
      token: "jwt-del-cliente",
      userId: "7",
    } as never);

    await GET(request());

    expect(storeFetch).toHaveBeenCalledWith(
      "/cart",
      expect.objectContaining({ authToken: "jwt-del-cliente" }),
    );
  });

  it("sin sesión pero con cookie de carrito, no manda ningún JWT", async () => {
    vi.mocked(readCartToken).mockResolvedValue("cookie-del-carrito" as never);

    await GET(request());

    expect(vi.mocked(storeFetch).mock.calls[0]![1]).toMatchObject({ authToken: null });
  });

  it("una línea sin existencias se quita antes de devolver el carrito", async () => {
    vi.mocked(readCartToken).mockResolvedValue("cookie-del-carrito" as never);
    const agotada = {
      key: "k1",
      id: 5,
      quantity: 1,
      name: "Agotado",
      quantity_limits: { minimum: 1, maximum: 0, multiple_of: 1, editable: true },
    };
    vi.mocked(storeFetch)
      .mockResolvedValueOnce({
        data: { ...CART, items: [agotada], items_count: 1 },
        cartToken: "t1",
      } as never)
      .mockResolvedValueOnce({ data: CART, cartToken: "t2" } as never);

    const res = await GET(request());

    expect(storeFetch).toHaveBeenLastCalledWith(
      "/cart/remove-item",
      expect.objectContaining({ method: "POST", body: { key: "k1" } }),
    );
    expect((await res.json()).items).toEqual([]);
    expect(writeCartToken).toHaveBeenCalledWith("t2");
  });
});

// ============================================================================
//  Visitante sin carrito ni sesión.
//
//  El `CartProvider` pide el carrito a todo el que abre la web, también a los
//  robots. Antes cada lectura abría una sesión de WooCommerce (un worker de PHP
//  por visitante); ahora se sirve la forma vacía cacheada en memoria y SIN
//  `Set-Cookie`, hasta que el visitante añade su primer producto.
// ============================================================================

describe("GET /api/store/cart — visitante sin carrito ni sesión", () => {
  it("devuelve el carrito vacío sin abrir sesión en Woo ni poner cookie", async () => {
    vi.mocked(storeFetch).mockResolvedValue({
      data: EMPTY_CART,
      cartToken: "sesion-nueva",
    } as never);

    const res = await GET(request());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(EMPTY_CART);
    // Sin token: la lectura no abre sesión de WooCommerce.
    expect(storeFetch).toHaveBeenCalledWith("/cart");
    expect(writeCartToken).not.toHaveBeenCalled();
    expect(res.headers.get("set-cookie")).toBeNull();
  });

  it("tras la primera lectura no vuelve a llamar a WordPress en cada visita", async () => {
    vi.mocked(storeFetch).mockResolvedValue({
      data: EMPTY_CART,
      cartToken: "sesion-nueva",
    } as never);

    await GET(request("198.51.100.1"));
    await GET(request("198.51.100.2"));
    await GET(request("198.51.100.3"));

    expect(storeFetch).toHaveBeenCalledTimes(1);
    expect(writeCartToken).not.toHaveBeenCalled();
  });

  it("el rate-limit sigue corriendo antes de la copia en memoria", async () => {
    vi.mocked(rateLimit).mockResolvedValue({ ...OK, success: false, retryAfter: 9 });

    const res = await GET(request());

    expect(res.status).toBe(429);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("si WordPress falla y no hay copia, responde el error de siempre", async () => {
    vi.mocked(storeFetch).mockRejectedValueOnce(
      new WooCommerceError("Store API caída", 503),
    );

    const res = await GET(request());

    expect(res.status).toBe(502);
    expect(await res.json()).toEqual({
      error: "El servicio de tienda no está disponible.",
    });
    expect(writeCartToken).not.toHaveBeenCalled();
  });

  it("si WordPress falla y hay copia, sirve la copia sin romper la cabecera", async () => {
    vi.useFakeTimers();
    try {
      vi.setSystemTime(new Date("2026-01-01T00:00:00Z"));
      vi.mocked(storeFetch).mockResolvedValue({
        data: EMPTY_CART,
        cartToken: "sesion-nueva",
      } as never);
      await GET(request());

      vi.setSystemTime(new Date("2026-01-01T00:06:00Z"));
      vi.mocked(storeFetch).mockRejectedValueOnce(
        new WooCommerceError("Store API caída", 503),
      );

      const res = await GET(request());

      expect(res.status).toBe(200);
      expect(await res.json()).toEqual(EMPTY_CART);
      expect(writeCartToken).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });
});

// ============================================================================
//  DELETE /api/store/cart — vaciar el carrito.
//
//  La Store API de WooCommerce responde al vaciado con una LISTA vacía, no con el
//  carrito: darla por buena hacía que el handler devolviera 500 y el cajón se
//  quedara con las líneas viejas. Tras el vaciado se relee el carrito.
// ============================================================================

describe("DELETE /api/store/cart", () => {
  it("si el vaciado devuelve una lista vacía, relee el carrito y responde 200 con él", async () => {
    vi.mocked(storeFetch)
      .mockResolvedValueOnce({ data: [], cartToken: "token-vaciado" } as never)
      .mockResolvedValueOnce({ data: CART, cartToken: "token-releido" } as never);

    const res = await DELETE(request());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(storeFetch).toHaveBeenNthCalledWith(
      1,
      "/cart/items",
      expect.objectContaining({ method: "DELETE" }),
    );
    expect(storeFetch).toHaveBeenNthCalledWith(
      2,
      "/cart",
      expect.objectContaining({ cartToken: "token-vaciado" }),
    );
    expect(writeCartToken).toHaveBeenCalledWith("token-releido");
    expect(res.headers.get("Cache-Control")).toBe("private, no-store");
  });

  it("si el vaciado ya devuelve un carrito, no hace una segunda lectura", async () => {
    vi.mocked(storeFetch).mockResolvedValueOnce({
      data: CART,
      cartToken: "token-directo",
    } as never);

    const res = await DELETE(request());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(storeFetch).toHaveBeenCalledTimes(1);
    expect(writeCartToken).toHaveBeenCalledWith("token-directo");
  });

  it("si la relectura falla, responde con el error normalizado (no un 200 falso)", async () => {
    vi.mocked(storeFetch)
      .mockResolvedValueOnce({ data: [], cartToken: "token-vaciado" } as never)
      .mockRejectedValueOnce(new WooCommerceError("Store API caída", 503));

    const res = await DELETE(request());

    expect(res.status).toBe(502);
    expect(await res.json()).toEqual({
      error: "El servicio de tienda no está disponible.",
    });
    expect(writeCartToken).not.toHaveBeenCalled();
  });

  it("pasa la sesión a las dos llamadas: el carrito es el de la cuenta", async () => {
    vi.mocked(getSession).mockResolvedValue({
      token: "jwt-del-cliente",
      userId: "7",
    } as never);
    vi.mocked(storeFetch)
      .mockResolvedValueOnce({ data: [], cartToken: "token-vaciado" } as never)
      .mockResolvedValueOnce({ data: CART, cartToken: "token-releido" } as never);

    await DELETE(request());

    for (const call of vi.mocked(storeFetch).mock.calls) {
      expect(call[1]).toMatchObject({ authToken: "jwt-del-cliente" });
    }
  });
});

describe("GET /api/store/cart — destino de envío (módulo shipping)", () => {
  const WITH_CITY = { ...CART, shipping_address: { city: "Madrid", country: "ES" } };

  it("pasa a ensureCartDestination el carrito reconciliado, la cuenta y las credenciales", async () => {
    vi.mocked(getSession).mockResolvedValue({ token: "jwt", userId: "7" } as never);

    await GET(request());

    expect(ensureCartDestination).toHaveBeenCalledWith(
      expect.objectContaining({ items: [] }),
      "7",
      { cartToken: "nuevo", authToken: "jwt" },
    );
  });

  it("si recupera la ciudad de la libreta, devuelve ese carrito y su cookie", async () => {
    vi.mocked(getSession).mockResolvedValue({ token: "jwt", userId: "7" } as never);
    vi.mocked(ensureCartDestination).mockResolvedValue({
      data: WITH_CITY,
      cartToken: "con-ciudad",
    } as never);

    const res = await GET(request());

    expect(await res.json()).toEqual(WITH_CITY);
    expect(writeCartToken).toHaveBeenCalledWith("con-ciudad");
  });

  it("si no hay nada que fijar, devuelve el carrito tal cual", async () => {
    vi.mocked(getSession).mockResolvedValue({ token: "jwt", userId: "7" } as never);
    vi.mocked(ensureCartDestination).mockResolvedValue(null);

    const res = await GET(request());

    expect(await res.json()).toEqual(CART);
    expect(writeCartToken).toHaveBeenCalledWith("nuevo");
  });
});
