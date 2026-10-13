// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { GuestAccountInvite } from "./guest-account-invite";

vi.mock("@/i18n/navigation", () => ({
  Link: ({
    href,
    children,
    ...rest
  }: {
    href: string | { pathname: string; query: Record<string, string> };
    children: ReactNode;
  }) => (
    <a
      href={
        typeof href === "string"
          ? href
          : `${href.pathname}?${new URLSearchParams(href.query).toString()}`
      }
      {...rest}
    >
      {children}
    </a>
  ),
}));

const copy = {
  title: "Guarda tu próximo pedido",
  body: "Con una cuenta sigues el estado de tus pedidos.",
  login: "Iniciar sesión",
  register: "Crear cuenta",
};

describe("GuestAccountInvite", () => {
  it("es una sección con nombre propio", () => {
    render(<GuestAccountInvite copy={copy} />);

    expect(screen.getByRole("complementary", { name: copy.title })).toBeTruthy();
  });

  it("el acceso lleva al login y de vuelta a «Mis pedidos», donde ya está el pedido", () => {
    render(<GuestAccountInvite copy={copy} />);

    expect(screen.getByRole("link", { name: copy.login }).getAttribute("href")).toBe(
      "/login?return=%2Faccount%2Forders",
    );
  });

  it("ofrece crear una cuenta", () => {
    render(<GuestAccountInvite copy={copy} />);

    expect(screen.getByRole("link", { name: copy.register }).getAttribute("href")).toBe(
      "/register?return=%2Faccount%2Forders",
    );
  });
});
