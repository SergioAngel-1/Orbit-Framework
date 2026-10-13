// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import type { EcommerceParams } from "@/lib/analytics/ecommerce";
import { ViewItemTracker } from "./view-item-tracker";

const trackEventMock = vi.fn();
vi.mock("./track-event", () => ({
  trackEvent: (...args: unknown[]) => trackEventMock(...args),
}));

function params(id: string): EcommerceParams {
  return {
    currency: "COP",
    value: 1000,
    items: [{ item_id: id, item_name: "X", price: 1000, quantity: 1 }],
  };
}

beforeEach(() => trackEventMock.mockClear());

describe("ViewItemTracker", () => {
  it("una visita por ficha, aunque se repinte", () => {
    const { rerender } = render(<ViewItemTracker params={params("7")} />);
    rerender(<ViewItemTracker params={params("7")} />);
    expect(trackEventMock).toHaveBeenCalledTimes(1);
    expect(trackEventMock).toHaveBeenCalledWith("view_item", params("7"));
  });

  it("pasar a otra ficha reutilizando el componente cuenta otra visita", () => {
    const { rerender } = render(<ViewItemTracker params={params("7")} />);
    rerender(<ViewItemTracker params={params("8")} />);
    expect(trackEventMock).toHaveBeenCalledTimes(2);
  });
});
