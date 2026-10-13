import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { createSiteReview } from "./site-review-api";

// La llamada server-to-server al plugin `hwe-site-reviews`: las reglas (el
// pedido es de ese usuario, admite reseña, no tiene ya una) las comprueba
// WordPress; aquí solo se traducen sus respuestas.

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));

const json = (body: unknown, status: number) =>
  new Response(JSON.stringify(body), { status });

const input = { orderId: 10, userId: 7, rating: 5, content: "Todo llegó perfecto." };

beforeEach(() => vi.clearAllMocks());

describe("createSiteReview", () => {
  it("manda el pedido, el usuario y la reseña por wpFetch (lleva el secreto interno)", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json(
        { review: { rating: 5, content: "Todo llegó perfecto.", date: "2026-10-13" } },
        201,
      ),
    );

    const result = await createSiteReview(input);

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/site-reviews",
      expect.objectContaining({ method: "POST", cache: "no-store" }),
    );
    const body = JSON.parse(String(vi.mocked(wpFetch).mock.calls[0]![1]!.body));
    expect(body).toEqual({
      order_id: 10,
      user_id: 7,
      rating: 5,
      content: input.content,
    });
    expect(result).toEqual({
      ok: true,
      review: { rating: 5, content: "Todo llegó perfecto.", date: "2026-10-13" },
    });
  });

  it.each([
    [409, { error: "already_reviewed" }, "already_reviewed"],
    [404, { error: "order_not_found" }, "not_found"],
    [422, { error: "order_not_rateable" }, "not_rateable"],
    [422, { error: "invalid_content" }, "invalid"],
    [500, { error: "create_failed" }, "unavailable"],
  ] as const)("%s %j → %s", async (status, body, reason) => {
    vi.mocked(wpFetch).mockResolvedValue(json(body, status));

    expect(await createSiteReview(input)).toEqual({ ok: false, reason });
  });

  it("si WordPress no responde, «no disponible» y no lanza", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("ECONNREFUSED"));

    expect(await createSiteReview(input)).toEqual({ ok: false, reason: "unavailable" });
  });
});
