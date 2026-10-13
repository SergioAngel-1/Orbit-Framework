// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { CONSENT_GENERATION } from "@/lib/analytics/consent-storage";
import { AnalyticsProvider, useConsent } from "./analytics-provider";
import { setTrackingReady } from "./track-event";
import { reportConsentEvent } from "@/lib/analytics/consent-report";

// ============================================================================
//  AnalyticsProvider: qué se carga, cuándo y con qué consentimiento. Los
//  servicios salen de lo configurado en Control Center → Integraciones; sin
//  «Aceptar» no se carga ninguno, y retirar el consentimiento los quita.
// ============================================================================

vi.mock("next/script", () => ({
  default: (props: { id?: string; src?: string; "data-domain"?: string }) => (
    <script
      data-testid={props.id}
      data-src={props.src}
      data-domain={props["data-domain"]}
    />
  ),
}));
vi.mock("next/navigation", () => ({ usePathname: () => "/" }));
vi.mock("@/i18n/navigation", () => ({
  Link: ({ children, href }: { children: ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
}));
vi.mock("./track-event", () => ({ setTrackingReady: vi.fn() }));
vi.mock("@/lib/analytics/consent-report", () => ({ reportConsentEvent: vi.fn() }));

const NONE = {
  analytics_provider: "none",
  analytics_id: "",
  gtm_id: "",
  meta_pixel_id: "",
  tiktok_pixel_id: "",
  hubspot_portal_id: "",
};

const ALL = {
  analytics_provider: "ga4",
  analytics_id: "G-ABC123",
  gtm_id: "",
  meta_pixel_id: "123456789",
  tiktok_pixel_id: "CABC123",
  hubspot_portal_id: "4567890",
};

let store: Record<string, string> = {};
const memoryStorage = {
  getItem: (k: string) => store[k] ?? null,
  setItem: (k: string, v: string) => {
    store[k] = v;
  },
  removeItem: (k: string) => {
    delete store[k];
  },
};

function renderProvider(config: typeof NONE, extra?: ReactNode, ledger = false) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <AnalyticsProvider config={config} ledger={ledger}>
        {extra}
      </AnalyticsProvider>
    </NextIntlClientProvider>,
  );
}

function ConsentProbe() {
  const { consent, required, openPreferences } = useConsent();
  return (
    <>
      <p data-testid="consent">{consent}</p>
      <p data-testid="required">{String(required)}</p>
      <button onClick={openPreferences}>preferencias</button>
    </>
  );
}

/** Una decisión guardada, con el formato del registro de consentimientos. */
function stored(status: "accepted" | "declined", services: string[] | null = null) {
  return JSON.stringify({
    v: CONSENT_GENERATION,
    status,
    services,
    receipt: null,
    pending: [],
  });
}

