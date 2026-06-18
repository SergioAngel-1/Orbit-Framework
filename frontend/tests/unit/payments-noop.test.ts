import { describe, it, expect, beforeAll } from "vitest";
import { NoopProvider } from "@/lib/payments/providers/noop";
import { registerProvider, getProvider, listProviders } from "@/lib/payments/registry";
import { hmacSha256 } from "@/lib/payments/signature";

const SECRET = process.env.NOOP_INTEGRITY_SECRET || "noop-sandbox-secret";

describe("registry", () => {
  beforeAll(() => registerProvider(new NoopProvider()));

  it("registra y resuelve el proveedor noop", () => {
    expect(listProviders()).toContain("noop");
    expect(getProvider("noop").id).toBe("noop");
  });
  it("lanza si el proveedor no existe", () => {
    expect(() => getProvider("inexistente")).toThrow(/no registrada/);
  });
});

describe("NoopProvider.createCheckout", () => {
  it("devuelve un redirect con la referencia en la URL de retorno", async () => {
    const provider = new NoopProvider();
    const result = await provider.createCheckout({
      reference: "123",
      amountMinor: 5999,
      currency: "COP",
      customer: { email: "a@b.com", fullName: "Ada Lovelace" },
      returnUrl: "https://shop.test/checkout/return",
    });
    expect(result.mode).toBe("redirect");
    const url = new URL(result.redirectUrl!);
    expect(url.searchParams.get("ref")).toBe("123");
    expect(url.searchParams.get("provider")).toBe("noop");
  });
});

describe("NoopProvider.verifyWebhook", () => {
  const provider = new NoopProvider();

  function sign(body: string) {
    return hmacSha256(body, SECRET, "hex");
  }

  it("acepta un evento aprobado con firma válida", async () => {
    const body = JSON.stringify({
      reference: "123",
      status: "APPROVED",
      transactionId: "tx_1",
      amountMinor: 5999,
      currency: "COP",
    });
    const headers = new Headers({ "x-noop-signature": sign(body) });
    const v = await provider.verifyWebhook(body, headers);
    expect(v.valid).toBe(true);
    expect(v.status).toBe("approved");
    expect(v.reference).toBe("123");
    expect(v.amountMinor).toBe(5999);
  });

  it("rechaza firma inválida", async () => {
    const body = JSON.stringify({ reference: "123", status: "APPROVED" });
    const headers = new Headers({ "x-noop-signature": "deadbeef" });
    const v = await provider.verifyWebhook(body, headers);
    expect(v.valid).toBe(false);
  });

  it("mapea estados nativos a normalizados", () => {
    expect(provider.mapStatus("APPROVED")).toBe("approved");
    expect(provider.mapStatus("DECLINED")).toBe("declined");
    expect(provider.mapStatus("VOIDED")).toBe("voided");
    expect(provider.mapStatus("PENDING")).toBe("pending");
    expect(provider.mapStatus("???")).toBe("error");
  });
});
