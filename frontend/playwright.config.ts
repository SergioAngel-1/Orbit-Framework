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

// `tests/e2e/instance/` es de la instancia (AGENTS.md §1.1): el framework solo
// versiona su README y no ejecuta nada de ahí. `testDir` desciende a subcarpetas,
// así que sin este `testIgnore` los specs de una instancia que hiciera merge del
// framework se colarían en `npm run e2e` y en el CI del framework. El interruptor
// es el mismo de repositorio que el resto del CI, fail-closed: solo `true` los
// habilita (el CI de la instancia le pasa la carpeta de forma explícita).
const IS_INSTANCE_REPO = process.env.HWE_INSTANCE === "true";

export default defineConfig({
  testDir: "./tests/e2e",
  testIgnore: IS_INSTANCE_REPO ? [] : ["**/instance/**"],
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
