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

  it("las lecturas simultáneas sin caché comparten UNA petición a WordPress", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    const fetchMock = vi.fn(async () => {
      await new Promise((resolve) => setTimeout(resolve, 5));
      return Response.json({ provider: "wompi" });
    });
    vi.stubGlobal("fetch", fetchMock);

    const results = await Promise.all(
      Array.from({ length: 5 }, () => getPaymentSettings()),
    );

    expect(results.map((r) => r.provider)).toEqual(Array(5).fill("wompi"));
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("con la copia caducada responde al instante y refresca en segundo plano", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Response.json({ provider: "wompi" })),
    );
    vi.useFakeTimers();
    await getPaymentSettings();
    vi.advanceTimersByTime(61_000);

    // WordPress tarda: la respuesta no debe esperarlo.
    let release!: (res: Response) => void;
    const slow = vi.fn(
      () =>
        new Promise<Response>((resolve) => {
          release = resolve;
        }),
    );
    vi.stubGlobal("fetch", slow);

    expect((await getPaymentSettings()).provider).toBe("wompi");
    expect((await getPaymentSettings()).provider).toBe("wompi");
    expect(slow).toHaveBeenCalledTimes(1);

    release(Response.json({ provider: "avalpay" }));
    await vi.waitFor(async () =>
      expect((await getPaymentSettings()).provider).toBe("avalpay"),
    );
    vi.useRealTimers();
  });

  it("una lectura que iba en vuelo al vaciar la caché no la repuebla con datos viejos", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    let release!: (res: Response) => void;
    vi.stubGlobal(
      "fetch",
      vi.fn(
        () =>
          new Promise<Response>((resolve) => {
            release = resolve;
          }),
      ),
    );
    const before = getPaymentSettings();
    clearPaymentSettingsCache(); // se guardó el Control Center mientras tanto
    release(Response.json({ provider: "wompi" }));
    await before;

    const fresh = vi.fn(async () => Response.json({ provider: "avalpay" }));
    vi.stubGlobal("fetch", fresh);
    expect((await getPaymentSettings()).provider).toBe("avalpay");
    expect(fresh).toHaveBeenCalledTimes(1);
  });

  it("con WordPress caído y copia caducada no reintenta en cada petición", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Response.json({ provider: "wompi" })),
    );
    vi.useFakeTimers();
    await getPaymentSettings();
    vi.advanceTimersByTime(61_000);

    const failing = vi.fn(async () => new Response("x", { status: 500 }));
    vi.stubGlobal("fetch", failing);

    expect((await getPaymentSettings()).provider).toBe("wompi");
    await vi.waitFor(() => expect(failing).toHaveBeenCalledTimes(1));
    // Dentro de la ventana de espera tras el fallo: sin nuevas peticiones.
    await vi.advanceTimersByTimeAsync(1_000);
    for (let i = 0; i < 5; i += 1) await getPaymentSettings();
    expect(failing).toHaveBeenCalledTimes(1);
    // Pasada la ventana, un reintento (uno solo).
    vi.advanceTimersByTime(10_000);
    expect((await getPaymentSettings()).provider).toBe("wompi");
    await vi.waitFor(() => expect(failing).toHaveBeenCalledTimes(2));
    vi.useRealTimers();
  });

  it("una copia de más de 10 min ya no se sirve sin esperar a WordPress", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Response.json({ provider: "wompi" })),
    );
    vi.useFakeTimers();
    await getPaymentSettings();
    vi.advanceTimersByTime(11 * 60_000);

    let release!: (res: Response) => void;
    vi.stubGlobal(
      "fetch",
      vi.fn(
        () =>
          new Promise<Response>((resolve) => {
            release = resolve;
          }),
      ),
    );
    let settled = false;
    const pending = getPaymentSettings().then((s) => {
      settled = true;
      return s;
    });
    await vi.advanceTimersByTimeAsync(0);
    expect(settled).toBe(false);

    release(Response.json({ provider: "avalpay" }));
    expect((await pending).provider).toBe("avalpay");
    vi.useRealTimers();
  });

  it("pasado el tope y con WordPress caído, sirve la última buena y espacia la espera", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Response.json({ provider: "wompi" })),
    );
    vi.useFakeTimers();
    await getPaymentSettings();
    vi.advanceTimersByTime(11 * 60_000);

    const failing = vi.fn(async () => new Response("x", { status: 500 }));
    vi.stubGlobal("fetch", failing);

    expect((await getPaymentSettings()).provider).toBe("wompi");
    expect(failing).toHaveBeenCalledTimes(1);
    // Dentro de la ventana tras el fallo no se vuelve a esperar a WordPress.
    expect((await getPaymentSettings()).provider).toBe("wompi");
    expect(failing).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });

  it("una respuesta que no es la config cuenta como fallo para espaciar el reintento", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno-de-pruebas-0123456789");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Response.json({ provider: "wompi" })),
    );
    vi.useFakeTimers();
    await getPaymentSettings();
    vi.advanceTimersByTime(61_000);

    const empty = vi.fn(async () => Response.json(null));
    vi.stubGlobal("fetch", empty);

    expect((await getPaymentSettings()).provider).toBe("wompi");
    await vi.waitFor(() => expect(empty).toHaveBeenCalledTimes(1));
    await vi.advanceTimersByTimeAsync(1_000);
    for (let i = 0; i < 5; i += 1) await getPaymentSettings();
    expect(empty).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });
});
