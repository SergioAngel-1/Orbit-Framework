// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render } from "@testing-library/react";
import { StrictMode, type ComponentType } from "react";

let pathname = "/";
vi.mock("next/navigation", () => ({ usePathname: () => pathname }));

// La última ruta enviada vive a nivel de módulo: cada test importa uno nuevo.
async function loadTracker(): Promise<ComponentType> {
  vi.resetModules();
  const { Ga4PageViews } = await import("./ga4-page-views");
  return Ga4PageViews;
}

let gtag: ReturnType<typeof vi.fn<(...args: unknown[]) => void>>;

beforeEach(() => {
  pathname = "/";
  gtag = vi.fn<(...args: unknown[]) => void>();
  window.gtag = gtag;
});

afterEach(() => {
  cleanup();
  delete window.gtag;
});

describe("Ga4PageViews — un page_view por ruta", () => {
  it("la vista de carga no se reenvía: esa la manda el `config`", async () => {
    const Tracker = await loadTracker();
    render(
      <StrictMode>
        <Tracker />
      </StrictMode>,
    );
    expect(gtag).not.toHaveBeenCalled();
  });

  it("cada cambio de ruta manda un page_view; un cambio de query, ninguno", async () => {
    const Tracker = await loadTracker();
    const { rerender } = render(<Tracker />);

    pathname = "/tienda";
    rerender(<Tracker />);
    expect(gtag).toHaveBeenCalledTimes(1);
    expect(gtag).toHaveBeenCalledWith(
      "event",
      "page_view",
      expect.objectContaining({ page_location: window.location.href }),
    );

    rerender(<Tracker />);
    expect(gtag).toHaveBeenCalledTimes(1);

    pathname = "/en/store";
    rerender(<Tracker />);
    expect(gtag).toHaveBeenCalledTimes(2);
  });
});
