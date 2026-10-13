// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import type { StoreCart } from "@/types/woocommerce";
import { FreeShippingNotice } from "./free-shipping-notice";

vi.mock("@/components/ui/currency-context", () => ({
  useFormatStoreAmount: () => (amount: string) => `${Number(amount) / 100} €`,
}));
vi.mock("./cart-context", () => ({ useCart: () => ({ closeDrawer: vi.fn() }) }));
vi.mock("@/i18n/navigation", () => ({
  Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));

afterEach(cleanup);

const STATE = {
  enabled: true,
  threshold: "5000",
  eligible_subtotal: "3000",
  remaining: "2000",
  qualifies: false,
  city: "eligible",
  all_cities: true,
  scoped: false,
};

function cartWith(state: Record<string, unknown> | null, needsShipping = true) {
  return {
    needs_shipping: needsShipping,
    totals: { currency_minor_unit: 2, currency_code: "EUR" },
    extensions: state ? { hwe_free_shipping: state } : {},
  } as unknown as StoreCart;
}

function view(cart: StoreCart | null) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <FreeShippingNotice cart={cart} />
    </NextIntlClientProvider>,
  );
}

describe("FreeShippingNotice", () => {
  it("dice lo que falta para el mínimo, con barra de progreso", () => {
    view(cartWith(STATE));

    expect(screen.getByText("Te faltan 20 € para el envío gratis")).not.toBeNull();
    expect(screen.getByRole("progressbar").getAttribute("aria-valuenow")).toBe("60");
  });

  it("cuando ya no se paga envío lo celebra sin barra", () => {
    view(cartWith({ ...STATE, qualifies: true, remaining: "0" }));

    expect(screen.getByText("¡Tienes envío gratis!")).not.toBeNull();
    expect(screen.queryByRole("progressbar")).toBeNull();
  });

  it("alcanzado el mínimo sin ciudad, pide la ciudad de entrega", () => {
    view(cartWith({ ...STATE, remaining: "0", city: "unknown" }));

    expect(screen.getByText(/se confirma con tu ciudad de entrega/)).not.toBeNull();
  });

  it("con alcance, ofrece el «?» que explica qué productos suman", () => {
    view(cartWith({ ...STATE, scoped: true }));

    expect(
      screen.getByRole("button", {
        name: "Ver qué productos suman para el envío gratis",
      }),
    ).not.toBeNull();
    expect(screen.getByText(/Suman los productos seleccionados/)).not.toBeNull();
  });

  it.each([
    ["sin carrito", null],
    ["sin la extensión (módulo apagado)", cartWith(null)],
    ["regla apagada", cartWith({ ...STATE, enabled: false })],
    ["carrito que no se envía", cartWith(STATE, false)],
    ["una extensión rota", cartWith({ enabled: "sí" })],
  ])("no pinta nada: %s", (_name, cart) => {
    const { container } = view(cart);

    expect(container.innerHTML).toBe("");
  });
});
