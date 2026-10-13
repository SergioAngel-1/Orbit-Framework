import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Módulo `product-landing` ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA:
//  `modules.product-landing`). El stub publica el producto `e2e-landing` con su
//  landing (y traducción al inglés) y su slug traducido `e2e-landing-en`. El
//  módulo apagado se prueba en unit (`app/[locale]/products/[slug]/learn`): el
//  stub sirve una config por ejecución.
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"product-landing":true'),
  "requiere el stub con modules.product-landing activo (STUB_SITE_CONFIG_EXTRA)",
);

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

test("la landing del producto se pinta con sus secciones, accesible", async ({
  page,
}) => {
  await page.goto("/products/e2e-landing/learn");
  const main = page.locator("#main-content");

  await expect(
    main.getByRole("heading", { level: 1, name: "Producto con guía" }),
  ).toBeVisible();
  await expect(main.getByText("Para empezar con calma")).toBeVisible();
  await expect(main.getByRole("heading", { name: "Cómo funciona" })).toBeVisible();
  await expect(main.getByText("Hoja de prueba")).toBeVisible();
  await expect(main.getByText("Cualidad solar")).toBeVisible();
  await expect(main.getByText("¿Cómo se usa?")).toBeVisible();
  await expect(main.getByText("Guárdalo en un lugar fresco.")).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});

test("declara el JSON-LD de la guía: artículo, producto y preguntas", async ({
  page,
}) => {
  await page.goto("/products/e2e-landing/learn");

  const blocks = await page
    .locator('script[type="application/ld+json"]')
    .allTextContents();
  const types = blocks.map((block) => JSON.parse(block)["@type"]);

  expect(types).toEqual(
    expect.arrayContaining(["BreadcrumbList", "Article", "Product", "FAQPage"]),
  );
});

test("el CTA lleva a la ficha del producto", async ({ page }) => {
  await page.goto("/products/e2e-landing/learn");

  await page
    .locator("#main-content")
    .getByRole("link", { name: "Ver el producto" })
    .first()
    .click();

  await expect(page).toHaveURL(/\/products\/e2e-landing$/);
});

test("en inglés se sirve la traducción y el slug traducido", async ({ page }) => {
  await page.goto("/en/products/e2e-landing-en/learn");
  const main = page.locator("#main-content");

  await expect(
    main.getByRole("heading", { level: 1, name: "Product with guide" }),
  ).toBeVisible();
  await expect(main.getByText("To start calmly")).toBeVisible();
  await expect(main.getByText("Test leaf")).toBeVisible();
  await expect(main.getByText("How is it used?")).toBeVisible();
});

test("el slug nativo bajo /en redirige (308) al traducido", async ({ page }) => {
  await page.goto("/en/products/e2e-landing/learn");

  await expect(page).toHaveURL(/\/en\/products\/e2e-landing-en\/learn$/);
});

test("un producto sin landing publicada muestra la página 404", async ({ page }) => {
  // En una vista con streaming el estado HTTP no es fiable: se comprueba el contenido.
  await page.goto("/products/otro-producto/learn");

  await expect(page.getByText("La página que buscas no existe.")).toBeVisible();
  await expect(page.getByText("Producto con guía")).toHaveCount(0);
});
