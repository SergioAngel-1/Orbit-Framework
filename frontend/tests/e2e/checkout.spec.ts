import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Starter de checkout (tarea 40) contra el stub: la vista y el selector de
//  medio de pago, con axe. El cobro real necesita WordPress y la pasarela, y
//  vive en purchase.spec.ts (opt-in).
// ============================================================================

// Los textos se comprueban en español: sin esto, el navegador pide inglés y el
// proxy redirige a /en por Accept-Language.
test.use({ locale: "es-ES" });

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

test("checkout: formulario con sus campos etiquetados y accesible", async ({
  page,
}) => {
  await page.goto("/checkout");
  await expect(page.getByRole("heading", { name: "Finalizar compra" })).toBeVisible();
  await expect(page.getByLabel("Nombre", { exact: true })).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test.describe("con varios medios de pago activos", () => {
  // El stub los activa con STUB_SITE_CONFIG_EXTRA (job e2e del CI, e2e.sh local).
  test.skip(
    !process.env.STUB_SITE_CONFIG_EXTRA,
    "requiere el stub con payments.whatsapp_enabled y payments.cash_enabled (STUB_SITE_CONFIG_EXTRA)",
  );

  test("el selector es accesible y el botón sigue al medio elegido", async ({
    page,
  }) => {
    await page.goto("/checkout");
    const group = page.getByRole("group", { name: "Medio de pago" });
    await expect(group).toBeVisible();
    await expect(group.getByRole("radio")).toHaveCount(3);
    await expect(group.getByRole("radio", { name: /Pagar en línea/ })).toBeChecked();

    await group.getByRole("radio", { name: /Link de pago por WhatsApp/ }).check();
    await expect(
      page.getByRole("button", { name: "Confirmar y recibir el link de pago" }),
    ).toBeVisible();

    await group.getByRole("radio", { name: /Pago en efectivo al recibir/ }).check();
    await expect(
      page.getByRole("button", { name: "Confirmar pedido (pago al recibir)" }),
    ).toBeVisible();

    await expectNoSeriousA11yViolations(page);
  });
});
