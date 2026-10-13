import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createTranslator } from "next-intl";
import es from "@/i18n/messages/es.json";
import type { ConsentRecordRequest } from "./consent-record";

// ============================================================================
//  recordConsentDecision — lo que el BFF manda a WordPress y la cookie del
//  comprobante. Se mockea todo lo que toca cookies, sesión, config y red.
// ============================================================================

const cookieJar = new Map<string, string>();
const setCookie = vi.fn(
  (name: string, value: string) => void cookieJar.set(name, value),
);

vi.mock("next/headers", () => ({
  cookies: async () => ({
    get: (name: string) =>
      cookieJar.has(name) ? { name, value: cookieJar.get(name) } : undefined,
    set: setCookie,
  }),
}));
vi.mock("next/server", () => ({ after: vi.fn() }));
vi.mock("@/lib/auth/session", () => ({ getSession: async () => null }));
vi.mock("next-intl/server", () => ({
  getTranslations: async ({ namespace }: { namespace: "consent" }) =>
    createTranslator({ locale: "es", messages: es, namespace }),
}));

const integrations = {
  analytics_provider: "ga4",
  analytics_id: "G-TEST1",
  gtm_id: "",
  meta_pixel_id: "123",
  tiktok_pixel_id: "",
  hubspot_portal_id: "",
};
vi.mock("@/lib/config", () => ({ getSiteConfig: async () => ({ integrations }) }));

const parsed: ConsentRecordRequest = {
  decision: "accepted",
  action: "first",
  path: "/",
  variant: "bar",
  locale: "es",
  clientAt: "2026-09-28T20:00:00.000Z",
  services: null,
  generation: 4,
};

const request = () =>
  new Request("https://tienda.example/api/consent", {
    method: "POST",
    headers: { "user-agent": "Vitest", "x-real-ip": "181.49.23.117" },
  });

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  cookieJar.clear();
  setCookie.mockClear();
  fetchMock = vi.fn(async () => new Response("{}", { status: 201 }));
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const sentBody = (call = 0) =>
  JSON.parse((fetchMock.mock.calls[call]![1] as RequestInit).body as string);

describe("recordConsentDecision", () => {
  it("manda a WordPress lo que el servidor sabe y devuelve el comprobante", async () => {
    const { recordConsentDecision } = await import("./consent-ledger");
    const result = await recordConsentDecision(request(), parsed);
    expect(result.ok).toBe(true);
    const body = sentBody();
    expect(body).toMatchObject({
      decision: "accepted",
      services: ["ga4", "meta"],
      generation: 4,
      ip: "181.49.23.0",
    });
    expect(result).toEqual({ ok: true, receipt: body.receipt });
  });

  it("rehace el texto con los servicios que NOMBRÓ el banner, no con los de ahora", async () => {
    const { recordConsentDecision } = await import("./consent-ledger");
    await recordConsentDecision(request(), { ...parsed, services: ["hubspot"] });
    const body = sentBody();
    expect(body.services).toEqual(["hubspot"]);
    expect(body.text).toContain("cookies de analítica de HubSpot");
    expect(body.advertising).toBe(false);
  });

  it("si WordPress falla, la cookie queda y el reintento reusa el mismo comprobante", async () => {
    const { recordConsentDecision } = await import("./consent-ledger");
    fetchMock.mockResolvedValueOnce(new Response("{}", { status: 504 }));
    const first = await recordConsentDecision(request(), parsed);
    expect(first).toEqual({ ok: false, reason: "unavailable" });
    const receipt = sentBody(0).receipt;
    expect(cookieJar.get("hwe_consent")).toBe(receipt);

    const retry = await recordConsentDecision(request(), parsed);
    expect(retry).toEqual({ ok: true, receipt });
    expect(sentBody(1).receipt).toBe(receipt);
  });

  it("sin servicios que consentir no llama a WordPress", async () => {
    const { recordConsentDecision } = await import("./consent-ledger");
    integrations.meta_pixel_id = "";
    integrations.analytics_provider = "none";
    try {
      const result = await recordConsentDecision(request(), parsed);
      expect(result).toEqual({ ok: false, reason: "nothing_to_consent" });
      expect(fetchMock).not.toHaveBeenCalled();
    } finally {
      integrations.meta_pixel_id = "123";
      integrations.analytics_provider = "ga4";
    }
  });
});
