// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import type { EcommerceParams } from "@/lib/analytics/ecommerce";
import {
  PURCHASE_TRACKED_KEY_PREFIX,
  PurchaseTracker,
  purchaseTrackedKey,
} from "./purchase-tracker";

const trackEventMock = vi.fn();
vi.mock("./track-event", () => ({
  trackEvent: (...args: unknown[]) => trackEventMock(...args),
}));

const PARAMS: EcommerceParams = {
  transaction_id: "981",
  currency: "COP",
  value: 90000,
  items: [{ item_id: "7", item_name: "Rescate", price: 45000, quantity: 2 }],
};

// El `localStorage` global de Node enmascara al de jsdom: almacén propio.
let store: Record<string, string> = {};

beforeEach(() => {
  store = {};
  trackEventMock.mockClear();
  vi.stubGlobal("localStorage", {
    getItem: (k: string) => store[k] ?? null,
    setItem: (k: string, v: string) => {
      store[k] = v;
    },
    removeItem: (k: string) => {
      delete store[k];
    },
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("purchaseTrackedKey", () => {
  it("antepone el prefijo estable al order id, en número o texto", () => {
    expect(purchaseTrackedKey(123)).toBe(`${PURCHASE_TRACKED_KEY_PREFIX}123`);
    expect(purchaseTrackedKey(789)).toBe(purchaseTrackedKey("789"));
    expect(purchaseTrackedKey(1)).not.toBe(purchaseTrackedKey(2));
  });
});

describe("PurchaseTracker", () => {
  it("envía la compra una vez y la marca en localStorage", () => {
    render(<PurchaseTracker params={PARAMS} />);
    expect(trackEventMock).toHaveBeenCalledTimes(1);
    expect(trackEventMock).toHaveBeenCalledWith("purchase", PARAMS);
    expect(store[purchaseTrackedKey("981")]).toBe("1");
  });

  it("recargar o abrir gracias en otra pestaña no la vuelve a enviar", () => {
    const first = render(<PurchaseTracker params={PARAMS} />);
    first.unmount();
    render(<PurchaseTracker params={PARAMS} />);
    expect(trackEventMock).toHaveBeenCalledTimes(1);
  });

  it("sin almacenamiento disponible envía igual (deduplica el eventID)", () => {
    vi.stubGlobal("localStorage", {
      getItem: () => {
        throw new Error("SecurityError");
      },
      setItem: () => {
        throw new Error("SecurityError");
      },
    });
    render(<PurchaseTracker params={PARAMS} />);
    expect(trackEventMock).toHaveBeenCalledTimes(1);
  });
});
