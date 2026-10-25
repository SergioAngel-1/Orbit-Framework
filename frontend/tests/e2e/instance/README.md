# `tests/e2e/instance/` — los E2E de TU instancia

Esta carpeta es **tuya**. El framework nunca la toca: puedes actualizar el núcleo
(`git merge vX.Y.Z`) sin conflictos en nada de lo que pongas aquí. Lo único que el
framework versiona es este README.

## Por qué existe

Los specs del núcleo (`tests/e2e/*.spec.ts`) están escritos contra las vistas del
*starter* (`frontend/src/app/[locale]/**`, `components/**`), que una instancia
reescribe por contrato (`AGENTS.md` §1.1). Por eso:

- El framework **no ejecuta** nada de esta carpeta: `playwright.config.ts` la ignora
  (`testIgnore`) en `npm run e2e` y en su CI.
- En el repositorio de una instancia (variable de repositorio `HWE_INSTANCE=true`),
  el job `e2e` del CI ejecuta **solo** esta carpeta, en sus dos pasadas: la normal y
  la de módulos apagados. Sin specs aquí el job pasa (`--pass-with-no-tests`), así
  que añádelos cuando quieras cubrir tus recorridos.

## Qué poner

Specs de Playwright normales, con `@playwright/test`, uno por flujo. El `baseURL`
sale de la app en marcha (en CI, `next start` con el *stub* de GraphQL; en local,
`E2E_BASE_URL`). En local ejecútalos con `HWE_INSTANCE=true` (sin él, `playwright.config.ts`
ignora esta carpeta): `HWE_INSTANCE=true E2E_BASE_URL=… E2E_FULL=1 npx playwright test tests/e2e/instance/`.
Los specs que necesitan tu pila real llevan `test.skip(!process.env.E2E_FULL, "…")`, como
`tests/e2e/purchase.spec.ts`: el CI (contra el stub) los salta.

- Cubre lo que es **tuyo**: tu home, tus listados y fichas, tu checkout, tus
  landings, tus textos. No repitas los specs del starter.
- Si quieres una segunda pasada contra la instalación **sin módulos**, añade tu
  propio `modules-off.spec.ts` aquí: con `HWE_INSTANCE=true` la segunda pasada
  ejecuta esta carpeta entera.
- Las limitaciones de CI (stub, no WordPress real) están en
  `docs/CREATE_INSTANCE.md`, «CI de la instancia». Los recorridos contra tu pila
  real se corren en local con `E2E_BASE_URL`.
