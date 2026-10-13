import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Módulo `customer-identity` ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA).
//  El stub sirve el paquete de país genérico (sin tipos de documento), así que
//  el checkout y el registro piden solo la aceptación de términos; el campo de
//  documento (países con tipos) y el módulo apagado se prueban en unit
//  (formularios, rutas BFF y `identityPolicy`).
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"customer-identity":true'),
  "requiere el stub con modules.customer-identity activo (STUB_SITE_CONFIG_EXTRA)",
);

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

test("el checkout pide aceptar los términos, con enlaces a los textos legales", async ({
  page,
}) => {
  test.skip(
    !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"checkout_login_required":false'),
    "requiere la compra de invitado en el stub",
  );
  await page.goto("/checkout");

  const terms = page.getByRole("checkbox", {
    name: /Acepto los términos y condiciones/,
  });
  await expect(terms).toBeVisible();
  await expect(terms).toHaveAttribute("required", "");
  await expect(
    page.getByRole("link", { name: "términos y condiciones" }),
  ).toHaveAttribute("href", "/legal/terms");
  // País sin tipos de documento: no se pide documento.
  await expect(page.locator('input[name="documentNumber"]')).toHaveCount(0);
  await expectNoSeriousA11yViolations(page);
});

test("el registro pide aceptar los términos", async ({ page }) => {
  await page.goto("/register");

  await expect(
    page.getByRole("checkbox", { name: /Acepto los términos y condiciones/ }),
  ).toHaveAttribute("required", "");
  await expectNoSeriousA11yViolations(page);
});
