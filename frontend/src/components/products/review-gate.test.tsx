// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { reviewApi } from "@/lib/client/store-api";
import { ReviewGate } from "./review-gate";

// La ficha ofrece reseñar solo a quien compró el producto y ya lo recibió.
// Cada estado de la elegibilidad dice qué falta, en vez de un formulario que el
// servidor rechazaría.

vi.mock("@/i18n/navigation", () => ({
  Link: ({ href, children }: { href: string; children: ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));
vi.mock("@/lib/client/store-api", () => ({
  reviewApi: { eligibility: vi.fn(), create: vi.fn(), confirmReceived: vi.fn() },
}));

const t = es.products;

function renderGate() {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <ReviewGate productId={9} />
    </NextIntlClientProvider>,
  );
}

beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

describe("ReviewGate", () => {
  it("sin sesión invita a iniciar sesión", async () => {
    vi.mocked(reviewApi.eligibility).mockResolvedValue({
      canReview: false,
      reason: "auth",
    });
    renderGate();

    const link = await screen.findByRole("link", { name: t.reviewLogin });
    expect(link.getAttribute("href")).toBe("/login");
    expect(screen.queryByRole("form")).toBeNull();
  });

  it("sin compra, dice que solo reseñan los compradores", async () => {
    vi.mocked(reviewApi.eligibility).mockResolvedValue({
      canReview: false,
      reason: "not_purchased",
    });
    renderGate();

    expect(await screen.findByText(t.reviewNotPurchased)).toBeTruthy();
  });

  it("con el pedido sin recibir ofrece «Recibí mi producto» y, al confirmarlo, vuelve a preguntar", async () => {
    vi.mocked(reviewApi.eligibility)
      .mockResolvedValueOnce({
        canReview: false,
        reason: "not_received",
        order: { id: 41, number: "41" },
      })
      .mockResolvedValueOnce({
        canReview: true,
        reason: null,
        reviewerName: "Ana Ruiz",
        order: { id: 41, number: "41", otherProducts: 0 },
      });
    vi.mocked(reviewApi.confirmReceived).mockResolvedValue(true);
    renderGate();

    fireEvent.click(
      await screen.findByRole("button", { name: t.reviewConfirmReceived }),
    );

    await waitFor(() => expect(reviewApi.confirmReceived).toHaveBeenCalledWith(41));
    expect(await screen.findByText(/Ana Ruiz/)).toBeTruthy();
    expect(reviewApi.eligibility).toHaveBeenCalledTimes(2);
  });

  it("ya reseñado, lo dice y no ofrece el formulario", async () => {
    vi.mocked(reviewApi.eligibility).mockResolvedValue({
      canReview: false,
      reason: "already_reviewed",
    });
    renderGate();

    expect(await screen.findByText(t.reviewAlready)).toBeTruthy();
  });

  it("elegible: formulario con el nombre con el que publicará y la opción de los demás productos", async () => {
    vi.mocked(reviewApi.eligibility).mockResolvedValue({
      canReview: true,
      reason: null,
      reviewerName: "Ana Ruiz",
      order: { id: 40, number: "40", otherProducts: 2 },
    });
    vi.mocked(reviewApi.create).mockResolvedValue({ id: 5, othersReviewed: 2 });
    renderGate();

    expect(await screen.findByText(/Ana Ruiz/)).toBeTruthy();
    fireEvent.change(screen.getByPlaceholderText(t.reviewPlaceholder), {
      target: { value: "Muy buen producto, lo recomiendo." },
    });
    fireEvent.click(screen.getByLabelText(/misma reseña/i));
    fireEvent.click(screen.getByRole("button", { name: t.reviewSubmit }));

    await waitFor(() =>
      expect(reviewApi.create).toHaveBeenCalledWith(9, {
        rating: 5,
        content: "Muy buen producto, lo recomiendo.",
        sameForOrder: true,
      }),
    );
    expect(await screen.findByText(t.reviewSubmitted)).toBeTruthy();
  });

  it("sin otros productos pendientes no ofrece publicarla en ellos", async () => {
    vi.mocked(reviewApi.eligibility).mockResolvedValue({
      canReview: true,
      reason: null,
      reviewerName: "Ana Ruiz",
      order: { id: 40, number: "40", otherProducts: 0 },
    });
    renderGate();

    await screen.findByText(/Ana Ruiz/);
    expect(screen.queryByLabelText(/misma reseña/i)).toBeNull();
  });
});
