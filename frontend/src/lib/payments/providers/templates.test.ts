import { describe, expect, it } from "vitest";
import { BoldProvider } from "./bold";
import { PayUProvider } from "./payu";

// ============================================================================
//  PayU y Bold son plantillas sin integrar (spec §1: fuera de alcance): no
//  cobran ni dan por bueno ningún webhook; responden 501 y no anuncian entorno.
// ============================================================================

describe.each([
  ["payu", new PayUProvider()],
  ["bold", new BoldProvider()],
])("plantilla %s", (id, provider) => {
  it("tiene su id y no anuncia ningún entorno", async () => {
    expect(provider.id).toBe(id);
    expect(await provider.isSandbox()).toBe(false);
  });

  it("crear el cobro responde 501", async () => {
    await expect(
      (provider.createCheckout as () => Promise<unknown>)(),
    ).rejects.toMatchObject({ status: 501, code: "provider_not_implemented" });
  });

  it("verificar un webhook responde 501: nunca lo da por válido", async () => {
    await expect(
      (provider.verifyWebhook as () => Promise<unknown>)(),
    ).rejects.toMatchObject({ status: 501, code: "provider_not_implemented" });
  });

  it("un estado desconocido es «error»", () => {
    expect(provider.mapStatus("???")).toBe("error");
  });
});

describe("PayU: estados de la confirmación", () => {
  it("mapea state_pol", () => {
    const payu = new PayUProvider();

    expect(payu.mapStatus("4")).toBe("approved");
    expect(payu.mapStatus("6")).toBe("declined");
    expect(payu.mapStatus("5")).toBe("voided");
    expect(payu.mapStatus("7")).toBe("pending");
  });
});
