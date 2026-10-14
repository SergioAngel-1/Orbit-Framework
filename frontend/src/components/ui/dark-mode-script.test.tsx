// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import { DarkModeScript } from "./dark-mode-toggle";

// ============================================================================
//  `DarkModeScript` en el CLIENTE (navegación blanda, p. ej. al cambiar de
//  idioma): no pinta un `<script>` (React 19 avisa «Encountered a script tag
//  while rendering React component» y nunca lo ejecutaría) y, como `<html>` se
//  recrea sin la clase `dark`, reaplica el tema guardado al montar.
// ============================================================================

/** Node ≥ 25 define un `localStorage` global sin archivo que tapa el de jsdom: se fija uno en memoria. */
function memoryStorage(): Storage {
  const data = new Map<string, string>();
  return {
    get length() {
      return data.size;
    },
    clear: () => data.clear(),
    getItem: (key) => data.get(key) ?? null,
    key: (index) => [...data.keys()][index] ?? null,
    removeItem: (key) => void data.delete(key),
    setItem: (key, value) => void data.set(key, String(value)),
  };
}

function prefersDark(matches: boolean) {
  vi.stubGlobal(
    "matchMedia",
    vi.fn(() => ({ matches, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  );
}

beforeEach(() => {
  vi.stubGlobal("localStorage", memoryStorage());
  document.documentElement.classList.remove("dark");
});
afterEach(() => vi.unstubAllGlobals());

describe("DarkModeScript (cliente)", () => {
  it("no renderiza ningún <script> ni provoca el aviso de React", () => {
    prefersDark(false);
    const error = vi.spyOn(console, "error").mockImplementation(() => undefined);

    const { container } = render(<DarkModeScript />);

    expect(container.querySelector("script")).toBeNull();
    expect(error).not.toHaveBeenCalled();
    error.mockRestore();
  });

  it("reaplica el tema oscuro guardado al montar", () => {
    prefersDark(false);
    localStorage.setItem("hwe-theme", "dark");

    render(<DarkModeScript />);

    expect(document.documentElement.classList.contains("dark")).toBe(true);
  });

  it("sin tema guardado sigue la preferencia del sistema", () => {
    prefersDark(true);

    render(<DarkModeScript />);

    expect(document.documentElement.classList.contains("dark")).toBe(true);
  });

  it("un tema claro guardado gana a un sistema oscuro", () => {
    prefersDark(true);
    localStorage.setItem("hwe-theme", "light");
    document.documentElement.classList.add("dark");

    render(<DarkModeScript />);

    expect(document.documentElement.classList.contains("dark")).toBe(false);
  });
});
