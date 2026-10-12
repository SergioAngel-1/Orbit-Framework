// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { Button } from "./button";

// ============================================================================
//  Test de componente de referencia: demuestra el entorno `jsdom` (activado por
//  archivo con el docblock de arriba; el resto de tests corre en `node`) y los
//  tests colocados junto al código (`src/**/*.test.tsx`).
// ============================================================================

describe("Button", () => {
  it("se pinta en jsdom como un <button> con su texto", () => {
    render(<Button>Comprar</Button>);

    const button = screen.getByRole("button", { name: "Comprar" });
    expect(button.tagName).toBe("BUTTON");
    expect(typeof document).toBe("object");
  });

  it("dispara onClick al pulsarlo", () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Comprar</Button>);

    // getByRole falla si hubiera más de un botón: prueba también que Testing
    // Library limpia el DOM entre tests.
    fireEvent.click(screen.getByRole("button"));

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it("en carga se bloquea, anuncia el estado y no dispara onClick", () => {
    const onClick = vi.fn();
    render(
      <Button loading onClick={onClick}>
        Comprar
      </Button>,
    );

    const button = screen.getByRole("button") as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(screen.getByRole("status", { name: "Cargando…" })).toBeTruthy();

    fireEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it("fullWidth ocupa el ancho del contenedor", () => {
    render(<Button fullWidth>Comprar</Button>);

    expect(screen.getByRole("button").className).toContain("w-full");
  });
});
