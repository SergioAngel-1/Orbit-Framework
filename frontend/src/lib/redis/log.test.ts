import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const logger = vi.hoisted(() => ({ warn: vi.fn(), error: vi.fn(), info: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({ logger }));

import { logRedisFailure, resetRedisFailureLog } from "./log";

beforeEach(() => {
  vi.useFakeTimers();
  logger.warn.mockReset();
  logger.error.mockReset();
  resetRedisFailureLog();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("logRedisFailure", () => {
  it("registra el fallo con el evento del ámbito, la operación y el error", () => {
    logRedisFailure("rate_limit", "incr", new Error("ECONNRESET"));

    expect(logger.warn).toHaveBeenCalledWith(
      { event: "rate_limit.redis_error", op: "incr", err: "ECONNRESET" },
      "Fallo de Redis en rate_limit",
    );
  });

  it("un control de seguridad que falla abierto se registra como error y dice qué se pierde", () => {
    logRedisFailure("replay", "set", "boom", {
      level: "error",
      effect: "sin anti-replay",
    });

    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "replay.redis_error", err: "boom" }),
      "Fallo de Redis en replay: sin anti-replay",
    );
    expect(logger.warn).not.toHaveBeenCalled();
  });

  it("con Redis caído no inunda el log: uno cada 30 s por ámbito y operación", () => {
    for (let i = 0; i < 5; i++) logRedisFailure("lock", "acquire", new Error("x"));
    logRedisFailure("lock", "release", new Error("x"));

    expect(logger.warn).toHaveBeenCalledTimes(2);
  });

  it("pasado el plazo vuelve a avisar y cuenta los omitidos", () => {
    logRedisFailure("lock", "acquire", new Error("x"));
    logRedisFailure("lock", "acquire", new Error("x"));
    logRedisFailure("lock", "acquire", new Error("x"));
    vi.advanceTimersByTime(30_001);
    logRedisFailure("lock", "acquire", new Error("x"));

    expect(logger.warn).toHaveBeenCalledTimes(2);
    expect(logger.warn.mock.calls[1]![0]).toMatchObject({ suppressed: 2 });
  });
});
