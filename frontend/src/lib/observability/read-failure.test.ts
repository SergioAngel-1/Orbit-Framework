import { beforeEach, describe, expect, it, vi } from "vitest";

const logger = vi.hoisted(() => ({ warn: vi.fn(), error: vi.fn(), info: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({ logger }));

import { logReadFailure } from "./read-failure";

beforeEach(() => {
  logger.warn.mockReset();
  logger.error.mockReset();
});

describe("logReadFailure", () => {
  it("por defecto registra un error con el evento, el contexto y el mensaje del fallo", () => {
    logReadFailure("catalog.categories_failed", new Error("HTTP 502"), {
      context: { locale: "en" },
    });

    expect(logger.error).toHaveBeenCalledWith(
      { event: "catalog.categories_failed", locale: "en", err: "HTTP 502" },
      expect.any(String),
    );
  });

  it("un adorno opcional se registra como aviso, con su propio mensaje", () => {
    logReadFailure("blog.featured_failed", "caído", {
      level: "warn",
      message: "No se pudo leer la entrada destacada",
    });

    expect(logger.warn).toHaveBeenCalledWith(
      { event: "blog.featured_failed", err: "caído" },
      "No se pudo leer la entrada destacada",
    );
    expect(logger.error).not.toHaveBeenCalled();
  });
});
