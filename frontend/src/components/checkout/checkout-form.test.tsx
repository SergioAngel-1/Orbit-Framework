// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import type { PaymentChoice } from "@/lib/payments/checkout-options";
import type { CheckoutCustomerSeed } from "@/lib/checkout/customer-seed";
import { trackEvent } from "@/components/analytics/track-event";
import { CheckoutForm } from "./checkout-form";

// El formulario ofrece los medios de pago que el Control Center activa y manda
// al BFF el gateway del elegido: el servidor rechaza cualquier otro. Con link
// por WhatsApp o efectivo no hay cobro en línea. Un envío doble no crea dos
// pedidos, y el formulario nace con los datos del cliente con sesión.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));
const tracking = vi.hoisted(() => ({ consent: false }));
vi.mock("@/components/analytics/track-event", () => ({
  hasTrackingConsent: () => tracking.consent,
  trackEvent: vi.fn(),
}));
vi.mock("@/i18n/navigation", () => ({
  getPathname: ({ href }: { href: string }) => href,
}));
// El carrito que ve el formulario; cada test puede cambiarlo.
const cartState = vi.hoisted(() => ({ cart: {} as Record<string, unknown> }));
const baseCart = () => ({
  items: [
    {
      key: "a",
      id: 3,
      name: "Producto",
      quantity: 1,
      prices: { price: "1000", currency_code: "EUR", currency_minor_unit: 2 },
    },
  ],
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
});
vi.mock("@/components/cart/cart-context", () => ({
  useCart: () => ({
    cart: cartState.cart,
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

const RETURN_URL = "https://tienda.example/checkout/return?ref=42";

const SEED: CheckoutCustomerSeed = {
  first_name: "Ana",
  last_name: "Ruiz",
  email: "ana@example.com",
  phone: "+34600111222",
  city: "Madrid",
  country: "ES",
  addresses: [
    {
      first_name: "",
      last_name: "",
      company: "",
      address_1: "Calle Mayor 1",
      address_2: "",
      city: "Valencia",
      state: "",
      postcode: "46001",
      country: "ES",
      phone: "",
      is_default: true,
      label: "Casa",
    },
  ],
};

const field = (container: HTMLElement, name: string) =>
  container.querySelector<HTMLInputElement>(`input[name="${name}"]`)!;

const checkoutCalls = () =>
  vi.mocked(csrfFetch).mock.calls.filter(([url]) => url === "/api/store/checkout");

function checkoutBodyFull(): { tracking: { consent: boolean; locale: string } } {
  return (checkoutCalls()[0]![1] as { body: { tracking: never } }).body;
}

const paymentCalls = () =>
  vi.mocked(csrfFetch).mock.calls.filter(([url]) => url === "/api/payments/create");

async function submit(container: HTMLElement) {
  fireEvent.submit(container.querySelector("form")!);
}

const ALL: PaymentChoice[] = ["online", "whatsapp", "cash"];

beforeEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
  tracking.consent = false;
  cartState.cart = baseCart();
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
  it("link por WhatsApp: hwe_whatsapp_link, sin cobro en línea y a la pantalla de «gracias»", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ALL });
    fireEvent.click(screen.getByLabelText(new RegExp(es.checkout.paymentWhatsapp)));

    await submit(container);

    await waitFor(() => expect(window.location.href).toBe(RETURN_URL));
    expect(checkoutBody().payment_method).toBe("hwe_whatsapp_link");
    expect(paymentCalls()).toHaveLength(0);
  });

  it("efectivo: cod, sin cobro en línea y a la pantalla de «gracias»", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ALL });
    fireEvent.click(screen.getByLabelText(new RegExp(es.checkout.paymentCash)));

    await submit(container);

    await waitFor(() => expect(window.location.href).toBe(RETURN_URL));
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

  it("en línea sin arrancar el cobro: a «gracias» con el aviso de reintento, no a la compra por hecha", async () => {
    bff(json({ error: "sin pasarela" }, 503));
    const { container } = renderForm({ paymentChoices: ["online"] });

    await submit(container);

    await waitFor(() => expect(window.location.href).toBe(`${RETURN_URL}&retry=1`));
  });

  it("el cobro vuelve a la pantalla de «gracias» del idioma de la página", async () => {
    bff(json({ mode: "redirect", redirectUrl: "https://pay.test/x" }));
    const { container } = renderForm({ paymentChoices: ["online"] });

    await submit(container);

    await waitFor(() => expect(paymentCalls()).toHaveLength(1));
    expect(
      (paymentCalls()[0]![1] as { body: { returnUrl: string } }).body.returnUrl,
    ).toBe(RETURN_URL);
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

describe("envío del formulario", () => {
  it("un doble envío no crea dos pedidos", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ["cash"] });

    await submit(container);
    await submit(container);

    await waitFor(() => expect(window.location.href).toBe(RETURN_URL));
    const orders = vi
      .mocked(csrfFetch)
      .mock.calls.filter(([url]) => url === "/api/store/checkout");
    expect(orders).toHaveLength(1);
  });

  it("un reintento tras un fallo reutiliza la clave de idempotencia: no duplica el pedido", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(json({ error: "caído" }, 502));
    const { container } = renderForm({ paymentChoices: ["cash"] });

    await submit(container);
    await waitFor(() => expect(screen.getByText("caído")).toBeTruthy());
    await submit(container);

    await waitFor(() => expect(vi.mocked(csrfFetch)).toHaveBeenCalledTimes(2));
    const keys = vi
      .mocked(csrfFetch)
      .mock.calls.map(
        ([, init]) =>
          (init as { headers: Record<string, string> }).headers["Idempotency-Key"],
      );
    expect(keys[0]).toBeTruthy();
    expect(keys[1]).toBe(keys[0]);
  });
});

