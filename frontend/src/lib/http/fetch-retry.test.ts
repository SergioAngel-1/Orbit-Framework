import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn() },
}));

import { logger } from "@/lib/observability/logger";
import { fetchWithRetry } from "./fetch-retry";

const res = (status: number) => ({ status, ok: status < 400 }) as Response;

beforeEach(() => {
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});

async function run(promise: Promise<Response>) {
  const settled = promise.catch((e: unknown) => e);
  await vi.runAllTimersAsync();
  return settled;
}

describe("fetchWithRetry", () => {
  it("devuelve la primera respuesta correcta sin esperar", async () => {
    const send = vi.fn().mockResolvedValue(res(200));
    expect(await run(fetchWithRetry(send))).toMatchObject({ status: 200 });
    expect(send).toHaveBeenCalledTimes(1);
  });

  it("reintenta un 5xx hasta recuperarse", async () => {
    const send = vi
      .fn()
      .mockResolvedValueOnce(res(503))
      .mockResolvedValueOnce(res(200));
    expect(await run(fetchWithRetry(send))).toMatchObject({ status: 200 });
    expect(send).toHaveBeenCalledTimes(2);
    expect(logger.warn).toHaveBeenCalledTimes(1);
  });

  it("reintenta un error de red", async () => {
    const send = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("fetch failed"))
      .mockResolvedValueOnce(res(200));
    expect(await run(fetchWithRetry(send))).toMatchObject({ status: 200 });
  });

  it("un 4xx se devuelve tal cual: reintentarlo solo alarga el build", async () => {
    const send = vi.fn().mockResolvedValue(res(404));
    expect(await run(fetchWithRetry(send))).toMatchObject({ status: 404 });
    expect(send).toHaveBeenCalledTimes(1);
  });

  it("agotados los intentos lanza el último error y lo registra", async () => {
    const send = vi.fn().mockResolvedValue(res(502));
    const result = await run(fetchWithRetry(send, undefined, { attempts: 3 }));
    expect(result).toBeInstanceOf(Error);
    expect((result as Error).message).toBe("HTTP 502");
    expect(send).toHaveBeenCalledTimes(3);
    expect(logger.error).toHaveBeenCalledTimes(1);
  });

  it("acepta una URL y su init", async () => {
    const fn = vi.fn().mockResolvedValue(res(200));
    vi.stubGlobal("fetch", fn);
    await run(fetchWithRetry("https://x.test/a", { method: "GET" }));
    expect(fn).toHaveBeenCalledWith("https://x.test/a", { method: "GET" });
  });

  it("espera con backoff exponencial entre intentos", async () => {
    const send = vi.fn().mockResolvedValue(res(500));
    const pending = fetchWithRetry(send, undefined, {
      attempts: 3,
      baseDelayMs: 100,
    }).catch(() => null);
    await vi.advanceTimersByTimeAsync(0);
    expect(send).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(99);
    expect(send).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(send).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(199);
    expect(send).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(send).toHaveBeenCalledTimes(3);
    await pending;
  });
});
