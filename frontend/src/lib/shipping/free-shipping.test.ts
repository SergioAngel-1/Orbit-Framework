import { describe, it, expect } from "vitest";
import {
  qualifiesForFreeShipping,
  readFreeShipping,
  resolveFreeShippingNotice,
  type FreeShippingState,
} from "./free-shipping";

const BASE: FreeShippingState = {
  enabled: true,
  threshold: "100000",
  eligible_subtotal: "25000",
  remaining: "75000",
  qualifies: false,
  city: "unknown",
  all_cities: false,
  scoped: false,
};

function cart(state: Partial<FreeShippingState> | null, needsShipping = true) {
  return {
    needs_shipping: needsShipping,
    extensions: state === null ? {} : { hwe_free_shipping: { ...BASE, ...state } },
  };
}

describe("readFreeShipping", () => {
  it("devuelve null sin la extensión (WordPress sin el plugin)", () => {
    expect(readFreeShipping(cart(null))).toBeNull();
    expect(readFreeShipping({})).toBeNull();
    expect(readFreeShipping(null)).toBeNull();
  });

  it("descarta un estado con forma inesperada", () => {
    expect(
      readFreeShipping({
        extensions: { hwe_free_shipping: { ...BASE, remaining: 75000 } },
      }),
    ).toBeNull();
    expect(
      readFreeShipping({
        extensions: { hwe_free_shipping: { ...BASE, city: "madrid" } },
      }),
    ).toBeNull();
    expect(readFreeShipping({ extensions: { hwe_free_shipping: [] } })).toBeNull();
  });

  it("trata como false los flags opcionales que falten", () => {
    const old: Record<string, unknown> = { ...BASE };
    delete old.all_cities;
    delete old.scoped;
    expect(readFreeShipping({ extensions: { hwe_free_shipping: old } })).toMatchObject({
      all_cities: false,
      scoped: false,
    });
  });
});

describe("resolveFreeShippingNotice", () => {
  it("no avisa con la regla apagada, sin extensión o sin envío", () => {
    expect(resolveFreeShippingNotice(cart({ enabled: false }))).toBeNull();
    expect(resolveFreeShippingNotice(cart(null))).toBeNull();
    expect(resolveFreeShippingNotice(cart({}, false))).toBeNull();
    expect(resolveFreeShippingNotice(null)).toBeNull();
  });

  it("a una ciudad que no lo admite no le promete nada: solo dice dónde aplica", () => {
    expect(resolveFreeShippingNotice(cart({ city: "not_eligible" }))).toEqual({
      kind: "otherCities",
    });
    expect(
      resolveFreeShippingNotice(
        cart({ city: "not_eligible", remaining: "0", eligible_subtotal: "150000" }),
      ),
    ).toEqual({ kind: "otherCities" });
  });

  it("dice lo que falta y el progreso", () => {
    expect(resolveFreeShippingNotice(cart({ city: "eligible" }))).toEqual({
      kind: "remaining",
      remaining: "75000",
      progress: 0.25,
      hints: { selectedCities: false, selectedProducts: false },
    });
  });

  it("avisa de las ciudades solo sin ciudad conocida y si no aplican todas", () => {
    const hints = (state: Partial<FreeShippingState>) => {
      const notice = resolveFreeShippingNotice(cart(state));
      return notice && "hints" in notice ? notice.hints : null;
    };
    expect(hints({})).toMatchObject({ selectedCities: true });
    expect(hints({ all_cities: true })).toMatchObject({ selectedCities: false });
    expect(hints({ city: "eligible" })).toMatchObject({ selectedCities: false });
    expect(hints({ scoped: true })).toMatchObject({ selectedProducts: true });
  });

  it("con el mínimo alcanzado y sin ciudad, queda pendiente de la ciudad", () => {
    expect(
      resolveFreeShippingNotice(cart({ remaining: "0", eligible_subtotal: "150000" })),
    ).toEqual({
      kind: "pendingCity",
      hints: { selectedCities: true, selectedProducts: false },
    });
  });

  it("confirma el envío gratis cuando WordPress lo da por cumplido", () => {
    const reached = cart({
      qualifies: true,
      city: "eligible",
      remaining: "0",
      eligible_subtotal: "150000",
    });
    expect(resolveFreeShippingNotice(reached)).toEqual({ kind: "reached" });
    expect(qualifiesForFreeShipping(reached)).toBe(true);
  });

  it("sin mínimo (0) no divide por cero", () => {
    expect(
      resolveFreeShippingNotice(
        cart({ threshold: "0", remaining: "0", eligible_subtotal: "0" }),
      ),
    ).toEqual({
      kind: "pendingCity",
      hints: { selectedCities: true, selectedProducts: false },
    });
  });

  it("qualifiesForFreeShipping es false sin estado", () => {
    expect(qualifiesForFreeShipping(cart(null))).toBe(false);
    expect(qualifiesForFreeShipping(cart({ enabled: false, qualifies: true }))).toBe(
      false,
    );
  });
});
