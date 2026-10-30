// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import type { SavedAddress } from "@/types/address";
import { AddressManager } from "./address-manager";

// El formulario apunta a una dirección por su `id`: si la lista cambia mientras se edita, el
// guardado no puede caer sobre la que haya quedado en esa posición.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));

const address = (id: string, street: string): SavedAddress => ({
  id,
  first_name: "Ana",
  last_name: "Ruiz",
  company: "",
  address_1: street,
  address_2: "",
  city: "Lima",
  state: "",
  postcode: "",
  country: "PE",
  phone: "",
  is_default: false,
  label: "",
});

let book: SavedAddress[];

async function renderManager() {
  render(
    <NextIntlClientProvider locale="es" messages={es}>
      <AddressManager />
    </NextIntlClientProvider>,
  );
  await screen.findByText(/Calle A/);
}

const lastCall = () => vi.mocked(csrfFetch).mock.calls.at(-1)![1] as Record<string, unknown>;

beforeEach(() => {
  book = [address("a", "Calle A"), address("b", "Calle B")];
  vi.stubGlobal("confirm", () => true);
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => new Response(JSON.stringify(book), { status: 200 })),
  );
  vi.mocked(csrfFetch).mockReset();
  vi.mocked(csrfFetch).mockImplementation(async () => new Response("{}", { status: 200 }));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("AddressManager", () => {
  it("borra por `id`", async () => {
    await renderManager();

    fireEvent.click(screen.getAllByRole("button", { name: es.addresses.delete })[1]!);

    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
    expect(lastCall()).toMatchObject({ method: "DELETE", body: { id: "b" } });
  });

  it("borrar otra dirección no cambia a cuál apunta la edición abierta", async () => {
    await renderManager();
    fireEvent.click(screen.getAllByRole("button", { name: es.addresses.edit })[1]!);

    book = [address("b", "Calle B")];
    fireEvent.click(screen.getAllByRole("button", { name: es.addresses.delete })[0]!);
    await waitFor(() => expect(screen.queryByText(/Calle A/)).toBeNull());

    fireEvent.click(screen.getByRole("button", { name: es.addresses.save }));

    await waitFor(() =>
      expect(lastCall()).toMatchObject({ method: "PUT", body: { id: "b" } }),
    );
  });

  it("borrar la dirección en edición cierra el formulario", async () => {
    await renderManager();
    fireEvent.click(screen.getAllByRole("button", { name: es.addresses.edit })[0]!);
    expect(screen.getByRole("button", { name: es.addresses.save })).toBeTruthy();

    book = [address("b", "Calle B")];
    fireEvent.click(screen.getAllByRole("button", { name: es.addresses.delete })[0]!);

    await waitFor(() =>
      expect(screen.queryByRole("button", { name: es.addresses.save })).toBeNull(),
    );
  });
});
