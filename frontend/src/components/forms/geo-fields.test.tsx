// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { GENERIC_PACK } from "@/lib/geo/generic";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import { CityField, DivisionField } from "./geo-fields";

afterEach(cleanup);

function division(props: Partial<Parameters<typeof DivisionField>[0]> = {}) {
  const onChange = vi.fn();
  render(
    <NextIntlClientProvider locale="es" messages={es}>
      <DivisionField
        id="d"
        name="state"
        label="Departamento"
        pack={CO_PACK}
        city=""
        country="CO"
        value=""
        onChange={onChange}
        {...props}
      />
    </NextIntlClientProvider>,
  );
  return onChange;
}

describe("CityField", () => {
  it("ofrece las ciudades del paquete como sugerencia sin cerrar la lista", () => {
    const onChange = vi.fn();
    render(
      <CityField
        id="c"
        name="city"
        label="Ciudad"
        pack={CO_PACK}
        value=""
        onChange={onChange}
      />,
    );

    const input = screen.getByLabelText("Ciudad");
    expect(input.getAttribute("list")).toBe("c-options");
    expect(document.querySelectorAll("#c-options option")).toHaveLength(3);
    fireEvent.change(input, { target: { value: "Pasto" } });
    expect(onChange).toHaveBeenCalledWith("Pasto");
  });

  it("sin ciudades en el paquete es un campo de texto sin lista", () => {
    render(
      <CityField
        id="c"
        name="city"
        label="Ciudad"
        pack={GENERIC_PACK}
        value=""
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByLabelText("Ciudad").getAttribute("list")).toBeNull();
    expect(document.querySelector("datalist")).toBeNull();
  });
});

describe("DivisionField", () => {
  it("con lista cerrada es un select con todas las divisiones para una ciudad desconocida", () => {
    const onChange = division({ city: "Pasto" });

    const select = screen.getByLabelText("Departamento") as HTMLSelectElement;
    expect(select.tagName).toBe("SELECT");
    expect([...select.options].map((o) => o.value)).toEqual([
      "",
      "CO-ANT",
      "CO-CUN",
      "CO-DC",
      "CO-NAR",
    ]);
    expect(select.required).toBe(true);
    fireEvent.change(select, { target: { value: "CO-NAR" } });
    expect(onChange).toHaveBeenCalledWith("CO-NAR");
  });

  it("una ciudad conocida deja solo su departamento y lo fija sola", () => {
    const onChange = division({ city: "Medellín" });

    const select = screen.getByLabelText("Departamento") as HTMLSelectElement;
    expect([...select.options].map((o) => o.value)).toEqual(["CO-ANT"]);
    expect(onChange).toHaveBeenCalledWith("CO-ANT", true);
  });

  it("un nombre guardado a mano pasa a su código; uno que no existe se vacía", () => {
    const onChange = division({ city: "Pasto", value: "Nariño" });
    expect(onChange).toHaveBeenCalledWith("CO-NAR", true);
    cleanup();

    const cleared = division({ city: "Pasto", value: "Atlantis" });
    expect(cleared).toHaveBeenCalledWith("", true);
  });

  it("un valor ya correcto no dispara ningún cambio", () => {
    const onChange = division({ city: "Pasto", value: "CO-NAR" });
    expect(onChange).not.toHaveBeenCalled();
  });

  it("fuera del país del paquete, o sin lista, es texto libre", () => {
    division({ country: "ES" });
    expect(screen.getByLabelText("Departamento").tagName).toBe("INPUT");
    cleanup();

    division({ pack: GENERIC_PACK, country: "" });
    expect(screen.getByLabelText("Departamento").tagName).toBe("INPUT");
  });

  it("si el paquete no pide división, no se pinta", () => {
    const pack = {
      ...CO_PACK,
      address: { ...CO_PACK.address, division: "none" as const },
    };
    division({ pack });
    expect(screen.queryByLabelText("Departamento")).toBeNull();
  });
});
