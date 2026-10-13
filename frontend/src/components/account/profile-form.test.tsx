// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import { EMPTY_IDENTITY } from "@/lib/customer/identity";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import { ProfileForm } from "./profile-form";

// El documento se escribe UNA vez: editable mientras la cuenta no tenga uno,
// bloqueado en cuanto se guarda (y WordPress lo impone).

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });
const INITIAL = { first_name: "Ana", last_name: "Ruiz" };
const TYPES = CO_PACK.documentTypes;

function renderForm(identity?: Parameters<typeof ProfileForm>[0]["identity"]) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <ProfileForm initial={INITIAL} identity={identity} />
    </NextIntlClientProvider>,
  );
}

const docInput = (c: HTMLElement) =>
  c.querySelector('input[name="documentNumber"]') as HTMLInputElement | null;
const lastBody = () =>
  (vi.mocked(csrfFetch).mock.calls.at(-1)?.[1] as { body: Record<string, unknown> })
    .body;

beforeEach(() => {
  vi.mocked(csrfFetch).mockReset();
  vi.mocked(csrfFetch).mockResolvedValue(json({ id: 7, meta_data: [] }));
});
afterEach(cleanup);

describe("ProfileForm — documento", () => {
  it("sin el módulo no pinta el campo", () => {
    const { container } = renderForm();

    expect(docInput(container)).toBeNull();
  });

  it("sin documento en la cuenta es editable y opcional; vacío no se envía", async () => {
    const { container } = renderForm({ documentTypes: TYPES, stored: EMPTY_IDENTITY });

    expect(docInput(container)!.required).toBe(false);
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());

    expect(lastBody()).not.toHaveProperty("identity");
  });

  it("lo escrito se envía y, una vez guardado, el campo queda bloqueado", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json({
        id: 7,
        meta_data: [
          { key: "billing_document_type", value: "CC" },
          { key: "billing_document", value: "1012345678" },
        ],
      }),
    );
    const { container } = renderForm({ documentTypes: TYPES, stored: EMPTY_IDENTITY });

    fireEvent.change(docInput(container)!, { target: { value: "1.012.345.678" } });
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());

    expect(lastBody()).toMatchObject({
      identity: { documentType: "CC", documentNumber: "1.012.345.678" },
    });
    const locked = (await screen.findByLabelText(
      "Número de documento",
    )) as HTMLInputElement;
    await waitFor(() => expect(locked.disabled).toBe(true));
    expect(locked.value).toContain("1012345678");
  });

  it("con documento en la cuenta nace bloqueado y no se envía", async () => {
    const { container } = renderForm({
      documentTypes: TYPES,
      stored: {
        documentType: "CC",
        documentNumber: "1098765432",
        termsAcceptedAt: null,
      },
    });

    expect(
      (screen.getByLabelText("Número de documento") as HTMLInputElement).disabled,
    ).toBe(true);
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());

    expect(lastBody()).not.toHaveProperty("identity");
  });

  it("un 409 del servidor sale bajo el campo y no lo bloquea", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json(
        {
          error: "Este documento ya está registrado.",
          details: { identity: ["Este documento ya está registrado."] },
        },
        409,
      ),
    );
    const { container } = renderForm({ documentTypes: TYPES, stored: EMPTY_IDENTITY });

    fireEvent.change(docInput(container)!, { target: { value: "1012345678" } });
    fireEvent.submit(container.querySelector("form")!);

    await screen.findAllByText("Este documento ya está registrado.");
    expect(docInput(container)!.disabled).toBe(false);
    expect(docInput(container)!.getAttribute("aria-invalid")).toBe("true");
  });

  it("en un país sin tipos de documento no pinta el campo", () => {
    const { container } = renderForm({ documentTypes: [], stored: EMPTY_IDENTITY });

    expect(docInput(container)).toBeNull();
  });
});
