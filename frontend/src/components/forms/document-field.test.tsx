// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import { DocumentField } from "./document-field";

afterEach(cleanup);

const TYPES = [
  ...CO_PACK.documentTypes,
  { code: "NIT", label: "NIT", pattern: "^\\d{9,10}$" },
];

function field(props: Partial<Parameters<typeof DocumentField>[0]> = {}) {
  const onTypeChange = vi.fn();
  const onNumberChange = vi.fn();
  const view = render(
    <NextIntlClientProvider locale="es" messages={es}>
      <DocumentField
        documentTypes={TYPES}
        documentType="CC"
        documentNumber=""
        onTypeChange={onTypeChange}
        onNumberChange={onNumberChange}
        {...props}
      />
    </NextIntlClientProvider>,
  );
  return { ...view, onTypeChange, onNumberChange };
}

describe("DocumentField", () => {
  it("ofrece los tipos del paquete y avisa de los cambios", () => {
    const { onTypeChange, onNumberChange } = field();

    const select = screen.getByLabelText("Tipo de documento") as HTMLSelectElement;
    expect([...select.options].map((o) => o.value)).toEqual(["CC", "NIT"]);
    fireEvent.change(select, { target: { value: "NIT" } });
    fireEvent.change(screen.getByLabelText(/Documento de identidad/), {
      target: { value: "900123456" },
    });

    expect(onTypeChange).toHaveBeenCalledWith("NIT");
    expect(onNumberChange).toHaveBeenCalledWith("900123456");
  });

  it("dice para qué se pide y marca el campo como obligatorio salvo que no lo sea", () => {
    field();
    expect(screen.getByText("Lo pedimos para facturar tu compra.")).not.toBeNull();
    expect(
      (screen.getByLabelText(/Documento de identidad/) as HTMLInputElement).required,
    ).toBe(true);
    cleanup();

    field({ required: false });
    expect(
      (screen.getByLabelText(/Documento de identidad/) as HTMLInputElement).required,
    ).toBe(false);
  });

  it("pinta el error enlazado al campo", () => {
    field({ error: "Este documento ya está registrado." });

    const input = screen.getByLabelText(/Documento de identidad/);
    expect(input.getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByRole("alert").textContent).toBe(
      "Este documento ya está registrado.",
    );
    expect(input.getAttribute("aria-describedby")).toBe(screen.getByRole("alert").id);
  });

  it("bloqueado: solo lectura, con el nombre del tipo, y sin selector", () => {
    field({ locked: true, documentType: "CC", documentNumber: "1012345678" });

    const input = screen.getByLabelText("Número de documento") as HTMLInputElement;
    expect(input.value).toBe("Cédula de ciudadanía · 1012345678");
    expect(input.disabled).toBe(true);
    expect(input.readOnly).toBe(true);
    expect(screen.queryByLabelText("Tipo de documento")).toBeNull();
    expect(screen.getByText(/No se puede cambiar desde el sitio/)).not.toBeNull();
  });

  it("sin tipos en el país no pinta nada", () => {
    const { container } = field({ documentTypes: [] });

    expect(container.innerHTML).toBe("");
  });
});
