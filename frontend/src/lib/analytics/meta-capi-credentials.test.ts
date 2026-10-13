import { afterEach, describe, expect, it, vi } from "vitest";
import {
  clearMetaCapiCredentialsCache,
  getMetaCapiCredentials,
  parseMetaCapiCredentials,
} from "./meta-capi-credentials";

afterEach(() => {
  clearMetaCapiCredentialsCache();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("parseMetaCapiCredentials", () => {
  it("recorta y tolera campos ausentes", () => {
    expect(
      parseMetaCapiCredentials({ access_token: " EAAB ", test_event_code: "" }),
    ).toEqual({ accessToken: "EAAB", testEventCode: "" });
    expect(parseMetaCapiCredentials(null)).toEqual({
      accessToken: "",
      testEventCode: "",
    });
  });
});

describe("getMetaCapiCredentials", () => {
  it("lee del canal interno con el secreto del BFF y cachea", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "s".repeat(32));
    vi.stubEnv("WORDPRESS_INTERNAL_API_URL", "http://wp/graphql");
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ access_token: "EAAB", test_event_code: "TEST1" }),
    });
    vi.stubGlobal("fetch", fetchMock);

    expect(await getMetaCapiCredentials()).toEqual({
      accessToken: "EAAB",
      testEventCode: "TEST1",
    });
    await getMetaCapiCredentials();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0]![0]).toBe(
      "http://wp/wp-json/hwe/v1/integrations/meta-capi",
    );
  });

  it("con WordPress caído usa la última lectura buena", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "s".repeat(32));
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ access_token: "EAAB" }) })
      .mockRejectedValueOnce(new Error("down"));
    vi.stubGlobal("fetch", fetchMock);
    vi.useFakeTimers();
    try {
      await getMetaCapiCredentials();
      vi.advanceTimersByTime(61_000);
      expect((await getMetaCapiCredentials()).accessToken).toBe("EAAB");
    } finally {
      vi.useRealTimers();
    }
  });
});
