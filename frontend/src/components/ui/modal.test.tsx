// @vitest-environment jsdom
import { beforeAll, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { Modal } from "./modal";

// ============================================================================
//  Cierre del modal por click en el backdrop.
//
//  El click en el backdrop es un atajo de ratón sobre <dialog>. La detección
//  usa el `target` y, después, las coordenadas contra el rectángulo del diálogo.
//  En jsdom `getBoundingClientRect()` devuelve todo a 0, así que cualquier
//  click con clientX/Y > 0 cae «fuera» y clientX/Y = 0 cae «dentro».
// ============================================================================

beforeAll(() => {
  // jsdom no implementa showModal()/close(): se simulan marcando `open`.
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", {
    configurable: true,
    value(this: HTMLDialogElement) {
      this.setAttribute("open", "");
    },
  });
  Object.defineProperty(HTMLDialogElement.prototype, "close", {
    configurable: true,
    value(this: HTMLDialogElement) {
      this.removeAttribute("open");
    },
  });
});

function renderModal(props: Partial<React.ComponentProps<typeof Modal>> = {}) {
  const onClose = vi.fn();
  const { container } = render(
    <Modal
      open
      onClose={onClose}
      title="Título"
      footer={<button type="button">Aceptar</button>}
      {...props}
    >
      <p>Contenido</p>
    </Modal>,
  );
  const dialog = container.querySelector("dialog") as HTMLDialogElement;
  return { onClose, dialog };
}

describe("Modal: cierre por click en el backdrop", () => {
  it("un click sobre el propio <dialog> fuera de su rectángulo cierra", () => {
    const { onClose, dialog } = renderModal();

    fireEvent.click(dialog, { clientX: 10, clientY: 10 });

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("un click sobre el propio <dialog> dentro de su rectángulo no cierra", () => {
    const { onClose, dialog } = renderModal();

    fireEvent.click(dialog, { clientX: 0, clientY: 0 });

    expect(onClose).not.toHaveBeenCalled();
  });

  it("un click en el contenido no cierra, aunque sus coordenadas caigan «fuera»", () => {
    const { onClose } = renderModal();

    fireEvent.click(screen.getByText("Contenido"), { clientX: 10, clientY: 10 });

    expect(onClose).not.toHaveBeenCalled();
  });

  it("el «click» de teclado sobre un botón del pie no cierra", () => {
    // Enter/Espacio sobre un botón genera un click con clientX/Y = 0 en los
    // navegadores; aquí se fuerza una coordenada «fuera» para probar que manda
    // el `target`, no las coordenadas.
    const { onClose } = renderModal();

    fireEvent.click(screen.getByRole("button", { name: "Aceptar", hidden: true }), {
      clientX: 10,
      clientY: 10,
    });

    expect(onClose).not.toHaveBeenCalled();
  });

  it("con preventBackdropClose el backdrop no cierra", () => {
    const { onClose, dialog } = renderModal({ preventBackdropClose: true });

    fireEvent.click(dialog, { clientX: 10, clientY: 10 });

    expect(onClose).not.toHaveBeenCalled();
  });

  it("el botón «Cerrar» cierra", () => {
    const { onClose } = renderModal();

    fireEvent.click(screen.getByRole("button", { name: "Cerrar", hidden: true }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
