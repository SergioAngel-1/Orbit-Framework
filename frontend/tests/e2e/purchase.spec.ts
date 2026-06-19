import { test, expect, request as pwRequest } from "@playwright/test";
import crypto from "node:crypto";

// ============================================================================
//  E2E del flujo crítico de compra (nivel API/BFF), regresión del fix §1.1:
//  registro → carrito → checkout → pago (noop) → webhook firmado → el COMPRADOR
//  ve su pedido (customer_id ligado). Falla si el checkout vuelve a crear
//  pedidos de invitado.
//
//  Requiere la PILA COMPLETA levantada y sembrada (WordPress + WooCommerce con
//  al menos un producto). Por eso es OPT-IN: se ejecuta solo con E2E_FULL=1.
//
//  Variables:
//    E2E_FULL=1                 → activa esta suite
//    E2E_PRODUCT_ID=<id>        → id de un producto comprable (def. 1)
//    NOOP_INTEGRITY_SECRET=...  → debe coincidir con el del servidor (def. sandbox)
// ============================================================================

const RUN = process.env.E2E_FULL === "1";
const PRODUCT_ID = Number(process.env.E2E_PRODUCT_ID || 1);
const NOOP_SECRET = process.env.NOOP_INTEGRITY_SECRET || "noop-sandbox-secret";
const ORIGIN = process.env.ALLOWED_ORIGIN || "http://localhost:3000";

test.describe("Compra de extremo a extremo (BFF)", () => {
  test.skip(!RUN, "Define E2E_FULL=1 con la pila Docker sembrada para ejecutar.");

  test("registro → carrito → checkout → pago → el comprador ve su pedido", async ({ baseURL }) => {
    const ctx = await pwRequest.newContext({ baseURL, extraHTTPHeaders: { origin: ORIGIN } });

    // 1) CSRF (cookie + token). Lo reenviaremos en cada escritura.
    const csrfRes = await ctx.get("/api/csrf");
    expect(csrfRes.ok()).toBeTruthy();
    const { token: csrf } = await csrfRes.json();
    const W = { "x-csrf-token": csrf, "content-type": "application/json" };

    // 2) Registro (auto-login: deja cookies de sesión en el contexto).
    const uniq = Date.now();
    const reg = await ctx.post("/api/auth/register", {
      headers: W,
      data: { username: `e2e_${uniq}`, email: `e2e_${uniq}@example.com`, password: "Sup3rSecret!" },
    });
    expect([200, 201]).toContain(reg.status());

    // 3) Añadir un producto al carrito.
    const add = await ctx.post("/api/store/cart/items", {
      headers: W,
      data: { id: PRODUCT_ID, quantity: 1 },
    });
    expect(add.ok()).toBeTruthy();

    // 4) Checkout (crea el pedido y, por el fix §1.1, lo liga al cliente).
    const checkout = await ctx.post("/api/store/checkout", {
      headers: { ...W, "idempotency-key": `e2e-${uniq}` },
      data: {
        billing_address: {
          first_name: "E2E", last_name: "Test", email: `e2e_${uniq}@example.com`,
          address_1: "Calle 1", city: "Bogotá", country: "CO", postcode: "110111",
        },
        payment_method: "noop",
      },
    });
    expect(checkout.status()).toBe(201);
    const order = await checkout.json();
    const orderId = order.order_id as number;
    expect(orderId).toBeGreaterThan(0);

    // 5) El COMPRADOR puede ver su pedido (prueba anti-pedido-invitado).
    const view = await ctx.get(`/api/store/orders/${orderId}`);
    expect(view.status()).toBe(200);
    const viewed = await view.json();
    expect(viewed.customer_id).toBeGreaterThan(0); // ligado, no invitado (0)

    // 6) Iniciar el pago (debe encontrar el pedido del usuario, no 404).
    const pay = await ctx.post("/api/payments/create", { headers: W, data: { reference: orderId } });
    expect(pay.ok()).toBeTruthy();

    // 7) Simular el webhook firmado de la pasarela (noop) → pedido pagado.
    const amountMinor = Math.round(Number(viewed.total) * 100);
    const body = JSON.stringify({
      reference: String(orderId), status: "APPROVED",
      transactionId: `e2e-tx-${uniq}`, amountMinor, currency: viewed.currency,
    });
    const sig = crypto.createHmac("sha256", NOOP_SECRET).update(body, "utf8").digest("hex");
    const hook = await ctx.post("/api/payments/webhook/noop", {
      headers: { "content-type": "application/json", "x-noop-signature": sig },
      data: body,
    });
    expect(hook.ok()).toBeTruthy();
    expect((await hook.json()).applied).toBe("paid");

    // 8) El pedido queda en estado pagado/processing.
    const after = await ctx.get(`/api/store/orders/${orderId}`);
    expect(["processing", "completed"]).toContain((await after.json()).status);

    await ctx.dispose();
  });
});
