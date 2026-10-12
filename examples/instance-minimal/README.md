# `instance-minimal` — ejemplo vivo del contrato núcleo ↔ instancia

La instancia más pequeña que demuestra el contrato: un campo propio en el Control Center,
un tag ISR propio y una vista que lee el campo. Todo neutro (sin nombres de ninguna
tienda real).

## Qué contiene

| Ruta en este ejemplo | Se superpone a | Qué demuestra |
|---|---|---|
| `backend/wp-content/mu-plugins/instance/10-example-schema.php` | `backend/wp-content/mu-plugins/instance/` | El campo `instance.example.greeting` vía `hwe_control_center_schema` |
| `…/instance/20-example-field-type.php` | (misma carpeta) | Hueco **preparado** para un tipo de campo propio (se activa en la tarea 26) |
| `…/instance/30-example-revalidation.php` | (misma carpeta) | El tag `example` vía `hwe_revalidation_tags` |
| `backend/tests/Unit/Instance/ExamplePublicConfigTest.php` | `backend/tests/Unit/Instance/` | El campo sale por la config pública y el tag se acepta |
| `frontend/src/instance/registry.ts` | `frontend/src/instance/registry.ts` | `revalidationTags: ["example"]` |
| `frontend/src/instance/config.d.ts` | `frontend/src/instance/config.d.ts` | *Module augmentation* de `InstanceConfig` |
| `frontend/src/app/[locale]/example/page.tsx` | (ruta nueva) | Vista que lee `config.instance.example.greeting` |

## Cómo se usa en CI (job «contrato»)

El job copia este ejemplo SOBRE el repo y ejecuta `composer test`, `npm run test`,
`npm run type-check` y `npx next build`. Así se comprueba que el contrato funciona con
una instancia real enchufada, sin ensuciar el framework: el framework solo versiona su
`instance/README.md` y su registro vacío.

```bash
cp -r examples/instance-minimal/backend/. backend/
cp -r examples/instance-minimal/frontend/. frontend/
```

## Cómo empezar una instancia desde aquí

Copia las dos carpetas `instance/` (backend: `mu-plugins/instance/`; frontend:
`src/instance/`) a tu clon y renombra `example` por tu dominio. La guía completa está en
`docs/PLUGIN_DEVELOPMENT.md` y `docs/CREATE_INSTANCE.md`.
