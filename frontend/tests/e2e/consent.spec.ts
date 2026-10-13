import { test, expect, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Banner de cookies con servicios configurados y el módulo `consent-log`.
//  Requiere el stub con `integrations` (GA4) y `modules["consent-log"]` — ver
//  STUB_SITE_CONFIG_EXTRA en `.github/workflows/ci.yml`. El stub contesta 200
//  a `hwe/v1/consent/record`, así que aquí se ejercita el BFF y el banner, no
//  la tabla de WordPress (eso lo cubre PHPUnit).
// ============================================================================

test.use({ locale: "es-ES" });

const RECEIPT_RE = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/;

/** Ningún script de terceros sale a la red del CI. */
async function blockThirdParties(page: Page) {
  await page.route(/googletagmanager\.com|google-analytics\.com/, (route) =>
    route.fulfill({ status: 200, contentType: "text/javascript", body: "" }),
  );
}

const banner = (page: Page) => page.getByRole("region", { name: "Cookies" });

async function storedConsent(page: Page) {
  return page.evaluate(() => localStorage.getItem("cookie-consent"));
}

async function receiptCookie(page: Page) {
  const cookies = await page.context().cookies();
  return cookies.find((c) => c.name === "hwe_consent");
}

test("el banner pregunta, no tiene violaciones graves y registra «Solo necesarias»", async ({
  page,
}) => {
  await blockThirdParties(page);
  await page.goto("/");
  await expect(banner(page)).toBeVisible();

  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);

  await banner(page).getByRole("button", { name: "Solo necesarias" }).click();
  await expect(banner(page)).toBeHidden();

  expect(await storedConsent(page)).toContain("declined");
  await expect.poll(async () => (await receiptCookie(page))?.httpOnly).toBe(true);
});

test("«Aceptar todas» no repregunta y «Preferencias» enseña el comprobante", async ({
  page,
}) => {
  await blockThirdParties(page);
  await page.goto("/");
  await banner(page).getByRole("button", { name: "Aceptar todas" }).click();
  await expect(banner(page)).toBeHidden();
  expect(await storedConsent(page)).toContain("accepted");

  // Recarga: la decisión vigente no vuelve a preguntar.
  await page.reload();
  await expect(page.locator("header")).toBeVisible();
  await expect(banner(page)).toBeHidden();

  // Reabrir desde el pie: enseña el comprobante que fijó el servidor.
  await page.getByRole("button", { name: "Preferencias de cookies" }).click();
  await expect(banner(page)).toBeVisible();
  await expect(banner(page).getByText(RECEIPT_RE)).toBeVisible();

  const first = (await receiptCookie(page))?.value;
  expect(first).toMatch(RECEIPT_RE);

  // Cambiar a «Solo necesarias» reutiliza el mismo comprobante del dispositivo.
  await banner(page).getByRole("button", { name: "Solo necesarias" }).click();
  await expect(banner(page)).toBeHidden();
  expect(await storedConsent(page)).toContain("declined");
  expect((await receiptCookie(page))?.value).toBe(first);
});

test("/api/consent rechaza una petición de otro origen", async ({ request }) => {
  const res = await request.post("/api/consent", {
    headers: { Origin: "https://evil.example" },
    data: { decision: "accepted", action: "first", variant: "bar", locale: "es" },
  });
  expect(res.status()).toBe(403);
});
