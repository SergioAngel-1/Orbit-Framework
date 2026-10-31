// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import { DARK_MODE_SCRIPT, DarkModeScript, DarkModeToggle } from "./dark-mode-toggle";

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
  document.documentElement.removeAttribute("data-color-scheme");
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

describe("con localStorage bloqueado (navegadores internos, políticas)", () => {
  function blockedStorage() {
    vi.stubGlobal("localStorage", {
      getItem: () => {
        throw new Error("bloqueado");
      },
      setItem: () => {
        throw new Error("bloqueado");
      },
    });
  }

  it("el componente sigue la preferencia del sistema", () => {
    blockedStorage();
    prefersDark(true);

    render(<DarkModeScript />);

    expect(document.documentElement.classList.contains("dark")).toBe(true);
  });

  it("el script inline también: la excepción no se lo salta", () => {
    blockedStorage();
    prefersDark(true);
    new Function(DARK_MODE_SCRIPT)();

    expect(document.documentElement.classList.contains("dark")).toBe(true);
  });
});

describe("con el esquema fijado (`<html data-color-scheme>`)", () => {
  beforeEach(() => {
    document.documentElement.setAttribute("data-color-scheme", "light");
    prefersDark(true);
    localStorage.setItem("hwe-theme", "dark");
  });

  it("no lee la preferencia guardada ni la del sistema", () => {
    render(<DarkModeScript />);

    expect(document.documentElement.classList.contains("dark")).toBe(false);
  });

  it("no ofrece el selector", () => {
    const { container } = render(<DarkModeToggle />);

    expect(container.querySelector("button")).toBeNull();
  });

  it("sin el atributo, el selector sigue ahí", () => {
    document.documentElement.removeAttribute("data-color-scheme");

    const { container } = render(<DarkModeToggle />);

    expect(container.querySelector("button")).not.toBeNull();
  });
});
