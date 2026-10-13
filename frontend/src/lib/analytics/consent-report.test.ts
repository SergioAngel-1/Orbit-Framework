// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { isLikelyBot, reportConsentEvent } from "./consent-report";

const fetchMock = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })));

beforeEach(() => {
  sessionStorage.clear();
  fetchMock.mockClear();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("isLikelyBot", () => {
  it("reconoce rastreadores, Lighthouse y navegadores automatizados", () => {
    expect(
      isLikelyBot({
        userAgent: "Mozilla/5.0 (compatible; Googlebot/2.1)",
        webdriver: false,
      }),
    ).toBe(true);
    expect(
      isLikelyBot({ userAgent: "Mozilla/5.0 Chrome-Lighthouse", webdriver: false }),
    ).toBe(true);
    expect(
      isLikelyBot({ userAgent: "Mozilla/5.0 HeadlessChrome/120", webdriver: false }),
    ).toBe(true);
    expect(
      isLikelyBot({
        userAgent: "Mozilla/5.0 Chrome/140 Safari/537.36",
        webdriver: true,
      }),
    ).toBe(true);
    expect(
      isLikelyBot({ userAgent: "Mozilla/5.0 (iPhone) Safari/604.1", webdriver: false }),
    ).toBe(false);
  });
});

describe("reportConsentEvent — una vez por evento y sesión", () => {
  it("envía el evento con su variante", () => {
    reportConsentEvent("shown", "bar");
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("/api/consent-stats");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ event: "shown", variant: "bar" });
  });

  it("el banner repintado en otra página no vuelve a contar", () => {
    reportConsentEvent("shown", "bar");
    reportConsentEvent("shown", "bar");
    reportConsentEvent("accepted", "bar");
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("sin sessionStorage no envía nada (no puede deduplicar)", () => {
    vi.stubGlobal("sessionStorage", {
      getItem: () => {
        throw new Error("SecurityError");
      },
    });
    reportConsentEvent("shown", "bar");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
