import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Blog contra el stub (CMS vacío): lo que NO depende de que haya entradas — la
//  vista vacía, el buscador, el archivo, la categoría inexistente y el endpoint
//  del scroll infinito con sus topes. Las tarjetas y la ficha se prueban en
//  unitario (`post-card`, `post-view`).
// ============================================================================

test.use({ locale: "es-ES" });

test("el listado sin entradas muestra el estado vacío y el buscador", async ({
  page,
}) => {
  await page.goto("/blog");

  await expect(page.getByRole("heading", { name: "Blog", level: 1 })).toBeVisible();
  await expect(
    page.getByRole("searchbox", { name: "Buscar en el blog" }),
  ).toBeVisible();
  await expect(page.getByText("Todavía no hay entradas.")).toBeVisible();
});

test("el listado no tiene violaciones graves de accesibilidad", async ({ page }) => {
  await page.goto("/blog");
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
});

test("una vista filtrada va noindex y sin canónica", async ({ page }) => {
  await page.goto("/blog?tag=verano");

  await expect(page.locator('meta[name="robots"]')).toHaveAttribute(
    "content",
    /noindex/,
  );
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(0);
  await expect(
    page.getByText("Ninguna entrada coincide con ese filtro."),
  ).toBeVisible();
});

test("el listado base sí declara su canónica", async ({ page }) => {
  await page.goto("/blog");
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
});

test("el archivo existe y avisa de que no hay entradas", async ({ page }) => {
  const res = await page.goto("/blog/archive");

  expect(res?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "Archivo del blog" })).toBeVisible();
  await expect(page.getByText("Todavía no hay entradas publicadas.")).toBeVisible();
});

test("una categoría inexistente no se sirve", async ({ page }) => {
  await page.goto("/blog/category/inexistente");
  await expect(page.getByText("La página que buscas no existe.")).toBeVisible();
});

test("/api/blog/posts responde y acota lo que el cliente pide", async ({ request }) => {
  const res = await request.get(
    "/api/blog/posts?first=100000&after=%27%3BDROP&locale=zz",
  );

  expect(res.status()).toBe(200);
  expect(res.headers()["cache-control"]).toContain("public");
  const body = await res.json();
  expect(body.posts).toEqual([]);
  expect(body.pageInfo.hasNextPage).toBe(false);
});

test("el sitemap del blog lista el archivo", async ({ request }) => {
  const res = await request.get("/sitemap/blog.xml");

  expect(res.status()).toBe(200);
  expect(await res.text()).toContain("/blog/archive");
});
