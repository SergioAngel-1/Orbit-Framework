import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Módulo `shipping` ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA). El stub sirve un extra
//  de envío («Seguro de envío») y un alcance del envío gratis con una ciudad; el carrito no
//  trae `extensions.hwe_free_shipping` (la calcula WooCommerce), así que el aviso del carrito
//  se prueba en unit. El módulo apagado también: rutas BFF en 404 y checkout sin extras.
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"shipping":true'),
  "requiere el stub con modules.shipping activo (STUB_SITE_CONFIG_EXTRA)",
);

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

test("el alcance del envío gratis se sirve, cacheable y en el idioma pedido", async ({
  request,
}) => {
  const res = await request.get("/api/store/free-shipping/scope?locale=es");

  expect(res.status()).toBe(200);
  expect(res.headers()["cache-control"]).toContain("s-maxage=300");
  expect((await res.json()).cities).toEqual(["Madrid"]);
});

test("el destino del carrito exige CSRF y Origin como cualquier escritura", async ({
  request,
}) => {
  const res = await request.put("/api/store/shipping/destination", {
    data: { city: "Madrid", country: "ES" },
  });

  expect([401, 403]).toContain(res.status());
});

test("el checkout ofrece los extras de envío del módulo, accesible", async ({
  page,
}) => {
  test.skip(
    !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"checkout_login_required":false'),
    "requiere la compra de invitado en el stub",
  );
  await page.goto("/checkout");

  await expect(page.getByRole("group", { name: "Extras de envío" })).toBeVisible();
  await expect(page.getByRole("checkbox", { name: /Seguro de envío/ })).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});
