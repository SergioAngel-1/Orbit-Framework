import { describe, it, expect } from "vitest";
import {
  canAccessOrder,
  parseGuestOrderCredential,
  serializeGuestOrderCredential,
} from "./order-access";

const guestOrder = { id: 42, customer_id: 0, order_key: "wc_order_AbC123" };
const userOrder = { id: 43, customer_id: 7, order_key: "wc_order_Zz9" };

describe("parseGuestOrderCredential", () => {
  it("hace el viaje de ida y vuelta con serialize", () => {
    const credential = { orderId: 42, orderKey: "wc_order_AbC123" };
    expect(
      parseGuestOrderCredential(serializeGuestOrderCredential(credential)),
    ).toEqual(credential);
  });

  it.each([
    null,
    "",
    "42",
    ":wc_order_x",
    "abc:wc_order_x",
    "42:otra",
    "0:wc_order_x",
    "42:wc_order_",
  ])("rechaza %j", (raw) => {
    expect(parseGuestOrderCredential(raw)).toBeNull();
  });
});

describe("canAccessOrder", () => {
  it("el cliente con sesión accede a SU pedido", () => {
    expect(canAccessOrder(userOrder, { userId: "7" })).toBe(true);
  });

  it("un cliente con sesión no accede al pedido de otro", () => {
    expect(canAccessOrder(userOrder, { userId: "8" })).toBe(false);
  });

  it("sin sesión ni credencial no hay acceso", () => {
    expect(canAccessOrder(guestOrder, {})).toBe(false);
  });

  it("el invitado accede con el id y la order_key correctos", () => {
    expect(
      canAccessOrder(guestOrder, {
        guest: { orderId: 42, orderKey: "wc_order_AbC123" },
      }),
    ).toBe(true);
  });

  it("una order_key equivocada no da acceso", () => {
    expect(
      canAccessOrder(guestOrder, {
        guest: { orderId: 42, orderKey: "wc_order_AbC124" },
      }),
    ).toBe(false);
  });

  it("la credencial de otro pedido no da acceso", () => {
    expect(
      canAccessOrder(guestOrder, {
        guest: { orderId: 41, orderKey: "wc_order_AbC123" },
      }),
    ).toBe(false);
  });

  it("la credencial de invitado no abre un pedido ya ligado a una cuenta", () => {
    expect(
      canAccessOrder(userOrder, { guest: { orderId: 43, orderKey: "wc_order_Zz9" } }),
    ).toBe(false);
  });

  it("el userId 0 de un visitante no casa con un pedido de invitado", () => {
    expect(canAccessOrder(guestOrder, { userId: 0 })).toBe(false);
  });
});
