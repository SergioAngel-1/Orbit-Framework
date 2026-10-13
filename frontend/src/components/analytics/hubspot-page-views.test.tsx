// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render } from "@testing-library/react";
import { StrictMode, type ComponentType } from "react";

let pathname = "/";
vi.mock("next/navigation", () => ({ usePathname: () => pathname }));

// La última ruta enviada vive a nivel de módulo: cada test importa uno nuevo.
async function loadTracker(): Promise<ComponentType> {
  vi.resetModules();
  const { HubSpotPageViews } = await import("./hubspot-page-views");
  return HubSpotPageViews;
}

const queue = () => window._hsq ?? [];

beforeEach(() => {
  pathname = "/";
  delete window._hsq;
});

afterEach(() => {
  cleanup();
  delete window._hsq;
});

describe("HubSpotPageViews — una vista por ruta", () => {
  it("la vista de carga no se reenvía: esa la cuenta el propio script", async () => {
    const Tracker = await loadTracker();
    render(
      <StrictMode>
        <Tracker />
      </StrictMode>,
    );
    expect(queue()).toEqual([]);
  });

  it("cada cambio de ruta encola setPath + trackPageView, en ese orden", async () => {
    const Tracker = await loadTracker();
    const { rerender } = render(<Tracker />);

    pathname = "/tienda";
    rerender(<Tracker />);
    expect(queue()).toEqual([["setPath", "/tienda"], ["trackPageView"]]);

    // Re-render sin navegar (p. ej. cambió solo la query): nada.
    rerender(<Tracker />);
    expect(queue()).toHaveLength(2);

    pathname = "/en/store";
    rerender(<Tracker />);
    expect(queue().slice(2)).toEqual([["setPath", "/en/store"], ["trackPageView"]]);
  });

  it("usa la cola que ya exista (la del script cargado) en vez de reemplazarla", async () => {
    const existing: unknown[][] = [["identify", {}]];
    window._hsq = existing;
    const Tracker = await loadTracker();
    const { rerender } = render(<Tracker />);
    pathname = "/blog";
    rerender(<Tracker />);
    expect(window._hsq).toBe(existing);
    expect(existing).toHaveLength(3);
  });
});
