import { afterEach, describe, expect, it, vi } from "vitest";
import { REF_COOKIE_MAX_AGE, refCookieMaxAge, refCookieOptions } from "./ref-cookie";

const DAY = 24 * 60 * 60;

afterEach(() => vi.restoreAllMocks());

describe("ventana de la cookie de referido", () => {
  it("sin declarar en el registro son 30 días", () => {
    expect(REF_COOKIE_MAX_AGE).toBe(30 * DAY);
    expect(refCookieMaxAge()).toBe(30 * DAY);
    expect(refCookieOptions().maxAge).toBe(30 * DAY);
  });

  it("la instancia puede declarar otra ventana", () => {
    expect(refCookieMaxAge(7)).toBe(7 * DAY);
    expect(refCookieMaxAge(365)).toBe(365 * DAY);
  });

  it.each([[0], [-1], [1.5], [366], [Number.NaN]])(
    "un valor inválido (%s) cae a 30 días y deja un aviso",
    async (days) => {
      vi.resetModules();
      const { refCookieMaxAge: fresh } = await import("./ref-cookie");
      const warn = vi.spyOn(console, "warn").mockImplementation(() => undefined);

      expect(fresh(days)).toBe(30 * DAY);
      expect(warn.mock.calls[0]![0]).toContain("instance.referral_cookie_invalid");
    },
  );

  // Se evalúa en cada visita con `?ref=`: sin esto, el log se llena con el mismo aviso.
  it("avisa una sola vez por proceso", async () => {
    vi.resetModules();
    const { refCookieMaxAge: fresh } = await import("./ref-cookie");
    const warn = vi.spyOn(console, "warn").mockImplementation(() => undefined);

    fresh(0);
    fresh(0);
    fresh(999);

    expect(warn).toHaveBeenCalledTimes(1);
  });
});
