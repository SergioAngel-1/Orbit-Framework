import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Módulo `landings` ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA: `modules.landings` y
//  `landings.*`). El stub declara dos landings del panel: `guia-demo` (en el área, con tramo en
//  inglés) y `perfil-demo` (en la raíz, tipo persona). El módulo apagado se prueba en unit
//  (`editorial-landing-route.test.tsx`): el stub sirve una config por ejecución.
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"landings":true'),
  "requiere el stub con modules.landings activo (STUB_SITE_CONFIG_EXTRA)",
);

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

test("una landing del área se pinta con sus slots, accesible", async ({ page }) => {
  await page.goto("/learn/guia-demo");
  const main = page.locator("#main-content");

  await expect(
    main.getByRole("heading", { level: 1, name: "Guía de demostración" }),
  ).toBeVisible();
  await expect(main.getByText("Entradilla de prueba")).toBeVisible();
  await expect(main.getByRole("heading", { name: "Primera sección" })).toBeVisible();
  // `prose-blocks` reparte el cuerpo por sus subtítulos.
  await expect(main.getByRole("heading", { name: "Primer bloque" })).toBeVisible();
  await expect(main.getByRole("heading", { name: "Segundo bloque" })).toBeVisible();
  await expect(main.getByText("¿Funciona?")).toBeVisible();
  await expect(main.getByRole("heading", { name: "Para cerrar" })).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("declara su JSON-LD: migas, artículo y preguntas", async ({ page }) => {
  await page.goto("/learn/guia-demo");

  const blocks = await page
    .locator('script[type="application/ld+json"]')
    .allTextContents();
  const types = blocks.map((block) => JSON.parse(block)["@type"]);

  expect(types).toEqual(
    expect.arrayContaining(["BreadcrumbList", "Article", "FAQPage"]),
  );
});

test("el botón de cierre valida el enlace: el interno se pinta y javascript: no", async ({
  page,
}) => {
  await page.goto("/learn/guia-demo");
  const main = page.locator("#main-content");

  await expect(main.getByRole("link", { name: "Ir a la tienda" })).toHaveAttribute(
    "href",
    "/products",
  );
  await expect(main.getByRole("link", { name: "Ataque" })).toHaveCount(0);
  await expect(main.locator('a[href^="javascript:"]')).toHaveCount(0);
});

test("en inglés se sirve la traducción con su propio tramo", async ({ page }) => {
  await page.goto("/en/learn/demo-guide");
  const main = page.locator("#main-content");

  await expect(
    main.getByRole("heading", { level: 1, name: "Demo guide" }),
  ).toBeVisible();
  await expect(main.getByText("Test lead")).toBeVisible();
  await expect(main.getByRole("heading", { name: "First section" })).toBeVisible();
});

test("el tramo de otro idioma redirige (308) al del idioma de la página", async ({
  page,
}) => {
  await page.goto("/en/learn/guia-demo");

  await expect(page).toHaveURL(/\/en\/learn\/demo-guide$/);
});

test("una landing de la raíz responde en la raíz y no bajo el área", async ({
  page,
}) => {
  await page.goto("/perfil-demo");
  await expect(
    page
      .locator("#main-content")
      .getByRole("heading", { level: 1, name: "Perfil de demostración" }),
  ).toBeVisible();
  const blocks = await page
    .locator('script[type="application/ld+json"]')
    .allTextContents();
  expect(blocks.map((block) => JSON.parse(block)["@type"])).toContain("Person");

  await page.goto("/learn/perfil-demo");
  await expect(page.getByText("La página que buscas no existe.")).toBeVisible();
});

test("un tramo que no es una landing es 404 y las rutas del sitio siguen siendo suyas", async ({
  page,
}) => {
  await page.goto("/esto-no-existe");
  await expect(page.getByText("La página que buscas no existe.")).toBeVisible();

  await page.goto("/products");
  await expect(page.getByRole("heading", { name: "Tienda" })).toBeVisible();
});

test("el índice del área lista las guías publicadas", async ({ page }) => {
  await page.goto("/learn");

  await expect(
    page.locator("#main-content").getByRole("link", { name: /Guía de demostración/ }),
  ).toHaveAttribute("href", "/learn/guia-demo");
  await expectNoSeriousA11yViolations(page);
});

test("el sitemap anuncia la landing solo en los idiomas donde existe", async ({
  request,
}) => {
  const res = await request.get("/sitemap/paginas.xml");
  const xml = await res.text();

  expect(res.status()).toBe(200);
  expect(xml).toContain("/learn/guia-demo");
  expect(xml).toContain("/en/learn/demo-guide");
  expect(xml).toContain("/perfil-demo");
  // La landing de la raíz no tiene traducción: no hay fila inglesa.
  expect(xml).not.toContain("/en/perfil-demo");
});
