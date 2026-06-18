import { defineConfig, devices } from "@playwright/test";

// ============================================================================
//  Configuración de Playwright (E2E).
//
//  Apunta a la app en marcha (BASE_URL, por defecto :3000). El flujo de compra
//  completo requiere la pila Docker (WordPress + WooCommerce + Redis); los
//  smoke tests solo necesitan el frontend levantado.
//
//  Ejecutar:  npx playwright install  (una vez)  &&  npm run e2e
// ============================================================================

const BASE_URL = process.env.E2E_BASE_URL ?? "http://localhost:3000";

export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 30_000,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  use: {
    baseURL: BASE_URL,
    trace: "on-first-retry",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
  ],
});
