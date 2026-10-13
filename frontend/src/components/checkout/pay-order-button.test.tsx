// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { csrfFetch } from "@/lib/client/csrf";
import { PayOrderButton } from "./pay-order-button";

// Reintenta el cobro de un pedido en línea que sigue sin pagar: pide a la
// pasarela activa un cobro nuevo y lleva al cliente a ella.

vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));
vi.mock("@/i18n/navigation", () => ({
  getPathname: ({ href }: { href: string }) => href,
}));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

function renderButton() {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <PayOrderButton orderId={42} />
    </NextIntlClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(window, "location", {
    value: {
      href: "https://tienda.example/checkout/return?ref=42",
      origin: "https://tienda.example",
    },
    writable: true,
  });
});
afterEach(cleanup);

describe("PayOrderButton", () => {
  it("pide el cobro del pedido con la URL de «gracias» y lleva a la pasarela", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json({ mode: "redirect", redirectUrl: "https://pay.test/x" }),
    );
    renderButton();

    fireEvent.click(screen.getByRole("button", { name: es.checkout.return.payNow }));

    await waitFor(() => expect(window.location.href).toBe("https://pay.test/x"));
    expect(csrfFetch).toHaveBeenCalledWith("/api/payments/create", {
      method: "POST",
      body: {
        reference: 42,
        returnUrl: "https://tienda.example/checkout/return?ref=42",
      },
    });
  });

  it("si el cobro no arranca lo avisa y deja volver a intentarlo", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(json({ error: "sin pasarela" }, 503));
    renderButton();

    fireEvent.click(screen.getByRole("button", { name: es.checkout.return.payNow }));

    await waitFor(() =>
      expect(screen.getByRole("alert").textContent).toBe(es.checkout.paymentNotStarted),
    );
    expect(
      (
        screen.getByRole("button", {
          name: es.checkout.return.payNow,
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(false);
  });
});
