import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  clearPaymentSettingsCache,
  getPaymentSettings,
  resolvePaymentSettings,
} from "./credentials";

vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

beforeEach(() => {
  clearPaymentSettingsCache();
  vi.stubEnv("PAYMENT_PROVIDER", "");
  vi.stubEnv("WOMPI_PUBLIC_KEY", "");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("resolvePaymentSettings (precedencia)", () => {
  it("la pasarela: PAYMENT_PROVIDER > Control Center > noop fuera de producción", () => {
    vi.stubEnv("NODE_ENV", "development");
    expect(resolvePaymentSettings(null).provider).toBe("noop");
    expect(resolvePaymentSettings({ provider: "wompi" }).provider).toBe("wompi");
    vi.stubEnv("PAYMENT_PROVIDER", "avalpay");
    expect(resolvePaymentSettings({ provider: "wompi" }).provider).toBe("avalpay");
  });

  it("en producción sin pasarela decidida no hay ninguna (nunca noop)", () => {
    vi.stubEnv("NODE_ENV", "production");
    expect(resolvePaymentSettings(null).provider).toBeNull();
    expect(resolvePaymentSettings({ provider: "" }).provider).toBeNull();
  });

  it("cada credencial: la del panel; si está vacía, la del entorno", () => {
    vi.stubEnv("WOMPI_PUBLIC_KEY", "pub_test_entorno");
    expect(
      resolvePaymentSettings({ wompi: { public_key: "pub_prod_panel" } }).wompi
        .publicKey,
    ).toBe("pub_prod_panel");
    expect(
      resolvePaymentSettings({ wompi: { public_key: "  " } }).wompi.publicKey,
    ).toBe("pub_test_entorno");
  });
});

describe("getPaymentSettings", () => {
  it("lee el panel con el secreto interno y lo cachea en memoria", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    const fetchMock = vi.fn(async () => Response.json({ provider: "wompi" }));
    vi.stubGlobal("fetch", fetchMock);

    expect((await getPaymentSettings()).provider).toBe("wompi");
    expect((await getPaymentSettings()).provider).toBe("wompi");

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toMatch(/\/wp-json\/hwe\/v1\/payments\/credentials$/);
    expect(
      (init.headers as Record<string, string>)["X-HWE-Internal-Secret"],
    ).toBeTruthy();
    expect(init.cache).toBe("no-store");
  });

  it("si WordPress no responde, usa la última lectura buena", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Response.json({ provider: "wompi" })),
    );
    await getPaymentSettings();
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("x", { status: 500 })),
    );
    vi.useFakeTimers();
    vi.advanceTimersByTime(120_000);

    expect((await getPaymentSettings()).provider).toBe("wompi");
    vi.useRealTimers();
  });
});
