import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));
let degraded = false;
vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => ({
    ...structuredClone(CONFIG_DEFAULTS),
    brand: { ...CONFIG_DEFAULTS.brand, url: "https://mitienda.example/" },
  }),
  isFallbackSiteConfig: () => degraded,
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

import {
  INDEXNOW_ENDPOINT,
  flushIndexNowForTests,
  indexNowKey,
  notifyProductChange,
  productPathFromWebhook,
  submitIndexNow,
} from "./indexnow";

const fetchMock = vi.fn(async () => new Response(null, { status: 202 }));

beforeEach(() => {
  degraded = false;
  fetchMock.mockClear();
  vi.stubGlobal("fetch", fetchMock);
  vi.stubEnv("NODE_ENV", "production");
  vi.stubEnv("INDEXNOW_KEY", "a1b2c3d4e5f6a7b8");
  vi.stubEnv("HWE_NOINDEX", "");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("indexNowKey", () => {
  it("solo acepta 8–128 caracteres alfanuméricos o guiones", () => {
    expect(indexNowKey({ INDEXNOW_KEY: "a1b2c3d4" })).toBe("a1b2c3d4");
    expect(indexNowKey({ INDEXNOW_KEY: "corta" })).toBeNull();
    expect(indexNowKey({ INDEXNOW_KEY: "con espacio 123" })).toBeNull();
    expect(indexNowKey({})).toBeNull();
  });
});

describe("productPathFromWebhook", () => {
  it("toma el slug del cuerpo de product.*", () => {
    expect(productPathFromWebhook('{"id":7,"slug":"vela-roja"}')).toBe(
      "/products/vela-roja",
    );
  });

  it("sin slug (el ping de prueba) o con JSON roto, nada", () => {
    expect(productPathFromWebhook("webhook_id=12")).toBeNull();
    expect(productPathFromWebhook('{"id":7}')).toBeNull();
  });
});

describe("notifyProductChange", () => {
  it("agrupa las fichas y las envía con host, clave y keyLocation", async () => {
    await notifyProductChange('{"slug":"vela-roja"}');
    await notifyProductChange('{"slug":"vela-azul"}');
    await notifyProductChange('{"slug":"vela-roja"}');
    await flushIndexNowForTests();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe(INDEXNOW_ENDPOINT);
    expect(JSON.parse(String(init.body))).toEqual({
      host: "mitienda.example",
      key: "a1b2c3d4e5f6a7b8",
      keyLocation: "https://mitienda.example/indexnow.txt",
      urlList: [
        "https://mitienda.example/products/vela-roja",
        "https://mitienda.example/products/vela-azul",
      ],
    });
  });

  it.each([
    ["sin clave", () => vi.stubEnv("INDEXNOW_KEY", "")],
    ["en desarrollo", () => vi.stubEnv("NODE_ENV", "development")],
    ["con la guarda de staging", () => vi.stubEnv("HWE_NOINDEX", "true")],
    ["con la config degradada", () => (degraded = true)],
  ])("no envía nada %s", async (_label, setup) => {
    setup();
    await notifyProductChange('{"slug":"vela-roja"}');
    await flushIndexNowForTests();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("submitIndexNow", () => {
  it("un fallo de red no lanza: devuelve 0", async () => {
    const failing = vi.fn(async () => {
      throw new Error("sin red");
    });
    await expect(
      submitIndexNow(
        { host: "h", key: "k", keyLocation: "l", urlList: ["u"] },
        failing as unknown as typeof fetch,
      ),
    ).resolves.toBe(0);
  });
});
