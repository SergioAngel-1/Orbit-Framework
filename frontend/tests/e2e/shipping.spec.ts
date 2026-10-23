import { test, expect, type APIRequestContext, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// ============================================================================
//  Módulo `shipping` ENCENDIDO en el stub (STUB_SITE_CONFIG_EXTRA). El stub sirve un extra
//  de envío («Seguro de envío») y un alcance del envío gratis con una ciudad; el carrito no
//  trae `extensions.hwe_free_shipping` (la calcula WooCommerce), así que el aviso del carrito
//  se prueba en unit. El módulo apagado también: rutas BFF en 404 y checkout sin extras.
//
//  Envío por distancia: el stub responde a la geocodificación de WordPress con candidatos fijos
//  (`hwe-shipping/v1/geocode` y `reverse-geocode`). Aquí se prueba el contrato del BFF: guardas,
//  validación de coordenadas (422 antes de llegar a WordPress) y la forma de la respuesta.
// ============================================================================

test.use({ locale: "es-ES" });

test.skip(
  !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"shipping":true'),
  "requiere el stub con modules.shipping activo (STUB_SITE_CONFIG_EXTRA)",
);

async function expectNoSeriousA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) => v.impact === "critical" || v.impact === "serious"),
  ).toHaveLength(0);
}

/** Cabeceras de una escritura válida: Origin permitido y el CSRF que emite el BFF. */
async function writeHeaders(
  request: APIRequestContext,
  baseURL: string,
): Promise<Record<string, string>> {
  const res = await request.get("/api/csrf");
  const { csrfToken } = (await res.json()) as { csrfToken: string };
  return { Origin: baseURL, "X-CSRF-Token": csrfToken };
}

/** La compra de invitado del stub: el buscador y la marca del mapa responden sin sesión. */
const GUEST_CHECKOUT = process.env.STUB_SITE_CONFIG_EXTRA?.includes(
  '"checkout_login_required":false',
);

test("el alcance del envío gratis se sirve, cacheable y en el idioma pedido", async ({
  request,
}) => {
  const res = await request.get("/api/store/free-shipping/scope?locale=es");

  expect(res.status()).toBe(200);
  expect(res.headers()["cache-control"]).toContain("s-maxage=300");
  expect((await res.json()).cities).toEqual(["Madrid"]);
});

test("el destino del carrito exige CSRF y Origin como cualquier escritura", async ({
  request,
}) => {
  const res = await request.put("/api/store/shipping/destination", {
    data: { city: "Madrid", country: "ES" },
  });

  expect([401, 403]).toContain(res.status());
});

test("el destino rechaza un pin fuera de rango o en 0,0 antes de tocar WordPress", async ({
  request,
  baseURL,
}) => {
  const headers = await writeHeaders(request, baseURL!);
  const base = { address_1: "Calle de prueba 1", city: "Madrid", country: "ES" };

  for (const location of [
    { lat: 200, lng: -3.7038 },
    { lat: 0, lng: 0 },
    { lat: "40.4168", lng: "-3.7038" },
    { lat: null, lng: -3.7038 },
  ]) {
    const res = await request.put("/api/store/shipping/destination", {
      headers,
      data: { ...base, location },
    });

    expect(res.status(), JSON.stringify(location)).toBe(422);
  }
});

test("el destino con pin exige una calle: el pin sin calle es un 422", async ({
  request,
  baseURL,
}) => {
  const res = await request.put("/api/store/shipping/destination", {
    headers: await writeHeaders(request, baseURL!),
    data: { city: "Madrid", country: "ES", location: { lat: 40.4168, lng: -3.7038 } },
  });

  expect(res.status()).toBe(422);
  expect((await res.json()).details).toHaveProperty("address_1");
});

test("el buscador del mapa exige CSRF y Origin", async ({ request }) => {
  const res = await request.post("/api/shipping/geocode", {
    data: { query: "Plaza Mayor, Madrid" },
  });

  expect([401, 403]).toContain(res.status());
});

test("el buscador rechaza una consulta vacía sin llamar a WordPress", async ({
  request,
  baseURL,
}) => {
  const res = await request.post("/api/shipping/geocode", {
    headers: await writeHeaders(request, baseURL!),
    data: { query: "   " },
  });

  expect(res.status()).toBe(422);
});

test("el buscador devuelve candidatos con coordenada, sin caché de navegador", async ({
  request,
  baseURL,
}) => {
  test.skip(!GUEST_CHECKOUT, "requiere la compra de invitado en el stub");
  const res = await request.post("/api/shipping/geocode", {
    headers: await writeHeaders(request, baseURL!),
    data: { query: "Plaza Mayor, Madrid" },
  });

  expect(res.status()).toBe(200);
  expect(res.headers()["cache-control"]).toBe("no-store");
  const { results } = (await res.json()) as {
    results: { lat: number; lng: number; label: string }[];
  };
  expect(results.length).toBeGreaterThan(0);
  for (const candidate of results) {
    expect(Number.isFinite(candidate.lat)).toBe(true);
    expect(Number.isFinite(candidate.lng)).toBe(true);
    expect(typeof candidate.label).toBe("string");
  }
});

// Presupuesto: el buscador y la inversa comparten el límite de 10/min por IP (rate-limit ANTES de
// validar). Este archivo gasta 5 peticiones por pasada; el pin 0,0 y el resto de basura se prueban
// en `PUT /api/store/shipping/destination`, que tiene su propio límite (30/min).
test("la geocodificación inversa rechaza coordenadas basura sin llamar a WordPress", async ({
  request,
  baseURL,
}) => {
  const headers = await writeHeaders(request, baseURL!);

  for (const point of [
    { lat: 91, lng: -3.7038 },
    { lat: "40.4168", lng: "-3.7038" },
  ]) {
    const res = await request.post("/api/shipping/reverse-geocode", {
      headers,
      data: point,
    });

    expect(res.status(), JSON.stringify(point)).toBe(422);
  }
});

test("la geocodificación inversa devuelve la dirección legible de un punto", async ({
  request,
  baseURL,
}) => {
  test.skip(!GUEST_CHECKOUT, "requiere la compra de invitado en el stub");
  const res = await request.post("/api/shipping/reverse-geocode", {
    headers: await writeHeaders(request, baseURL!),
    data: { lat: 40.4155, lng: -3.7074 },
  });

  expect(res.status()).toBe(200);
  expect(res.headers()["cache-control"]).toBe("no-store");
  expect(typeof (await res.json()).label).toBe("string");
});

test("el checkout ofrece los extras de envío del módulo, accesible", async ({
  page,
}) => {
  test.skip(
    !process.env.STUB_SITE_CONFIG_EXTRA?.includes('"checkout_login_required":false'),
    "requiere la compra de invitado en el stub",
  );
  await page.goto("/checkout");

  await expect(page.getByRole("group", { name: "Extras de envío" })).toBeVisible();
  await expect(page.getByRole("checkbox", { name: /Seguro de envío/ })).toBeVisible();
  await expectNoSeriousA11yViolations(page);
});
