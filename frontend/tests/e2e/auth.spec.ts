import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Starter de auth (tarea 39) contra el stub: vistas, estados y axe. El flujo
//  real (credenciales, correo) necesita WordPress y vive en purchase.spec.ts.
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

test("login: formulario accesible", async ({ page }) => {
  await page.goto("/login");
  await expect(page.locator("form input[name=username]")).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("registro: pide confirmar la contraseña y avisa si no coincide", async ({
  page,
}) => {
  await page.goto("/register");
  const passwords = page.locator('form input[type="password"]');
  await expect(passwords).toHaveCount(2);
  await passwords.nth(0).fill("una-clave-larga");
  await passwords.nth(1).fill("otra-clave-larga");
  await expect(page.getByText("Las contraseñas no coinciden.")).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("restablecer contraseña: el enlace del correo muestra el formulario", async ({
  page,
}) => {
  await page.goto("/reset-password?key=clave&login=ana");
  await expect(page.locator('form input[type="password"]')).toHaveCount(2);
  await expectNoSeriousA11yViolations(page);
});

test("verificar email sin token: tarjeta de enlace incompleto", async ({ page }) => {
  await page.goto("/verify-email");
  await expect(page.getByRole("heading", { name: "Enlace incompleto" })).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("verificar email con token: tarjeta de resultado y el token sale de la URL", async ({
  page,
}) => {
  // El stub responde 200 a todo, así que aquí sale «verificado»; contra WordPress
  // un token desconocido sale «Error de verificación». Lo que se comprueba es que
  // la página termina en una tarjeta y que el token no se queda en la URL.
  await page.goto(`/verify-email?token=${"a".repeat(64)}`);
  await expect(
    page.getByRole("heading", { name: /Email verificado|Error de verificación/ }),
  ).toBeVisible();
  expect(new URL(page.url()).searchParams.has("token")).toBe(false);
  await expectNoSeriousA11yViolations(page);
});

test("verificar email en inglés", async ({ page }) => {
  await page.goto("/en/verify-email");
  await expect(page.getByRole("heading", { name: "Incomplete link" })).toBeVisible();
});