describe("datos del cliente", () => {
  it("con sesión, el formulario nace con su perfil y su dirección predeterminada", () => {
    const { container } = renderForm({ customer: SEED });

    expect(field(container, "first_name").value).toBe("Ana");
    expect(field(container, "email").value).toBe("ana@example.com");
    expect(field(container, "address_1").value).toBe("Calle Mayor 1");
    expect(field(container, "city").value).toBe("Valencia");
    expect(field(container, "postcode").value).toBe("46001");
  });

  it("sin cliente (invitado) nace vacío y no pide nada al BFF", () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    const { container } = renderForm({ guest: true, customer: null });

    expect(field(container, "first_name").value).toBe("");
    expect(field(container, "email").value).toBe("");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("si el servidor no pudo precargarlo, el formulario lo pide al BFF", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        json({
          email: "ana@example.com",
          first_name: "Ana",
          last_name: "",
          billing: {},
          shipping: {},
        }),
      ),
    );
    const { container } = renderForm({ customer: null });

    await waitFor(() => expect(field(container, "first_name").value).toBe("Ana"));
  });
});

describe("guard de envío", () => {
  it("un carrito que necesita envío y no tiene tarifa no se puede enviar y lo dice", () => {
    cartState.cart = {
      ...baseCart(),
      needs_shipping: true,
      shipping_rates: [{ package_id: 0, name: "Envío", shipping_rates: [] }],
    };
    renderForm();

    expect(screen.getByText(es.checkout.noShipping)).toBeTruthy();
    expect(
      (
        screen.getByRole("button", {
          name: es.checkout.submitOnline,
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);
  });

  it("con tarifa disponible el botón está activo", () => {
    cartState.cart = {
      ...baseCart(),
      needs_shipping: true,
      shipping_rates: [
        {
          package_id: 0,
          name: "Envío",
          shipping_rates: [
            {
              rate_id: "flat_rate:1",
              name: "Estándar",
              price: "500",
              currency_minor_unit: 2,
              currency_code: "EUR",
              selected: true,
            },
          ],
        },
      ],
    };
    renderForm();

    expect(
      (
        screen.getByRole("button", {
          name: es.checkout.submitOnline,
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(false);
  });
});

describe("medición", () => {
  it("al abrir el checkout con carrito, mide `begin_checkout` una sola vez", () => {
    const { rerender } = renderForm();
    rerender(
      <NextIntlClientProvider locale="es" messages={es}>
        <CheckoutForm />
      </NextIntlClientProvider>,
    );

    expect(trackEvent).toHaveBeenCalledTimes(1);
    expect(trackEvent).toHaveBeenCalledWith(
      "begin_checkout",
      expect.objectContaining({ currency: "EUR", value: 10 }),
    );
  });

  it("con el carrito vacío no mide nada", () => {
    cartState.cart = { ...baseCart(), items: [] };
    renderForm();

    expect(trackEvent).not.toHaveBeenCalled();
  });

  it("manda al servidor el consentimiento y el idioma, para el contexto del pedido", async () => {
    tracking.consent = true;
    bff();
    const { container } = renderForm({ paymentChoices: ["cash"] });

    await submit(container);

    await waitFor(() => expect(checkoutCalls()).toHaveLength(1));
    expect(checkoutBodyFull().tracking).toEqual({ consent: true, locale: "es" });
  });

  it("sin consentimiento manda `consent: false`", async () => {
    bff();
    const { container } = renderForm({ paymentChoices: ["cash"] });

    await submit(container);

    await waitFor(() => expect(checkoutCalls()).toHaveLength(1));
    expect(checkoutBodyFull().tracking).toEqual({ consent: false, locale: "es" });
  });
});
