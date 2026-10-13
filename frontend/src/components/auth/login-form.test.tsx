// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { LoginForm } from "./login-form";

const push = vi.fn();
const refresh = vi.fn();
vi.mock("@/i18n/navigation", () => ({
  useRouter: () => ({ push, refresh }),
  Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));
vi.mock("next-intl", () => ({ useTranslations: () => (key: string) => key }));
const csrfFetch = vi.fn();
vi.mock("@/lib/client/csrf", () => ({
  csrfFetch: (...a: unknown[]) => csrfFetch(...a),
}));

beforeEach(() => {
  push.mockReset();
  refresh.mockReset();
  csrfFetch.mockReset();
});

async function submit() {
  fireEvent.change(screen.getByLabelText(/username/i), { target: { value: "ana" } });
  fireEvent.change(screen.getByLabelText(/password/i), {
    target: { value: "secreto" },
  });
  fireEvent.submit(screen.getByRole("button", { name: "submit" }).closest("form")!);
}

describe("LoginForm — vuelta tras entrar", () => {
  it("sin destino, a la cuenta", async () => {
    csrfFetch.mockResolvedValue({
      ok: true,
      json: async () => ({ user: { id: "1" } }),
    });
    render(<LoginForm />);

    await submit();

    await waitFor(() => expect(push).toHaveBeenCalledWith("/account"));
  });

  it("con `returnTo`, vuelve ahí (el servidor ya lo validó)", async () => {
    csrfFetch.mockResolvedValue({
      ok: true,
      json: async () => ({ user: { id: "1" } }),
    });
    render(<LoginForm returnTo="/checkout" />);

    await submit();

    await waitFor(() => expect(push).toHaveBeenCalledWith("/checkout"));
  });
});
