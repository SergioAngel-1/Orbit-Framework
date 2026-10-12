// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import { ChangePasswordForm } from "./change-password-form";

// El servidor informa de qué pasó con las demás sesiones tras cambiar la contraseña;
// el formulario no debe dar por cerradas unas sesiones que siguen abiertas.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));

function renderForm() {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <ChangePasswordForm />
    </NextIntlClientProvider>,
  );
}

async function submit() {
  const { container } = renderForm();
  const [current, next, confirm] = Array.from(
    container.querySelectorAll<HTMLInputElement>('input[type="password"]'),
  );
  fireEvent.change(current!, { target: { value: "contraseña-vieja" } });
  fireEvent.change(next!, { target: { value: "contraseña-nueva-123" } });
  fireEvent.change(confirm!, { target: { value: "contraseña-nueva-123" } });
  fireEvent.submit(container.querySelector("form")!);
}

const respond = (body: unknown) =>
  vi
    .mocked(csrfFetch)
    .mockResolvedValue(new Response(JSON.stringify(body), { status: 200 }));

beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

describe("ChangePasswordForm", () => {
  it("todo correcto: confirma el cierre de las demás sesiones", async () => {
    respond({ ok: true, otherSessionsRevoked: true, sessionKept: true });
    await submit();

    await waitFor(() =>
      expect(screen.getByText(es.changePassword.success)).toBeTruthy(),
    );
  });

  it("si no se pudieron cerrar las otras sesiones, lo dice y no las da por cerradas", async () => {
    respond({ ok: true, otherSessionsRevoked: false, sessionKept: true });
    await submit();

    await waitFor(() =>
      expect(screen.getByText(es.changePassword.successSessionsPending)).toBeTruthy(),
    );
    expect(screen.queryByText(es.changePassword.success)).toBeNull();
  });

  it("si no se pudo conservar esta sesión, avisa de que hay que volver a entrar", async () => {
    respond({ ok: true, otherSessionsRevoked: true, sessionKept: false });
    await submit();

    await waitFor(() =>
      expect(screen.getByText(es.changePassword.successReloginNeeded)).toBeTruthy(),
    );
  });

  it("una respuesta sin esos campos (servidor antiguo) se trata como correcta", async () => {
    respond({ ok: true });
    await submit();

    await waitFor(() =>
      expect(screen.getByText(es.changePassword.success)).toBeTruthy(),
    );
  });
});
