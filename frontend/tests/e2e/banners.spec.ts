import { test, expect } from "@playwright/test";

// ============================================================================
//  Módulo `banners` ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA: `modules.banners` y
//  `banners.enabled`). El stub sirve el carrusel de la portada con un slide y su imagen. El
//  módulo apagado se prueba en unit (`lib/banners/disabled.test.ts`): el stub sirve una
//  config por ejecución.
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"banners":true'),
  "requiere el stub con modules.banners activo (STUB_SITE_CONFIG_EXTRA)",
);

test("la portada pinta el carrusel de banners del CMS", async ({ page }) => {
  await page.goto("/");

  const carousel = page.getByRole("region", { name: "Carrusel de banners" });
  await expect(carousel).toBeVisible();
  await expect(carousel.getByText("Banner de prueba")).toBeVisible();
  await expect(carousel.locator("img").first()).toHaveAttribute(
    "src",
    /e2e-banner\.svg$/,
  );
});
