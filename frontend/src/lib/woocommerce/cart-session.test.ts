import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import {
  readCartToken,
  writeCartToken,
  clearCartToken,
} from "@/lib/woocommerce/cart-cookie";
import {
  pushCartDestination,
  readDestinationAddresses,
} from "@/lib/woocommerce/cart-destination";
import { syncAuthenticatedCart } from "./cart-session";

// Tras abrir sesión el carrito de la cuenta prevalece y, con el id de la cuenta y el módulo
// `shipping`, se le fija el destino de la dirección predeterminada.

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  readCartToken: vi.fn(),
  writeCartToken: vi.fn(async () => undefined),
  clearCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/woocommerce/cart-destination", () => ({
  readDestinationAddresses: vi.fn(async () => null),
  pushCartDestination: vi.fn(async () => null),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const CART = { items: [], items_count: 0, totals: {} };
const ADDRESSES = [{ id: "a1", city: "Bogotá", is_default: true }];

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(readCartToken).mockResolvedValue("invitado" as never);
  vi.mocked(storeFetch).mockResolvedValue({
    data: CART,
    cartToken: "de-la-cuenta",
  } as never);
});

describe("syncAuthenticatedCart", () => {
  it("el carrito de la cuenta prevalece sobre el del navegador", async () => {
    await syncAuthenticatedCart("jwt");

    expect(storeFetch).toHaveBeenCalledWith("/cart", { authToken: "jwt" });
    expect(writeCartToken).toHaveBeenCalledWith("de-la-cuenta");
  });

  it("sin id de la cuenta (registro) no toca el destino", async () => {
    await syncAuthenticatedCart("jwt");

    expect(readDestinationAddresses).not.toHaveBeenCalled();
    expect(pushCartDestination).not.toHaveBeenCalled();
  });

  it("con el id de la cuenta le fija el destino y se queda con la cookie del carrito resultante", async () => {
    vi.mocked(readDestinationAddresses).mockResolvedValue(ADDRESSES as never);
    vi.mocked(pushCartDestination).mockResolvedValue({
      data: CART,
      cartToken: "con-destino",
    } as never);

    await syncAuthenticatedCart("jwt", 7);

    expect(readDestinationAddresses).toHaveBeenCalledWith(7);
    expect(pushCartDestination).toHaveBeenCalledWith(7, ADDRESSES, {
      cartToken: "de-la-cuenta",
      authToken: "jwt",
    });
    expect(writeCartToken).toHaveBeenCalledWith("con-destino");
  });

  it("lee la libreta a la vez que el carrito, no después", async () => {
    let releaseCart!: () => void;
    vi.mocked(storeFetch).mockImplementation(
      () =>
        new Promise((resolve) => {
          releaseCart = () =>
            resolve({ data: CART, cartToken: "de-la-cuenta" } as never);
        }),
    );
    vi.mocked(readDestinationAddresses).mockResolvedValue(ADDRESSES as never);

    const sync = syncAuthenticatedCart("jwt", 7);
    // El carrito sigue sin responder y la libreta ya se pidió.
    await vi.waitFor(() => expect(readDestinationAddresses).toHaveBeenCalledWith(7));
    releaseCart();
    await sync;

    expect(pushCartDestination).toHaveBeenCalledOnce();
  });

  it("sin libreta (módulo apagado o WordPress sin responder) no fija destino", async () => {
    vi.mocked(readDestinationAddresses).mockResolvedValue(null);

    await syncAuthenticatedCart("jwt", 7);

    expect(pushCartDestination).not.toHaveBeenCalled();
    expect(writeCartToken).toHaveBeenCalledWith("de-la-cuenta");
  });

  it("si el destino no se pudo fijar, conserva la cookie del carrito de la cuenta", async () => {
    vi.mocked(readDestinationAddresses).mockResolvedValue(ADDRESSES as never);
    vi.mocked(pushCartDestination).mockResolvedValue(null);

    await syncAuthenticatedCart("jwt", 7);

    expect(writeCartToken).toHaveBeenCalledWith("de-la-cuenta");
    expect(clearCartToken).not.toHaveBeenCalled();
  });

  it("nunca lanza: un fallo de WordPress solo se registra", async () => {
    vi.mocked(storeFetch).mockRejectedValue(new Error("502"));

    await expect(syncAuthenticatedCart("jwt", 7)).resolves.toBeUndefined();
  });
});
