import { describe, expect, it } from "vitest";
import { orderSupportAction } from "./order-support";

describe("orderSupportAction", () => {
  it("En espera → pedir la cancelación", () => {
    expect(orderSupportAction("on-hold")).toBe("cancel");
  });

  it("Completado → «Tuve un problema con mi pedido»", () => {
    expect(orderSupportAction("completed")).toBe("problem");
  });

  it.each(["pending", "processing", "cancelled", "refunded", "failed"])(
    "%s no ofrece ayuda por WhatsApp",
    (status) => {
      expect(orderSupportAction(status)).toBeNull();
    },
  );
});
