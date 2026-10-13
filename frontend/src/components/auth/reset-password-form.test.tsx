// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import { ResetPasswordForm } from "./reset-password-form";

// Regresión: la clave del enlace llegaba en la prop `key`, que React se reserva
// y nunca pasa al componente: el formulario enviaba `key: undefined` y
// restablecer la contraseña fallaba siempre.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));
const push = vi.fn();
vi.mock("@/i18n/navigation", () => ({ useRouter: () => ({ push, refresh: vi.fn() }) }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function fill(container: HTMLElement, password: string, confirm: string) {
  const [pw, cf] = Array.from(
    container.querySelectorAll<HTMLInputElement>('input[type="password"]'),
  );
  fireEvent.change(pw!, { target: { value: password } });
  fireEvent.change(cf!, { target: { value: confirm } });
  fireEvent.submit(container.querySelector("form")!);
}

describe("ResetPasswordForm", () => {
  it("envía la clave y el login del enlace", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(new Response("{}", { status: 200 }));
    const { container } = render(
      <NextIntlClientProvider locale="es" messages={es}>
        <ResetPasswordForm resetKey="clave-del-correo" login="ana" />
      </NextIntlClientProvider>,
    );

    fill(container, "una-clave-nueva", "una-clave-nueva");

    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
    expect(vi.mocked(csrfFetch).mock.calls[0][1]?.body).toEqual({
      key: "clave-del-correo",
      login: "ana",
      password: "una-clave-nueva",
    });
    await waitFor(() => expect(push).toHaveBeenCalledWith("/login"));
  });

  it("si no coinciden, avisa y no envía nada", async () => {
    const { container } = render(
      <NextIntlClientProvider locale="es" messages={es}>
        <ResetPasswordForm resetKey="k" login="ana" />
      </NextIntlClientProvider>,
    );

    fill(container, "una-clave-nueva", "otra-distinta-1");

    expect(await screen.findAllByText(es.form.passwordsDontMatch)).not.toHaveLength(0);
    expect(csrfFetch).not.toHaveBeenCalled();
  });
});
