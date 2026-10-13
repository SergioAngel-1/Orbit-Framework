// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { AnalyticsProvider, useConsent } from "./analytics-provider";
import { setTrackingReady } from "./track-event";

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

function renderProvider(config: typeof NONE, extra?: ReactNode) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <AnalyticsProvider config={config}>{extra}</AnalyticsProvider>
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
    expect(store["cookie-consent"]).toBe("accepted");
    expect(setTrackingReady).toHaveBeenLastCalledWith(true);
  });

  it("«Solo necesarias» no carga nada y recuerda la decisión", () => {
    renderProvider(ALL);

    fireEvent.click(screen.getByRole("button", { name: es.consent.necessaryOnly }));

    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
    expect(store["cookie-consent"]).toBe("declined");
    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
  });

  it("una decisión guardada no vuelve a preguntar y, si fue «Aceptar», carga", () => {
    store["cookie-consent"] = "accepted";

    renderProvider(ALL);

    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
    expect(screen.getByTestId("hwe-ga4")).toBeTruthy();
  });

  it("con GTM, GA4 directo no se carga (el contenedor lo trae por dentro)", () => {
    store["cookie-consent"] = "accepted";

    renderProvider({ ...ALL, gtm_id: "GTM-ABC123" });

    expect(screen.getByTestId("hwe-gtm")).toBeTruthy();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
  });

  it("Plausible se carga con su dominio y el host por defecto", () => {
    store["cookie-consent"] = "accepted";

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
    store["cookie-consent"] = "accepted";

    renderProvider({ ...ALL, meta_pixel_id: "1');alert(1);//", analytics_id: "G-A'B" });

    expect(screen.queryByTestId("hwe-meta-pixel")).toBeNull();
    expect(screen.queryByTestId("hwe-ga4")).toBeNull();
  });
});

describe("preferencias", () => {
  it("reabre el banner con «Cerrar» para dejar la decisión como estaba", () => {
    store["cookie-consent"] = "declined";
    renderProvider(ALL, <ConsentProbe />);

    fireEvent.click(screen.getByRole("button", { name: "preferencias" }));
    fireEvent.click(screen.getByRole("button", { name: es.consent.close }));

    expect(screen.queryByRole("region", { name: es.consent.regionLabel })).toBeNull();
    expect(screen.getByTestId("consent").textContent).toBe("declined");
  });

  it("retirar un «Aceptar» borra las cookies de seguimiento y recarga", () => {
    store["cookie-consent"] = "accepted";
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
