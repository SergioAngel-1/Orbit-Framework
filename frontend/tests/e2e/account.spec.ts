import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";
import { SignJWT } from "jose";

// ============================================================================
//  Cuenta (tarea 42) contra el stub: historial y detalle de pedido, con axe.
//  La sesión se firma con el secreto ficticio del CI (el BFF verifica el JWT en
//  local) y los pedidos salen de los fixtures del stub (`STUB_ORDERS`, cliente 7).
//  El módulo `site-reviews` está ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA);
//  apagado se comprueba en `account/orders/[id]/page.test.tsx`.
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"site-reviews":true'),
  "requiere el stub con modules.site-reviews activo (STUB_SITE_CONFIG_EXTRA)",
);

const SECRET =
  process.env.GRAPHQL_JWT_AUTH_SECRET_KEY ??
  "dummy-ci-jwt-secret-key-at-least-24-chars";
const AUTH_COOKIE = process.env.AUTH_COOKIE_NAME ?? "hwe_at";

async function signIn(page: Page, baseURL: string | undefined) {
  const token = await new SignJWT({ data: { user: { id: "7" } } })
    .setProtectedHeader({ alg: "HS256" })
    .setExpirationTime("1h")
    .sign(new TextEncoder().encode(SECRET));
  await page
    .context()
    .addCookies([
      { name: AUTH_COOKIE, value: token, url: baseURL ?? "http://localhost:3000" },
    ]);
}

/**
 * El contenido de la vista, sin el marcador oculto que React deja fuera de
 * `<main>` mientras una frontera de Suspense termina de transmitirse: un
 * `getByText` sobre la página entera lo encuentra dos veces (modo estricto).
 */
const main = (page: Page) => page.locator("#main-content");

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

test.beforeEach(async ({ page, baseURL }) => signIn(page, baseURL));

test("historial: pedidos enlazados y con el estado traducido", async ({ page }) => {
  await page.goto("/account/orders");

  await expect(page.getByRole("heading", { name: "Mis pedidos" })).toBeVisible();
  await expect(page.getByRole("link", { name: /Pedidos? #10/ })).toBeVisible();
  await expect(main(page).getByText("Completado").first()).toBeVisible();
  await expect(main(page).getByText("En preparación")).toBeVisible();
  await expect(main(page).getByText("En espera")).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("pedido completado: se puede volver a pedir y calificar", async ({ page }) => {
  await page.goto("/account/orders/10");

  await expect(page.getByRole("button", { name: "Volver a pedir" })).toBeVisible();
  await page.getByRole("button", { name: "Calificar pedido" }).click();
  await expect(page.getByLabel("Puntuación")).toBeVisible();
  await expect(page.getByLabel("Tu opinión sobre el pedido")).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("pedido en preparación: ofrece «Recibí mi pedido», no calificar", async ({
  page,
}) => {
  await page.goto("/account/orders/11");

  await expect(page.getByRole("button", { name: "Recibí mi pedido" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Calificar pedido" })).toHaveCount(0);
  await expectNoSeriousA11yViolations(page);
});

test("pedido ya calificado: enseña la calificación y no la vuelve a pedir", async ({
  page,
}) => {
  await page.goto("/account/orders/12");

  await expect(main(page).getByText("Muy buena compra.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Calificar pedido" })).toHaveCount(0);
  await expectNoSeriousA11yViolations(page);
});

test("pedido en espera con pago en línea: ofrece «Pagar ahora»", async ({ page }) => {
  await page.goto("/account/orders/13");

  await expect(page.getByRole("button", { name: "Pagar ahora" })).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("un pedido que no es del cliente enseña la página de «no encontrado»", async ({
  page,
}) => {
  // Con la vista en streaming el estado HTTP ya salió como 200: se comprueba el contenido.
  await page.goto("/account/orders/99999");

  await expect(main(page).getByText("La página que buscas no existe.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Calificar pedido" })).toHaveCount(0);
});
