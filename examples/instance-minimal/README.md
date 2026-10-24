# `instance-minimal` — ejemplo vivo del contrato núcleo ↔ instancia

La instancia más pequeña que demuestra el contrato: un campo propio en el Control Center,
un tag ISR propio, una vista que lee el campo y un beneficio de membresía propio. Todo neutro
(sin nombres de ninguna tienda real).

## Qué contiene

| Ruta en este ejemplo | Se superpone a | Qué demuestra |
|---|---|---|
| `backend/wp-content/mu-plugins/instance/10-example-schema.php` | `backend/wp-content/mu-plugins/instance/` | El campo `instance.example.greeting` vía `hwe_control_center_schema` |
| `…/instance/20-example-field-type.php` | (misma carpeta) | El tipo propio `example_upper` vía `hwe_control_center_field_types` |
| `…/instance/30-example-revalidation.php` | (misma carpeta) | El tag `example` vía `hwe_revalidation_tags` |
| `…/instance/40-example-landing.php` | (misma carpeta) | Una landing con diseño propio (`example-guide`) vía `hwe_landings_static_entries` |
| `…/instance/50-example-product-field.php` | (misma carpeta) | Dos campos de producto declarativos (`example_usage`, traducible, y `example_weight`) vía `hwe_product_fields` |
| `…/instance/60-example-order-item-detail.php` | (misma carpeta) | Un detalle de línea obligatorio (`example_note`) con su validador vía `hwe_order_item_details` |
| `…/instance/70-example-membership-benefit.php` | (misma carpeta) | Un tipo de beneficio de membresía (`example_order_gift`) vía `hwe_memberships_benefit_types`: nota privada cada N pedidos cobrados |
| `backend/tests/Unit/Instance/ExampleProductFieldsTest.php` | `backend/tests/Unit/Instance/` | Los campos llegan al registro y el traducible a `hwe-content-i18n` |
| `frontend/src/instance/product-fields.d.ts` | `frontend/src/instance/` | *Module augmentation* de `ProductFieldValues`: `product.fields.example_usage` tipado |
| `backend/tests/Unit/Instance/ExampleOrderItemDetailTest.php` | `backend/tests/Unit/Instance/` | El detalle llega al registro y se valida al añadir al carrito |
| `backend/tests/Unit/Instance/ExampleMembershipBenefitTest.php` | `backend/tests/Unit/Instance/` | El beneficio se registra, se configura por nivel y solo actúa con el beneficio y el módulo encendidos |
| `backend/tests/Unit/Instance/ExamplePublicConfigTest.php` | `backend/tests/Unit/Instance/` | El campo sale por la config pública y el tag se acepta |
| `backend/tests/Unit/Instance/ExampleLandingTest.php` | `backend/tests/Unit/Instance/` | La landing del ejemplo llega al registro de `hwe-landings` y manda sobre una del panel |
| `frontend/src/instance/registry.ts` | `frontend/src/instance/registry.ts` | `revalidationTags: ["example"]`, la landing `example-guide` (con su sección única) y `reservedRootSegments` |
| `frontend/src/components/landings/editorial/unique-sections.tsx` | (mismo archivo del starter, vacío) | El mapa `clave → pieza` de las secciones únicas: `example-callout` |
| `frontend/src/instance/example-landing.test.ts` | `frontend/src/instance/` | La única se inserta tras `s1`, absorbe la FAQ y tiene pieza |
| `frontend/src/instance/config.d.ts` | `frontend/src/instance/config.d.ts` | *Module augmentation* de `InstanceConfig` |
| `frontend/src/app/[locale]/example/page.tsx` | (ruta nueva) | Vista que lee `config.instance.example.greeting` |

### Beneficio de membresía de instancia

`70-example-membership-benefit.php` demuestra el contrato del módulo `memberships`: la
instancia añade un tipo propio con el filtro `hwe_memberships_benefit_types`, declara sus
campos por nivel (`orders`, `note`) —que el panel, `wp hwe setup`, la API y el historial sanean
como los de serie— y engancha el ciclo del pedido. Cuando un pedido del socio queda cobrado y
completa el tramo configurado, deja una nota **privada** en el pedido y una marca en su meta
para no repetirla (`processing` → `completed`, webhook repetido…). Sin el beneficio activo en el
nivel, sin el módulo encendido o con un pedido no cobrado, no hace nada.

El archivo se carga como mu-plugin antes que los plugins, así que la clase del tipo se declara
dentro del callback del filtro, cuando la clase base ya existe. Es el patrón para cualquier tipo
de instancia que extienda `HWE_Memberships_Benefit`.

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
