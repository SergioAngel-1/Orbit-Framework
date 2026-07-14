# Actualizar una instancia con los cambios del framework

> El framework y cada instancia comparten historia de git: una instancia ES un clon del
> framework que divergió. Este documento define **qué rutas siguen siendo del framework**
> (se actualizan) y **cómo traer sus cambios** sin pisar el código de la instancia.

## 1. Qué es núcleo (se actualiza) y qué es instancia (no se toca)

**Núcleo del framework** — en una actualización, la versión del framework gana:

- `backend/**` (mu-plugins, tema headless, scripts, config)
- `frontend/src/lib/**`
- `frontend/src/app/api/**`
- `frontend/src/components/ui/**`
- `frontend/src/proxy.ts`, `frontend/src/instrumentation.ts`
- `frontend/next.config.mjs`, `frontend/eslint.config.mjs`, `frontend/vitest.config.ts`
- `docker-compose.yml`, `docker-compose.prod.yml`, `Caddyfile`
- `.github/workflows/**`
- `docs/**` (salvo notas propias de la instancia)
- `frontend/tests/unit/**` (tests del núcleo)

**Instancia** — la actualización NUNCA debe tocarlo (si un merge lo toca, resuelve a favor
de lo local):

- `frontend/src/components/**` (salvo `ui/`)
- `frontend/src/app/[locale]/**`
- `frontend/src/i18n/messages/**`
- `backend/scripts/instance.config.json` (no versionado) y cualquier contenido/branding

**Zona gris (revisar a mano):** `frontend/package.json` (el framework puede subir versiones de
dependencias del núcleo; la instancia puede haber añadido las suyas) y
`frontend/src/i18n/routing.ts` (si la instancia añadió locales).

## 2. Preparación (una vez por instancia)

```bash
git remote add framework <URL-del-repo-del-framework>
git fetch framework --tags
```

## 3. Procedimiento de actualización

1. Lee `CHANGELOG.md` del framework entre tu versión y la nueva (los tags `vX.Y.Z` delimitan).
   Las entradas marcadas **BREAKING** requieren acción manual descrita en la propia entrada.
2. Crea una rama: `git checkout -b chore/framework-vX.Y.Z`.
3. Trae los cambios: `git merge vX.Y.Z` (merge del tag, no de `framework/main`: actualiza
   siempre a una release, nunca a trabajo en curso).
4. Resuelve conflictos con la regla de §1: rutas de núcleo → versión del framework; rutas de
   instancia → versión local; zona gris → a mano.
5. Verifica desde `frontend/`: `npm ci && npm run test && npm run lint && npx next build`.
6. Smoke test del contrato con la pila levantada: `node examples/minimal-consumer/index.mjs`
   y prueba de humo manual (login, carrito, checkout `noop`). Despliega como cualquier otro
   cambio (`docs/DEPLOYMENT.md`).

## 4. Reglas para el framework (para que esto siga funcionando)

- Todo cambio de núcleo se publica como release taggeada + entrada de CHANGELOG.
- Un cambio que exija tocar código de instancia se marca **BREAKING** en el CHANGELOG con su
  instrucción de migración.
- El núcleo no importa código de instancia (regla de lint en `eslint.config.mjs`) — esto es lo
  que hace que los merges de arriba sean limpios.
