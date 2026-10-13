// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { cartApi, reviewApi } from "@/lib/client/store-api";
import { OrderActions } from "./order-actions";

// Las acciones de un pedido en la cuenta: volver a pedir, «Recibí mi pedido» y
// calificarlo. Lo que se ofrece lo decide la vista con el estado del pedido.

const refreshRouter = vi.fn();
const refreshCart = vi.fn(async () => undefined);
const openDrawer = vi.fn();

vi.mock("@/i18n/navigation", () => ({ useRouter: () => ({ refresh: refreshRouter }) }));
vi.mock("@/components/cart/cart-context", () => ({
  useCart: () => ({ refresh: refreshCart, openDrawer }),
}));
vi.mock("@/lib/client/store-api", () => ({
  cartApi: { reorder: vi.fn() },
  reviewApi: { confirmReceived: vi.fn(), rateOrder: vi.fn() },
}));

type Props = Parameters<typeof OrderActions>[0];

function renderActions(props: Partial<Props> = {}) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <OrderActions orderId={10} reorder review={null} {...props} />
    </NextIntlClientProvider>,
  );
}

const t = es.account.orderActions;

beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

describe("volver a pedir", () => {
  it("añade lo que se puede comprar, abre el carrito y dice qué no entró", async () => {
    vi.mocked(cartApi.reorder).mockResolvedValue({
      cart: {} as never,
      added: [{ productId: 3, name: "Camiseta", quantity: 2 }],
      skipped: [{ name: "Gorra", reason: "out_of_stock" }],
    });
    renderActions();

    fireEvent.click(screen.getByRole("button", { name: t.reorder }));

    await waitFor(() => expect(openDrawer).toHaveBeenCalled());
    expect(cartApi.reorder).toHaveBeenCalledWith(10);
    expect(refreshCart).toHaveBeenCalled();
    expect(screen.getByRole("status").textContent).toContain("Gorra");
    expect(screen.getByRole("status").textContent).toContain(t.skipped.out_of_stock);
  });

  it("si no entra nada, lo dice y no abre el carrito", async () => {
    vi.mocked(cartApi.reorder).mockResolvedValue({
      cart: {} as never,
      added: [],
      skipped: [{ name: "Gorra", reason: "unavailable" }],
    });
    renderActions();

    fireEvent.click(screen.getByRole("button", { name: t.reorder }));

    await waitFor(() => expect(screen.getByRole("status")).toBeTruthy());
    expect(openDrawer).not.toHaveBeenCalled();
  });

  it("un error de la API se muestra como alerta", async () => {
    vi.mocked(cartApi.reorder).mockRejectedValue(new Error("caído"));
    renderActions();

    fireEvent.click(screen.getByRole("button", { name: t.reorder }));

    await waitFor(() => expect(screen.getByRole("alert").textContent).toBe("caído"));
  });
});

describe("«Recibí mi pedido»", () => {
  it("lo ofrece solo en el modo `receive` y repinta la vista al confirmarlo", async () => {
    vi.mocked(reviewApi.confirmReceived).mockResolvedValue(true);
    renderActions({ review: { mode: "receive", productCount: 0 } });

    fireEvent.click(screen.getByRole("button", { name: t.received }));

    await waitFor(() => expect(refreshRouter).toHaveBeenCalled());
    expect(reviewApi.confirmReceived).toHaveBeenCalledWith(10);
  });

  it("sin modo `receive` no aparece", () => {
    renderActions({ review: { mode: "rate", productCount: 0 } });

    expect(screen.queryByRole("button", { name: t.received })).toBeNull();
  });
});

describe("calificar el pedido", () => {
  it("manda la nota y el texto, con los productos si se marca, y repinta", async () => {
    vi.mocked(reviewApi.rateOrder).mockResolvedValue({
      ok: true,
      review: { rating: 4, content: "x", date: "" },
      productsReviewed: 2,
    });
    renderActions({ review: { mode: "rate", productCount: 2 } });

    fireEvent.click(screen.getByRole("button", { name: t.rate }));
    fireEvent.change(screen.getByLabelText(t.rating), { target: { value: "4" } });
    fireEvent.change(screen.getByLabelText(t.comment), {
      target: { value: "Todo llegó perfecto y a tiempo." },
    });
    fireEvent.click(screen.getByLabelText(/Publicar también/));
    fireEvent.click(screen.getByRole("button", { name: t.submitRating }));

    await waitFor(() => expect(refreshRouter).toHaveBeenCalled());
    expect(reviewApi.rateOrder).toHaveBeenCalledWith(10, {
      rating: 4,
      content: "Todo llegó perfecto y a tiempo.",
      products: true,
    });
  });

  it("sin productos pendientes no ofrece publicarla en ellos", () => {
    renderActions({ review: { mode: "rate", productCount: 0 } });

    fireEvent.click(screen.getByRole("button", { name: t.rate }));

    expect(screen.queryByLabelText(/Publicar también/)).toBeNull();
  });

  it("si ya estaba calificado, lo dice", async () => {
    vi.mocked(reviewApi.rateOrder).mockResolvedValue({
      ok: false,
      alreadyReviewed: true,
    });
    renderActions({ review: { mode: "rate", productCount: 0 } });

    fireEvent.click(screen.getByRole("button", { name: t.rate }));
    fireEvent.change(screen.getByLabelText(t.comment), {
      target: { value: "Todo llegó perfecto y a tiempo." },
    });
    fireEvent.click(screen.getByRole("button", { name: t.submitRating }));

    await waitFor(() =>
      expect(screen.getByRole("alert").textContent).toBe(t.alreadyRated),
    );
  });
});
