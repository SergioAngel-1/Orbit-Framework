import { test, expect, type APIRequestContext } from "@playwright/test";

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

/** Cabeceras de una escritura válida: Origin permitido y el CSRF que emite el BFF. */
async function writeHeaders(
  request: APIRequestContext,
  baseURL: string,
): Promise<Record<string, string>> {
  const res = await request.get("/api/csrf");
  const { csrfToken } = (await res.json()) as { csrfToken: string };
  return { Origin: baseURL, "X-CSRF-Token": csrfToken };
}

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

  test.describe("loyalty: el monedero y los referidos no existen", () => {
    for (const path of [
      "/api/loyalty/summary",
      "/api/loyalty/transactions",
      "/api/loyalty/referrals",
      "/api/loyalty/referral-link",
      "/api/loyalty/validate-code?code=ABCDEFGH",
    ]) {
      test(`GET ${path} responde 404`, async ({ request }) => {
        const res = await request.get(path);

        expect(res.status()).toBe(404);
      });
    }

    test("POST /api/loyalty/transfer responde 404", async ({ request, baseURL }) => {
      const res = await request.post("/api/loyalty/transfer", {
        headers: await writeHeaders(request, baseURL!),
        data: { code: "ABCDEFGH", points: 100 },
      });

      expect(res.status()).toBe(404);
    });

    for (const method of ["POST", "DELETE"] as const) {
      test(`${method} /api/store/cart/points responde 404`, async ({
        request,
        baseURL,
      }) => {
        const res = await request.fetch("/api/store/cart/points", {
          method,
          headers: await writeHeaders(request, baseURL!),
          ...(method === "POST" ? { data: { points: 100 } } : {}),
        });

        expect(res.status()).toBe(404);
      });
    }
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
