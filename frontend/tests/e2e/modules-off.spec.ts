import { test, expect } from "@playwright/test";

// ============================================================================
//  Instalación SIN módulos (la config por defecto: `modules` vacío). Es la otra mitad de los
//  specs de cada módulo, que se saltan si el stub lo enciende: con el módulo apagado sus rutas
//  del BFF responden 404, sus páginas no existen y el sitemap no las lista.
//
//  El stub sirve una config por ejecución y las páginas se prerenderizan en el build, así que
//  esta pasada necesita su propio build con `STUB_SITE_CONFIG_EXTRA` sin módulos (CI, job e2e).
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  /"modules"\s*:\s*\{[^}]*:\s*true/.test(process.env.STUB_SITE_CONFIG_EXTRA ?? ""),
  "esta pasada exige el stub SIN módulos activos (STUB_SITE_CONFIG_EXTRA)",
);

test.describe("rutas del BFF de un módulo apagado", () => {
  test("shipping: el alcance del envío gratis no existe", async ({ request }) => {
    const res = await request.get("/api/store/free-shipping/scope?locale=es");

    expect(res.status()).toBe(404);
  });

  test("coupon-rules: los cupones agotados no existen", async ({ request }) => {
    const res = await request.get("/api/coupons/used-up");

    expect(res.status()).toBe(404);
  });

  test("consent-log: el registro del consentimiento no existe", async ({
    request,
    baseURL,
  }) => {
    // Con el Origin del propio sitio: sin él, la guarda de escrituras responde 403 antes.
    const res = await request.post("/api/consent", {
      headers: { Origin: baseURL! },
      data: { necessary: true, analytics: false, marketing: false },
    });

    expect(res.status()).toBe(404);
  });
});

test.describe("páginas de un módulo apagado", () => {
  for (const path of [
    "/learn",
    "/learn/guia-demo",
    "/guia-demo",
    "/products/e2e-landing/learn",
  ]) {
    test(`${path} es un 404`, async ({ page }) => {
      await page.goto(path);

      await expect(page.getByText("La página que buscas no existe.")).toBeVisible();
    });
  }

  test("el sitemap no lista landings", async ({ request }) => {
    const res = await request.get("/sitemap.xml");

    expect(res.status()).toBe(200);
    expect(await res.text()).not.toMatch(/\/learn(\/|<)/);
  });
});
