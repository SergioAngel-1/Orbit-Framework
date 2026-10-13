// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import { RegisterForm } from "./register-form";

// El registro pide documento y términos solo con el módulo `customer-identity`
// (prop `identity`); sin ella es el formulario de siempre.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));
vi.mock("@/i18n/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
  Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

function renderForm(props: Parameters<typeof RegisterForm>[0] = {}) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <RegisterForm {...props} />
    </NextIntlClientProvider>,
  );
}

function fillBasics(container: HTMLElement) {
  const set = (name: string, value: string) =>
    fireEvent.change(container.querySelector(`[name="${name}"]`)!, {
      target: { value },
    });
  set("username", "ana");
  set("email", "ana@example.com");
  set("password", "una-clave-larga-1");
  set("confirm", "una-clave-larga-1");
}

const lastBody = () =>
  (vi.mocked(csrfFetch).mock.calls.at(-1)?.[1] as { body: Record<string, unknown> })
    .body;

beforeEach(() => {
  vi.mocked(csrfFetch).mockReset();
  vi.mocked(csrfFetch).mockResolvedValue(json({ user: {} }, 201));
});
afterEach(cleanup);

describe("RegisterForm — documento y términos", () => {
  it("sin el módulo no pide ni envía documento ni términos", async () => {
    const { container } = renderForm();
    fillBasics(container);

    expect(container.querySelector('[name="documentNumber"]')).toBeNull();
    expect(container.querySelector('[name="termsAccepted"]')).toBeNull();
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());

    expect(lastBody()).not.toHaveProperty("identity");
    expect(lastBody()).not.toHaveProperty("terms_accepted");
  });

  it("con el módulo envía el documento y la aceptación", async () => {
    const { container } = renderForm({
      identity: { documentTypes: CO_PACK.documentTypes },
    });
    fillBasics(container);

    fireEvent.change(container.querySelector('[name="documentNumber"]')!, {
      target: { value: "1012345678" },
    });
    fireEvent.click(container.querySelector('[name="termsAccepted"]')!);
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());

    expect(lastBody()).toMatchObject({
      identity: { documentType: "CC", documentNumber: "1012345678" },
      terms_accepted: true,
    });
  });

  it("en un país sin documentos solo pide los términos", () => {
    const { container } = renderForm({ identity: { documentTypes: [] } });

    expect(container.querySelector('[name="documentNumber"]')).toBeNull();
    expect(container.querySelector('[name="termsAccepted"]')).not.toBeNull();
  });

  it("el documento de otra cuenta se avisa bajo su campo", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json(
        {
          error: "Este documento ya está registrado.",
          details: { identity: ["Este documento ya está registrado."] },
        },
        409,
      ),
    );
    const { container } = renderForm({
      identity: { documentTypes: CO_PACK.documentTypes },
    });
    fillBasics(container);
    fireEvent.change(container.querySelector('[name="documentNumber"]')!, {
      target: { value: "1012345678" },
    });
    fireEvent.click(container.querySelector('[name="termsAccepted"]')!);
    fireEvent.submit(container.querySelector("form")!);

    await screen.findAllByText("Este documento ya está registrado.");
    expect(
      container.querySelector('[name="documentNumber"]')!.getAttribute("aria-invalid"),
    ).toBe("true");
  });
});