beforeEach(() => {
  store = {};
  vi.stubGlobal("localStorage", memoryStorage);
  vi.clearAllMocks();
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("sin servicios configurados", () => {
  it("no hay nada que consentir: ni banner ni scripts", () => {
    renderProvider(NONE, <ConsentProbe />);

    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
    expect(screen.getByTestId("required").textContent).toBe("false");
  });
});

describe("con servicios configurados", () => {
  it("sin respuesta pregunta y NOMBRA a los terceros, sin cargar ningún script", () => {
    renderProvider(ALL);

    const banner = screen.getByRole("region", { name: es.consent.regionLabel });
    expect(banner.textContent).toContain("Google Analytics");
    expect(banner.textContent).toContain("Meta");
    expect(banner.textContent).toContain("TikTok");
    expect(banner.textContent).toContain("HubSpot");
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
    expect(screen.queryByTestId("hwe-meta-pixel")).toBeNull();
    expect(setTrackingReady).toHaveBeenLastCalledWith(false);
  });

  it("al aceptar carga los de lo configurado y abre la cola de eventos", () => {
    renderProvider(ALL);

    fireEvent.click(screen.getByRole("button", { name: es.consent.acceptAll }));

    expect(screen.getByTestId("hwe-ga4").getAttribute("data-src")).toContain(
      "G-ABC123",
    );
    expect(screen.getByTestId("hwe-meta-pixel")).toBeTruthy();
    expect(screen.getByTestId("hwe-tiktok-pixel")).toBeTruthy();
    expect(screen.getByTestId("hs-script-loader").getAttribute("data-src")).toContain(
      "4567890",
    );
    expect(screen.queryByTestId("hwe-gtm")).toBeNull();
    expect(JSON.parse(store["cookie-consent"]!)).toMatchObject({
      status: "accepted",
      services: ["ga4", "meta", "tiktok", "hubspot"],
    });
    expect(setTrackingReady).toHaveBeenLastCalledWith(true);
  });

  it("«Solo necesarias» no carga nada y recuerda la decisión", () => {
    renderProvider(ALL);

    fireEvent.click(screen.getByRole("button", { name: es.consent.necessaryOnly }));

    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
    expect(JSON.parse(store["cookie-consent"]!).status).toBe("declined");
    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
  });

  it("una decisión guardada no vuelve a preguntar y, si fue «Aceptar», carga", () => {
    store["cookie-consent"] = stored("accepted");

    renderProvider(ALL);

    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
    expect(screen.getByTestId("hwe-ga4")).toBeTruthy();
  });

  it("con GTM, GA4 directo no se carga (el contenedor lo trae por dentro)", () => {
    store["cookie-consent"] = stored("accepted");

    renderProvider({ ...ALL, gtm_id: "GTM-ABC123" });

    expect(screen.getByTestId("hwe-gtm")).toBeTruthy();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
  });

  it("Plausible se carga con su dominio y el host por defecto", () => {
    store["cookie-consent"] = stored("accepted");

    renderProvider({
      ...NONE,
      analytics_provider: "plausible",
      analytics_id: "tienda.example",
    });

    const script = screen.getByTestId("hwe-plausible");
    expect(script.getAttribute("data-domain")).toBe("tienda.example");
    expect(script.getAttribute("data-src")).toBe("https://plausible.io/js/script.js");
  });

  it("un ID con forma inválida no llega a ningún script", () => {
    store["cookie-consent"] = stored("accepted");

    renderProvider({ ...ALL, meta_pixel_id: "1');alert(1);//", analytics_id: "G-A'B" });

    expect(screen.queryByTestId("hwe-meta-pixel")).toBeNull();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
  });
});

describe("preferencias", () => {
  it("reabre el banner con «Cerrar» para dejar la decisión como estaba", () => {
    store["cookie-consent"] = stored("declined");
    renderProvider(ALL, <ConsentProbe />);

    fireEvent.click(screen.getByRole("button", { name: "preferencias" }));
    fireEvent.click(screen.getByRole("button", { name: es.consent.close }));

    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
    expect(screen.getByTestId("consent").textContent).toBe("declined");
  });

  it("retirar un «Aceptar» borra las cookies de seguimiento y recarga", () => {
    store["cookie-consent"] = stored("accepted");
    document.cookie = "_fbp=fb.1.1.1; path=/";
    const reload = vi.fn();
    vi.stubGlobal("location", { ...window.location, reload, hostname: "localhost" });
    renderProvider(ALL, <ConsentProbe />);

    fireEvent.click(screen.getByRole("button", { name: "preferencias" }));
    act(() => {
      fireEvent.click(screen.getByRole("button", { name: es.consent.necessaryOnly }));
    });

    expect(reload).toHaveBeenCalled();
    expect(document.cookie).not.toContain("_fbp");
  });
});

describe("registro de consentimientos (módulo consent-log)", () => {
  const RECEIPT = "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c";

  function fetchOk() {
    const fetchMock = vi.fn<(input: string, init?: RequestInit) => Promise<Response>>(
      async () => new Response(JSON.stringify({ receipt: RECEIPT }), { status: 200 }),
    );
    vi.stubGlobal("fetch", fetchMock);
    return fetchMock;
  }

  it("apagado: la decisión vale en el navegador y no se envía ni se encola nada", async () => {
    const fetchMock = fetchOk();
    renderProvider(ALL, undefined, false);

    fireEvent.click(screen.getByRole("button", { name: es.consent.acceptAll }));

    expect(fetchMock).not.toHaveBeenCalled();
    expect(JSON.parse(store["cookie-consent"]!).pending).toEqual([]);
    expect(reportConsentEvent).not.toHaveBeenCalled();
  });

  it("encendido: cada decisión se encola y se envía al registro con su comprobante", async () => {
    const fetchMock = fetchOk();
    renderProvider(ALL, undefined, true);
    // El envío de arranque (cola vacía) termina antes de decidir.
    await act(async () => {});

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: es.consent.acceptAll }));
    });

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/consent",
      expect.objectContaining({ method: "POST", keepalive: true }),
    );
    const sent = JSON.parse(String(fetchMock.mock.calls[0]![1]!.body));
    expect(sent).toMatchObject({ decision: "accepted", action: "first", locale: "es" });
    expect(sent.services).toEqual(["ga4", "meta", "tiktok", "hubspot"]);
    await vi.waitFor(() =>
      expect(JSON.parse(store["cookie-consent"]!)).toMatchObject({
        receipt: RECEIPT,
        pending: [],
      }),
    );
  });

  it("la medición cuenta la PRIMERA respuesta, no los cambios desde «Preferencias»", async () => {
    fetchOk();
    renderProvider(ALL, <ConsentProbe />, true);

    expect(reportConsentEvent).toHaveBeenCalledWith("shown", "bar");
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: es.consent.acceptAll }));
    });
    expect(reportConsentEvent).toHaveBeenCalledWith("accepted", "bar");

    vi.mocked(reportConsentEvent).mockClear();
    fireEvent.click(screen.getByRole("button", { name: "preferencias" }));
    const reload = vi.fn();
    vi.stubGlobal("location", { ...window.location, reload, hostname: "localhost" });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: es.consent.necessaryOnly }));
    });

    expect(reportConsentEvent).not.toHaveBeenCalled();
  });

  it("un «Aceptar» que no nombraba un servicio nuevo no lo cubre: vuelve a preguntar y no carga ese", () => {
    store["cookie-consent"] = stored("accepted", ["ga4"]);

    renderProvider(ALL);

    expect(screen.getByRole("region", { name: es.consent.regionLabel })).toBeTruthy();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
    expect(setTrackingReady).toHaveBeenLastCalledWith(false);
  });

  it("una decisión de una generación anterior se descarta y se repregunta", () => {
    store["cookie-consent"] = JSON.stringify({
      v: CONSENT_GENERATION - 1,
      status: "accepted",
      services: null,
      receipt: null,
      pending: [],
    });

    renderProvider(ALL);

    expect(screen.getByRole("region", { name: es.consent.regionLabel })).toBeTruthy();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
  });

  it("al reabrir «Preferencias» enseña el comprobante de la decisión vigente", () => {
    store["cookie-consent"] = JSON.stringify({
      v: CONSENT_GENERATION,
      status: "accepted",
      services: ["ga4", "meta", "tiktok", "hubspot"],
      receipt: RECEIPT,
      pending: [],
    });
    renderProvider(ALL, <ConsentProbe />);

    fireEvent.click(screen.getByRole("button", { name: "preferencias" }));

    expect(
      screen.getByRole("region", { name: es.consent.regionLabel }).textContent,
    ).toContain(RECEIPT);
  });
});
