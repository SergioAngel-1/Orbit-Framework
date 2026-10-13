// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import type { PaymentChoice } from "@/lib/payments/checkout-options";
import { CheckoutForm } from "./checkout-form";

// El formulario ofrece los medios de pago que el Control Center activa y manda
// al BFF el gateway del elegido: el servidor rechaza cualquier otro. Con link
// por WhatsApp o efectivo no hay cobro en línea.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));
vi.mock("@/components/cart/cart-context", () => ({
  useCart: () => ({
    cart: {
      items: [{ key: "a", name: "Producto", quantity: 1 }],
      coupons: [],
      needs_shipping: false,
      shipping_rates: [],
      totals: {
        total_items: "1000",
        total_discount: "0",
        total_shipping: "0",
        total_price: "1000",
        currency_minor_unit: 2,
        currency_code: "EUR",
      },
    },
    loading: false,
    refresh: vi.fn(async () => undefined),
    applyCoupon: vi.fn(),
    removeCoupon: vi.fn(),
    selectShippingRate: vi.fn(),
  }),
}));
vi.mock("@/components/ui/currency-context", () => ({
  useFormatStoreAmount: () => (amount: string) => `${amount} €`,
}));

type Props = Parameters<typeof CheckoutForm>[0];

function renderForm(props: Props = {}) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <CheckoutForm {...props} />
    </NextIntlClientProvider>,
  );
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

/** Respuestas del BFF: pedido creado y, opcionalmente, el inicio del cobro. */
function bff(payment: Response = json({ mode: "none" })) {
  vi.mocked(csrfFetch).mockImplementation(async (url: string) =>
    url === "/api/store/checkout"
      ? json({ order_id: 42, status: "pending" }, 201)
      : payment,
  );
}

function checkoutBody(): { payment_method: string } {
  const call = vi
    .mocked(csrfFetch)
    .mock.calls.find(([url]) => url === "/api/store/checkout");
  return (call?.[1] as { body: { payment_method: string } }).body;
}

const paymentCalls = () =>
  vi.mocked(csrfFetch).mock.calls.filter(([url]) => url === "/api/payments/create");

async function submit(container: HTMLElement) {
  fireEvent.submit(container.querySelector("form")!);
}

const ALL: PaymentChoice[] = ["online", "whatsapp", "cash"];

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(window, "location", {
    value: {
      href: "https://tienda.example/checkout",
      origin: "https://tienda.example",
    },
    writable: true,
  });
});
afterEach(cleanup);

describe("selector de medio de pago", () => {
  it("con un solo medio no pinta el selector y pide el pago en línea", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ["online"] });

    expect(screen.queryByRole("group", { name: es.checkout.paymentMethod })).toBeNull();
    await submit(container);

    await waitFor(() => expect(checkoutBody().payment_method).toBe("hwe_offline"));
  });

  it("con varios medios los pinta como radios, con el primero marcado", () => {
    renderForm({ paymentChoices: ALL });

    const radios = screen.getAllByRole("radio", { checked: true });
    expect(screen.getByRole("group", { name: es.checkout.paymentMethod })).toBeTruthy();
    expect(screen.getByLabelText(new RegExp(es.checkout.paymentWhatsapp))).toBeTruthy();
    expect(screen.getByLabelText(new RegExp(es.checkout.paymentCash))).toBeTruthy();
    expect(radios).toHaveLength(1);
    expect((radios[0] as HTMLInputElement).value).toBe("online");
  });

  it("si el medio elegido ya no está activo, cae al primero en vez de enviarlo", async () => {
    bff();
    const view = renderForm({ paymentChoices: ALL });
    fireEvent.click(screen.getByLabelText(new RegExp(es.checkout.paymentCash)));

    view.rerender(
      <NextIntlClientProvider locale="es" messages={es}>
        <CheckoutForm paymentChoices={["online", "whatsapp"]} />
      </NextIntlClientProvider>,
    );
    await submit(view.container);

    await waitFor(() => expect(checkoutBody().payment_method).toBe("hwe_offline"));
  });
});

describe("cada medio crea el pedido con su gateway", () => {
  it("link por WhatsApp: hwe_whatsapp_link, sin cobro en línea y con aviso", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ALL });
    fireEvent.click(screen.getByLabelText(new RegExp(es.checkout.paymentWhatsapp)));

    await submit(container);

    await waitFor(() =>
      expect(screen.getByText(es.checkout.resultWhatsapp)).toBeTruthy(),
    );
    expect(checkoutBody().payment_method).toBe("hwe_whatsapp_link");
    expect(paymentCalls()).toHaveLength(0);
  });

  it("efectivo: cod, sin cobro en línea y con aviso", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ALL });
    fireEvent.click(screen.getByLabelText(new RegExp(es.checkout.paymentCash)));

    await submit(container);

    await waitFor(() => expect(screen.getByText(es.checkout.resultCash)).toBeTruthy());
    expect(checkoutBody().payment_method).toBe("cod");
    expect(paymentCalls()).toHaveLength(0);
  });

  it("en línea: inicia el cobro y lleva al cliente a la pasarela", async () => {
    bff(json({ mode: "redirect", redirectUrl: "https://pay.test/x" }));
    const { container } = renderForm({ paymentChoices: ALL });

    await submit(container);

    await waitFor(() => expect(window.location.href).toBe("https://pay.test/x"));
    expect(paymentCalls()).toHaveLength(1);
  });

  it("en línea sin arrancar el cobro: avisa en vez de dar la compra por hecha", async () => {
    bff(json({ error: "sin pasarela" }, 503));
    const { container } = renderForm({ paymentChoices: ["online"] });

    await submit(container);

    await waitFor(() =>
      expect(screen.getByRole("alert").textContent).toBe(es.checkout.paymentNotStarted),
    );
  });
});

describe("modo de pruebas", () => {
  it("avisa solo cuando se paga en línea con una pasarela de pruebas", () => {
    renderForm({ paymentChoices: ALL, gatewaySandbox: true });

    expect(screen.getByRole("status").textContent).toBe(es.checkout.sandboxNotice);

    fireEvent.click(screen.getByLabelText(new RegExp(es.checkout.paymentCash)));

    expect(screen.queryByRole("status")).toBeNull();
  });

  it("no avisa con credenciales reales", () => {
    renderForm({ paymentChoices: ALL, gatewaySandbox: false });

    expect(screen.queryByRole("status")).toBeNull();
  });
});
