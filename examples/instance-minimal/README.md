# `instance-minimal` — ejemplo vivo del contrato núcleo ↔ instancia

La instancia más pequeña que demuestra el contrato: un campo propio en el Control Center,
un tag ISR propio y una vista que lee el campo. Todo neutro (sin nombres de ninguna
tienda real).

## Qué contiene

| Ruta en este ejemplo | Se superpone a | Qué demuestra |
|---|---|---|
| `backend/wp-content/mu-plugins/instance/10-example-schema.php` | `backend/wp-content/mu-plugins/instance/` | El campo `instance.example.greeting` vía `hwe_control_center_schema` |
| `…/instance/20-example-field-type.php` | (misma carpeta) | El tipo propio `example_upper` vía `hwe_control_center_field_types` |
| `…/instance/30-example-revalidation.php` | (misma carpeta) | El tag `example` vía `hwe_revalidation_tags` |
| `…/instance/40-example-landing.php` | (misma carpeta) | Una landing con diseño propio (`example-guide`) vía `hwe_landings_static_entries` |
| `…/instance/50-example-product-field.php` | (misma carpeta) | Dos campos de producto declarativos (`example_usage`, traducible, y `example_weight`) vía `hwe_product_fields` |
| `backend/tests/Unit/Instance/ExampleProductFieldsTest.php` | `backend/tests/Unit/Instance/` | Los campos llegan al registro y el traducible a `hwe-content-i18n` |
| `frontend/src/instance/product-fields.d.ts` | `frontend/src/instance/` | *Module augmentation* de `ProductFieldValues`: `product.fields.example_usage` tipado |
| `backend/tests/Unit/Instance/ExamplePublicConfigTest.php` | `backend/tests/Unit/Instance/` | El campo sale por la config pública y el tag se acepta |
| `backend/tests/Unit/Instance/ExampleLandingTest.php` | `backend/tests/Unit/Instance/` | La landing del ejemplo llega al registro de `hwe-landings` y manda sobre una del panel |
| `frontend/src/instance/registry.ts` | `frontend/src/instance/registry.ts` | `revalidationTags: ["example"]`, la landing `example-guide` (con su sección única) y `reservedRootSegments` |
| `frontend/src/components/landings/editorial/unique-sections.tsx` | (mismo archivo del starter, vacío) | El mapa `clave → pieza` de las secciones únicas: `example-callout` |
| `frontend/src/instance/example-landing.test.ts` | `frontend/src/instance/` | La única se inserta tras `s1`, absorbe la FAQ y tiene pieza |
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
