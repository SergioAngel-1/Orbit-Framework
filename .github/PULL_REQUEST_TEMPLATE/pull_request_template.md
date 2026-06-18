## Descripción

Describe los cambios introducidos y por qué son necesarios.

## Tipo de cambio

- [ ] Corrección de error (bug fix)
- [ ] Nueva funcionalidad (feature)
- [ ] Mejora de código / refactor
- [ ] Documentación
- [ ] CI / Infraestructura

## Fase del plan

Marca la fase del PRODUCTION-PLAN.md a la que pertenece este cambio:

- [ ] Fase 1 — Seguridad base
- [ ] Fase 2 — Autenticación JWT
- [ ] Fase 3 — Proxy WooCommerce
- [ ] Fase 4 — CSRF / rate-limit
- [ ] Fase 5 — E-commerce
- [ ] Fase 6 — SEO / i18n
- [ ] Fase 7 — Pagos
- [ ] Fase 8 — Calidad / CI
- [ ] Fase 9 — Empaquetado comercial

## Cómo se ha probado

Describe cómo has verificado los cambios:

- [ ] `npm run type-check` (typescript)
- [ ] `npm run lint` (eslint)
- [ ] `npm run test` (vitest)
- [ ] `npm run build` (build de producción)
- [ ] Pruebas manuales (describe el escenario)

## Checklist

- [ ] El código sigue las convenciones del proyecto (ver AGENTS.md)
- [ ] No se añadieron secretos ni claves al repositorio
- [ ] Los mensajes i18n están actualizados en `es.json` y `en.json`
- [ ] Las variables de entorno nuevas están documentadas en `.env.example`
- [ ] La documentación relevante se ha actualizado (`docs/`)

## Issues relacionados

Closes # (número de issue)
