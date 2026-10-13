import { test, expect } from "@playwright/test";

// ============================================================================
//  Status HTTP de las fichas (producto, categoría, entrada). Los demás e2e
//  siguen redirecciones y comparan la URL final, y un `<meta refresh>` con 200 o
//  una página «no existe» con 200 (soft-404) también la cumplen: aquí se pide
//  SIN seguir redirecciones y se comprueba el status real, que es lo que ve un
//  buscador.
//
//  Requiere `next build` + `next start` (en `next dev` no hay prerender y todo
//  sale en 200) y el producto `e2e-landing` del stub, con su slug `e2e-landing-en`.
// ============================================================================

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"product-landing":true'),
  "requiere el stub con modules.product-landing activo (STUB_SITE_CONFIG_EXTRA)",
);

test("el slug nativo bajo /en responde 308 al traducido", async ({ request }) => {
  const res = await request.get("/en/products/e2e-landing", { maxRedirects: 0 });

  expect(res.status()).toBe(308);
  expect(new URL(res.headers()["location"]!, "http://x").pathname).toBe(
    "/en/products/e2e-landing-en",
  );
});

test("el slug de otro idioma bajo el idioma por defecto responde 308 al nativo", async ({
  request,
}) => {
  const res = await request.get("/products/e2e-landing-en", { maxRedirects: 0 });

  expect(res.status()).toBe(308);
  expect(new URL(res.headers()["location"]!, "http://x").pathname).toBe(
    "/products/e2e-landing",
  );
});

for (const path of [
  "/products/no-existe",
  "/en/products/no-existe",
  "/categories/no-existe",
  "/en/categories/no-existe",
  "/blog/no-existe",
]) {
  test(`${path} responde 404, no un 200 con página de error`, async ({ request }) => {
    const res = await request.get(path, { maxRedirects: 0 });

    expect(res.status()).toBe(404);
  });
}
