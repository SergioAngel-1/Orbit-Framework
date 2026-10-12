import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { createPaymentSchema, isAllowedReturnUrl } from "./payments";

// ============================================================================
//  `returnUrl` de POST /api/payments/create.
//
//  Se le entrega a la pasarela como destino del redirect post-pago. Validada solo
//  con `z.url()`, cualquier URL servía: el endpoint era un redirect abierto a
//  través de un tercero (la pasarela). El candado no restringe nada legítimo:
//  `guardMutation` ya obliga a que el `Origin` sea `ALLOWED_ORIGIN` y el cliente
//  compone la URL con `window.location.origin`.
// ============================================================================

const SITE = "https://tienda.example";

let original: string | undefined;
beforeEach(() => {
  original = process.env.ALLOWED_ORIGIN;
  process.env.ALLOWED_ORIGIN = SITE;
});
afterEach(() => {
  if (original === undefined) delete process.env.ALLOWED_ORIGIN;
  else process.env.ALLOWED_ORIGIN = original;
});

function parse(returnUrl: string) {
  return createPaymentSchema.safeParse({ reference: 1, returnUrl });
}

describe("returnUrl con ALLOWED_ORIGIN configurado", () => {
  it("acepta cualquier ruta del propio sitio, con query y fragmento", () => {
    expect(parse(`${SITE}/checkout/gracias?ref=1`).success).toBe(true);
    expect(parse(`${SITE}/en/checkout/return#estado`).success).toBe(true);
    expect(parse(SITE).success).toBe(true);
  });

  it("rechaza un destino de otro sitio (redirect abierto vía la pasarela)", () => {
    const result = parse("https://evil.example/phish");

    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe(
        "La URL de retorno debe apuntar a este sitio.",
      );
    }
  });

  it.each([
    ["credenciales que simulan el host", "https://tienda.example@evil.example/"],
    ["dominio propio como subdominio ajeno", "https://tienda.example.evil.example/"],
    ["subdominio del sitio", "https://api.tienda.example/"],
    ["el sitio como ruta de otro", "https://evil.example/https://tienda.example"],
    ["otro puerto", "https://tienda.example:8443/"],
    ["http en lugar de https", "http://tienda.example/"],
    ["esquema javascript", "javascript://tienda.example/%0Aalert(1)"],
    ["esquema data", "data:text/html,<script>alert(1)</script>"],
  ])("rechaza %s", (_caso, url) => {
    expect(parse(url).success).toBe(false);
  });

  it("sigue rechazando lo que no es una URL", () => {
    expect(parse("/checkout/return").success).toBe(false);
    expect(parse("no").success).toBe(false);
  });

  it("returnUrl sigue siendo opcional", () => {
    expect(createPaymentSchema.safeParse({ reference: 1 }).success).toBe(true);
  });
});

describe("returnUrl sin ALLOWED_ORIGIN (desarrollo)", () => {
  beforeEach(() => {
    delete process.env.ALLOWED_ORIGIN;
  });

  it("no restringe el destino, igual que assertAllowedOrigin", () => {
    expect(parse("http://localhost:3001/checkout/gracias?ref=1").success).toBe(true);
  });
});

describe("isAllowedReturnUrl", () => {
  it("una URL ilegible no es válida (no lanza)", () => {
    expect(isAllowedReturnUrl("no es una url")).toBe(false);
  });
});
