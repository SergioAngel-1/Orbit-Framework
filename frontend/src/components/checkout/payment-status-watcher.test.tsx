// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render } from "@testing-library/react";
import {
  PAYMENT_STATUS_POLL_DELAYS,
  PaymentStatusWatcher,
} from "./payment-status-watcher";

const refreshMock = vi.fn();

vi.mock("@/i18n/navigation", () => ({
  useRouter: () => ({ refresh: refreshMock }),
}));

function respond(body: { status: string; paid: boolean }) {
  return { ok: true, json: async () => body } as Response;
}

/** Deja correr la espera `attempt` y la consulta que la sigue. */
async function runAttempt(attempt: number) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(PAYMENT_STATUS_POLL_DELAYS[attempt]!);
  });
}

beforeEach(() => {
  vi.useFakeTimers();
  refreshMock.mockClear();
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe("PaymentStatusWatcher", () => {
  it("repinta la página cuando el pedido pasa a pagado", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(respond({ status: "pending", paid: false }))
      .mockResolvedValueOnce(respond({ status: "processing", paid: true }));
    vi.stubGlobal("fetch", fetchMock);

    render(<PaymentStatusWatcher orderId="981" status="pending" />);
    await runAttempt(0);
    expect(refreshMock).not.toHaveBeenCalled();
    await runAttempt(1);

    expect(fetchMock).toHaveBeenCalledWith("/api/payments/return?ref=981", {
      credentials: "same-origin",
      cache: "no-store",
    });
    expect(refreshMock).toHaveBeenCalledTimes(1);
  });

  it("también repinta si el pedido cambia a otro estado (rechazado)", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(respond({ status: "cancelled", paid: false })),
    );
    render(<PaymentStatusWatcher orderId="981" status="pending" />);
    await runAttempt(0);
    expect(refreshMock).toHaveBeenCalledTimes(1);
  });

  it("deja de consultar al agotar las esperas", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(respond({ status: "pending", paid: false }));
    vi.stubGlobal("fetch", fetchMock);

    render(<PaymentStatusWatcher orderId="981" status="pending" />);
    for (let i = 0; i < PAYMENT_STATUS_POLL_DELAYS.length; i++) await runAttempt(i);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(120_000);
    });

    expect(fetchMock).toHaveBeenCalledTimes(PAYMENT_STATUS_POLL_DELAYS.length);
    expect(refreshMock).not.toHaveBeenCalled();
  });

  it("un fallo de red no corta la consulta", async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new Error("offline"))
      .mockResolvedValueOnce(respond({ status: "processing", paid: true }));
    vi.stubGlobal("fetch", fetchMock);

    render(<PaymentStatusWatcher orderId="981" status="pending" />);
    await runAttempt(0);
    await runAttempt(1);
    expect(refreshMock).toHaveBeenCalledTimes(1);
  });
});
