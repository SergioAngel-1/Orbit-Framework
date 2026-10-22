import { beforeEach, describe, expect, it, vi } from "vitest";

const getPaymentSettings = vi.fn();
vi.mock("./credentials", () => ({
  getPaymentSettings: () => getPaymentSettings(),
}));
const getSiteConfig = vi.fn();
vi.mock("@/lib/config", () => ({
  getSiteConfig: () => getSiteConfig(),
}));

import {
  acceptsWebhooksFrom,
  getActiveProvider,
  getProvider,
  isActiveGatewaySandbox,
  listProviders,
  registerProvider,
} from "./registry";
import { PaymentError, type PaymentProvider } from "./types";

const provider = (id: string, sandbox = false): PaymentProvider =>
  ({ id, isSandbox: async () => sandbox }) as unknown as PaymentProvider;

/** Medios del checkout: el pago en línea encendido o apagado (solo WhatsApp). */
function onlinePayments(enabled: boolean) {
  getSiteConfig.mockResolvedValue({
    payments: {
      gateway_enabled: enabled,
      whatsapp_enabled: !enabled,
      cash_enabled: false,
    },
  });
}

beforeEach(() => {
  getPaymentSettings.mockReset();
  getPaymentSettings.mockResolvedValue({ provider: null });
  onlinePayments(true);
  registerProvider(provider("noop", true));
  registerProvider(provider("wompi", false));
});

describe("registro de pasarelas", () => {
  it("lista las registradas y registrar dos veces el mismo id deja la última", () => {
    expect(listProviders()).toEqual(expect.arrayContaining(["noop", "wompi"]));

    const replacement = provider("wompi");
    registerProvider(replacement);

    expect(getProvider("wompi")).toBe(replacement);
  });

  it("una pasarela no registrada es 404 con código", () => {
    expect(() => getProvider("nada")).toThrowError(PaymentError);
    try {
      getProvider("nada");
    } catch (error) {
      expect(error).toMatchObject({ status: 404, code: "provider_not_found" });
    }
  });

  it("sin pasarela decidida, cobrar es 503 (no hay respaldo silencioso)", async () => {
    await expect(getActiveProvider()).rejects.toMatchObject({
      status: 503,
      code: "provider_unavailable",
    });
  });

  it("la pasarela activa es la que dice la configuración", async () => {
    getPaymentSettings.mockResolvedValue({ provider: "wompi" });

    expect((await getActiveProvider()).id).toBe("wompi");
  });
});

describe("acceptsWebhooksFrom", () => {
  it("una pasarela no registrada no atiende", async () => {
    expect(await acceptsWebhooksFrom("nada")).toBe(false);
  });

  it("una real atiende siempre: la notificación tardía de la anterior es legítima", async () => {
    getPaymentSettings.mockResolvedValue({ provider: "avalpay" });

    expect(await acceptsWebhooksFrom("wompi")).toBe(true);
  });

  it("la de pruebas solo atiende si es la activa (firma con un secreto público)", async () => {
    getPaymentSettings.mockResolvedValue({ provider: "wompi" });
    expect(await acceptsWebhooksFrom("noop")).toBe(false);

    getPaymentSettings.mockResolvedValue({ provider: "noop" });
    expect(await acceptsWebhooksFrom("noop")).toBe(true);
  });

  it("con el pago en línea apagado no hay pasarela activa: la de pruebas guardada no atiende", async () => {
    // «Solo WhatsApp»: el panel oculta la pasarela, pero el `noop` por defecto
    // sigue guardado. Su webhook (secreto público) marcaría pagado cualquier pedido.
    onlinePayments(false);
    getPaymentSettings.mockResolvedValue({ provider: "noop" });

    expect(await acceptsWebhooksFrom("noop")).toBe(false);
    expect(await isActiveGatewaySandbox()).toBe(false);
    await expect(getActiveProvider()).rejects.toMatchObject({ status: 503 });
  });

  it("con el pago en línea apagado, una real sigue atendiendo cobros ya iniciados", async () => {
    onlinePayments(false);
    getPaymentSettings.mockResolvedValue({ provider: "wompi" });

    expect(await acceptsWebhooksFrom("wompi")).toBe(true);
  });
});

describe("isActiveGatewaySandbox", () => {
  it("es el entorno de la pasarela activa; sin ninguna, falso", async () => {
    expect(await isActiveGatewaySandbox()).toBe(false);

    getPaymentSettings.mockResolvedValue({ provider: "noop" });
    expect(await isActiveGatewaySandbox()).toBe(true);

    getPaymentSettings.mockResolvedValue({ provider: "wompi" });
    expect(await isActiveGatewaySandbox()).toBe(false);
  });
});
