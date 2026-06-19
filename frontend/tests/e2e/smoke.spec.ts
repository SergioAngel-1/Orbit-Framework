import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Smoke tests — solo requieren el frontend levantado (sin pedidos reales).
//  Verifican enrutado i18n, SEO base, barrera de seguridad del BFF y
//  accesibilidad básica con axe-core.
// ============================================================================

test("la home en español responde 200 y renderiza la cabecera", async ({ page }) => {
  const res = await page.goto("/");
  expect(res?.status()).toBeLessThan(400);
  await expect(page.locator("header")).toBeVisible();
});

test("la home no tiene violaciones críticas de accesibilidad", async ({ page }) => {
  await page.goto("/");
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.filter((v) => v.impact === "critical" || v.impact === "serious")).toHaveLength(0);
});

test("el prefijo /en sirve la versión en inglés", async ({ page }) => {
  await page.goto("/en");
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
});

test("/es redirige al canónico sin prefijo", async ({ page }) => {
  const res = await page.goto("/es");
  expect(new URL(page.url()).pathname).toBe("/");
  expect(res?.status()).toBeLessThan(400);
});

test("robots.txt y sitemap.xml están disponibles", async ({ request }) => {
  expect((await request.get("/robots.txt")).status()).toBe(200);
  expect((await request.get("/sitemap.xml")).status()).toBe(200);
});

test("la sonda de salud responde", async ({ request }) => {
  const res = await request.get("/api/health");
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(["ok", "degraded"]).toContain(body.status);
});

test("la página de recuperación de contraseña no tiene violaciones críticas de accesibilidad", async ({ page }) => {
  await page.goto("/forgot-password");
  await page.waitForSelector("form");
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.filter((v) => v.impact === "critical" || v.impact === "serious")).toHaveLength(0);
});

test("la página de registro no tiene violaciones críticas de accesibilidad", async ({ page }) => {
  await page.goto("/register");
  await page.waitForSelector("form");
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.filter((v) => v.impact === "critical" || v.impact === "serious")).toHaveLength(0);
});

test("la página de productos no tiene violaciones críticas de accesibilidad", async ({ page }) => {
  await page.goto("/products");
  await page.waitForSelector("h1, h2");
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.filter((v) => v.impact === "critical" || v.impact === "serious")).toHaveLength(0);
});

test("la página 404 no tiene violaciones críticas de accesibilidad", async ({ page }) => {
  const res = await page.goto("/pagina-que-no-existe");
  // 404 should still render the error page
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.filter((v) => v.impact === "critical" || v.impact === "serious")).toHaveLength(0);
});

test("una escritura sin Origin ni CSRF es rechazada (barrera del BFF)", async ({
  request,
}) => {
  // Sin cabecera Origin/Referer válida ni token CSRF → 403.
  const res = await request.post("/api/store/checkout", {
    data: { billing_address: {}, payment_method: "cod" },
    headers: { origin: "https://atacante.example" },
  });
  expect([401, 403]).toContain(res.status());
});
