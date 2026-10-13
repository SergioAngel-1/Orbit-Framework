import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { redirect } from "@/i18n/navigation";
import { getGeoPack } from "@/lib/geo";
import { getShippingMethods } from "@/lib/shipping";
import { CO_PACK } from "../../../../tests/helpers/geo-pack";
import CheckoutPage from "./page";

// ============================================================================
//  /checkout: quién llega al formulario. Sin sesión solo se compra si la tienda
//  admite invitados (`ecommerce.checkout_login_required` apagado); si no, al
//  login. Con sesión, el formulario nace con los datos del cliente.
// ============================================================================

vi.mock("next-intl/server", () => ({
  setRequestLocale: vi.fn(),
  getTranslations: vi.fn(async () => (key: string) => key),
}));
vi.mock("@/i18n/navigation", () => ({
  redirect: vi.fn(() => {
    throw new Error("NEXT_REDIRECT");
  }),
}));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/geo", () => ({ getGeoPack: vi.fn() }));
vi.mock("@/lib/shipping", () => ({ getShippingMethods: vi.fn(async () => []) }));
vi.mock("@/lib/checkout/customer-seed-server", () => ({
  getCheckoutCustomerSeed: vi.fn(async () => ({ first_name: "Ana" })),
}));
vi.mock("@/lib/payments/providers", () => ({}));
vi.mock("@/lib/payments/registry", () => ({
  isActiveGatewaySandbox: vi.fn(async () => false),
}));
vi.mock("@/components/checkout/checkout-form", () => ({
  CheckoutForm: () => null,
}));

function config(loginRequired: boolean) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    ecommerce: {
      checkout_login_required: loginRequired,
      country: "ES",
      coupons_enabled: false,
    },
    payments: { gateway_enabled: true, whatsapp_enabled: false, cash_enabled: false },
  } as never);
}

const render = () => CheckoutPage({ params: Promise.resolve({ locale: "es" }) });

/** Props con las que la página monta el formulario. */
async function formProps() {
  const element = (await render()) as {
    props: { children: unknown };
  };
  const children = element.props.children as unknown[];
  const form = children.find(
    (c) =>
      typeof c === "object" &&
      c !== null &&
      "props" in c &&
      "guest" in (c as never as { props: object }).props,
  ) as { props: Record<string, unknown> };
  return form.props;
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
});

describe("/checkout", () => {
  it("sin sesión y con login obligatorio, redirige al login", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    config(true);

    await expect(render()).rejects.toThrow("NEXT_REDIRECT");

    expect(redirect).toHaveBeenCalledWith({
      href: { pathname: "/login", query: { return: "/checkout" } },
      locale: "es",
    });
  });

  it("sin sesión y con compra de invitado, muestra el formulario como invitado", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    config(false);

    const props = await formProps();

    expect(redirect).not.toHaveBeenCalled();
    expect(props).toMatchObject({ guest: true, customer: undefined });
  });

  it("con sesión, el formulario nace con los datos del cliente", async () => {
    vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "jwt" } as never);
    config(true);

    const props = await formProps();

    expect(props).toMatchObject({ guest: false, customer: { first_name: "Ana" } });
  });

  it("le pasa al formulario el paquete de país de la tienda", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    config(false);

    const props = await formProps();

    expect(props.geoPack).toBe(CO_PACK);
  });

  it("con el módulo customer-identity le pasa los tipos de documento del país; apagado, nada", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    config(false);
    vi.mocked(getSiteConfig).mockResolvedValue({
      ...(await getSiteConfig()),
      modules: { "customer-identity": true },
    } as never);

    const on = await formProps();
    expect(
      (on.identity as { documentTypes: { code: string }[] }).documentTypes.map(
        (d) => d.code,
      ),
    ).toEqual(["CC"]);

    config(false);
    const off = await formProps();
    expect(off.identity).toBeUndefined();
  });

  it("con el módulo shipping le pasa los métodos extra; apagado, ni los lee", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    config(false);
    vi.mocked(getSiteConfig).mockResolvedValue({
      ...(await getSiteConfig()),
      modules: { shipping: true },
    } as never);
    vi.mocked(getShippingMethods).mockResolvedValue([
      { id: 3, name: "Seguro", addedValue: 5 },
    ]);

    const on = await formProps();
    expect(on.shipping).toEqual({
      methods: [{ id: 3, name: "Seguro", addedValue: 5 }],
    });
    expect(getShippingMethods).toHaveBeenCalledWith({ enabled: true });

    config(false);
    vi.mocked(getShippingMethods).mockClear();
    const off = await formProps();
    expect(off.shipping).toBeUndefined();
    expect(getShippingMethods).toHaveBeenCalledWith({ enabled: false });
  });
});
