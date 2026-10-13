// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render } from "@testing-library/react";
import { StrictMode, type ComponentType } from "react";

let pathname = "/";
vi.mock("next/navigation", () => ({ usePathname: () => pathname }));

const fbq = vi.fn();
const PIXEL_ID = "1234567890";

// La última ruta enviada vive a nivel de módulo: cada test importa uno nuevo.
async function loadTracker(): Promise<ComponentType> {
  vi.resetModules();
  const { MetaPixelPageViews } = await import("./meta-pixel-page-views");
  return function Tracker() {
    return <MetaPixelPageViews pixelId={PIXEL_ID} />;
  };
}

beforeEach(() => {
  pathname = "/";
  fbq.mockReset();
  vi.stubGlobal("fbq", fbq);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("MetaPixelPageViews — un PageView por vista", () => {
  it("la vista de carga no se reenvía: ese PageView es del snippet", async () => {
    const Tracker = await loadTracker();
    render(
      <StrictMode>
        <Tracker />
      </StrictMode>,
    );
    expect(fbq).not.toHaveBeenCalled();
  });

  it("cada cambio de ruta envía exactamente un PageView", async () => {
    const Tracker = await loadTracker();
    const { rerender } = render(<Tracker />);

    pathname = "/tienda";
    rerender(<Tracker />);
    expect(fbq).toHaveBeenCalledTimes(1);
    // `trackSingle`, no `track`: fbevents.js descarta en silencio todo
    // `track('PageView')` posterior al primero de la carga (ver el componente).
    expect(fbq).toHaveBeenCalledWith("trackSingle", PIXEL_ID, "PageView");
    expect(fbq).not.toHaveBeenCalledWith("track", "PageView");

    // Re-render sin navegar (p. ej. cambió solo la query): nada.
    rerender(<Tracker />);
    expect(fbq).toHaveBeenCalledTimes(1);

    pathname = "/tienda/camisetas";
    rerender(<Tracker />);
    expect(fbq).toHaveBeenCalledTimes(2);
  });

  it("remontar en otra ruta (cambio de idioma) la cuenta; en la misma, no", async () => {
    const Tracker = await loadTracker();
    render(<Tracker />);
    cleanup();

    pathname = "/en";
    render(<Tracker />);
    expect(fbq).toHaveBeenCalledTimes(1);
    cleanup();

    render(<Tracker />);
    expect(fbq).toHaveBeenCalledTimes(1);
  });
});
