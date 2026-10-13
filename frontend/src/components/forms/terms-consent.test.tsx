// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { TermsConsent } from "./terms-consent";

vi.mock("@/i18n/navigation", () => ({
  Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

afterEach(cleanup);

function view(props: Partial<Parameters<typeof TermsConsent>[0]> = {}) {
  const onChange = vi.fn();
  render(
    <NextIntlClientProvider locale="es" messages={es}>
      <TermsConsent checked={false} onChange={onChange} {...props} />
    </NextIntlClientProvider>,
  );
  return onChange;
}

describe("TermsConsent", () => {
  it("es una casilla obligatoria que avisa del cambio", () => {
    const onChange = view();

    const box = screen.getByRole("checkbox") as HTMLInputElement;
    expect(box.required).toBe(true);
    fireEvent.click(box);

    expect(onChange).toHaveBeenCalledWith(true);
  });

  it("enlaza los términos y la privacidad en otra pestaña", () => {
    view();

    const terms = screen.getByRole("link", { name: /términos y condiciones/ });
    const privacy = screen.getByRole("link", { name: /política de privacidad/ });
    expect(terms.getAttribute("href")).toBe("/legal/terms");
    expect(privacy.getAttribute("href")).toBe("/legal/privacy");
    expect(terms.getAttribute("target")).toBe("_blank");
  });

  it("pinta el error enlazado a la casilla", () => {
    view({ error: "Debes aceptar los términos para continuar." });

    const box = screen.getByRole("checkbox");
    expect(box.getAttribute("aria-invalid")).toBe("true");
    expect(box.getAttribute("aria-describedby")).toBe(screen.getByRole("alert").id);
  });
});
