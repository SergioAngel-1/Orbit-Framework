# Desarrollar un plugin del framework (mu-plugin + Control Center)

> Receta para añadir **lógica de negocio reutilizable** al backend headless y exponerla al
> contrato del frontend. Antes de empezar: `AGENTS.md` (mapa del proyecto) y
> `backend/wp-content/mu-plugins/README.md` (convención de carga de mu-plugins).
>
> **Criterio de admisión:** solo pertenece al framework si aporta valor reutilizable para
> múltiples proyectos. Lo específico de un cliente vive en su instancia, no aquí.

## 0. Contrato núcleo ↔ instancia (dónde vive tu código)

El framework es **backend + arquitectura**; la UI y el dominio de cada tienda son de la
instancia (`AGENTS.md §1.1`). El código de una instancia vive en rutas RESERVADAS que el
framework nunca toca, y se engancha al núcleo por hooks estables, versionados por
`HWE_CORE_CONTRACT_VERSION` (hoy `2.0.0`):

| Lado | Ruta de instancia | Qué va ahí |
|---|---|---|
| Backend | `backend/wp-content/mu-plugins/instance/*.php` | Campos del Control Center, tipos de campo, tags ISR. Los carga `hwe-instance-loader.php` en orden alfabético (ver su `README.md`). |
| Backend (grande) | `backend/wp-content/plugins/hwe-instance-<slug>/` | Plugins enteros (CPT, tablas propias). Patrón preparado en el `.gitignore`. |
| Frontend | `frontend/src/instance/` | `registry.ts` (datos del `InstanceRegistry`), `config.d.ts` (ampliación de `InstanceConfig`), `lib/` (tu dominio). |
| Frontend | `frontend/src/components/**` (salvo `ui/`) y `frontend/src/app/[locale]/**` | Tu UI y tus vistas. |

**Hooks estables del backend:**

| Hook | Tipo | Para qué |
|---|---|---|
| `hwe_control_center_schema` | filtro | Añadir campos, solo dentro del grupo `instance`. Una clave raíz nueva se descarta y un grupo del núcleo eliminado se restaura (ambos se registran). |
| `hwe_control_center_field_types` | filtro | Registrar tipos de campo propios (desde la tarea 26). |
| `hwe_control_center_saved` | acción | Reaccionar al guardado: `($publicData, $rawPost)`. Sustituye a las acciones con nombre de instancia; `AdminPage` ya no lee claves del POST fuera del schema. |
| `hwe_revalidation_tags` | filtro | Declarar tags ISR propios (el frontend debe declararlos también en `registry.ts`). |
| `hwe_modules_catalog` | filtro | Añadir módulos propios (id → slug de plugin). |

**Módulos opcionales:** la instancia los elige en `instance.config.json` (`modules:
[<id>…]`); `wp hwe setup` activa los listados y desactiva los demás del catálogo
(`banners`, `shipping`, `product-landing`, `landings`, `consent-log`, `site-reviews`,
`coupon-rules`, `customer-identity`, `external-integration`). La config pública expone
`modules: { <id>: boolean }` según si cada plugin está activo: es la fuente de verdad que
el frontend usa con `isModuleEnabled(config, id)` y `requireModule(id)` (las rutas BFF de
un módulo apagado responden 404).

**La frontera también te protege a ti:** el núcleo (`src/lib/**`, `src/app/api/**`,
`src/proxy.ts`, `src/components/ui/**`) no puede importar `@/components/**` (salvo `ui/`)
ni `@/instance/**` (salvo `@/instance/registry`); lo impone ESLint (`import type`
incluido). `InstanceRegistry` son solo datos, nunca componentes.

**Vocabulario prohibido en el núcleo:** el gate `bash .github/scripts/check-anti-instance.sh`
falla si en rutas de núcleo aparece `siu|sheilak|mascota|essence|esencia|dormilab|atlas`.
Ojo al español corriente: "esenciales" contiene "esencia" — escribe "necesario". En tu
código de instancia puedes usar las palabras que quieras; en el núcleo, ninguna de esas.

**Ejemplo vivo:** `examples/instance-minimal/` replica las rutas de instancia con un campo,
un tag y una vista. El job «contrato» de CI lo superpone al repo y ejecuta `composer
test`, `npm run test`, `type-check` y `build`: si el contrato se rompe, ese job lo dice.

## 1. Decide la forma del plugin

| Necesitas | Forma |
|---|---|
| Hooks/filtros con un solo concern | mu-plugin simple: `mu-plugins/mi-cosa.php` en la raíz |
| Varias clases | loader `mu-plugins/mi-paquete.php` + carpeta `mu-plugins/mi-paquete/` |
| Configuración editable por el administrador | sección nueva en el **Schema del Control Center** (ver §2) |
| Datos de contenido con medioteca/editor | CPT propio expuesto por WPGraphQL (última opción; evalúa antes si un campo de config basta) |

Regla: si el dato es "configuración de la instancia" (textos, flags, importes, URLs), va al
Control Center — así hereda gratis la UI de admin, `wp hwe setup`, la API pública y la
revalidación ISR del frontend.

## 2. Añadir campos al Control Center

1. **`Schema.php`** (`backend/wp-content/mu-plugins/hwe-control-center/Schema.php`) es la fuente
   única de verdad. Añade tu grupo/campos con los tipos disponibles (`text`, `textarea`, `color`,
   `url`, `email`, `select`, `boolean`, `secret`). Marca `'public' => true` SOLO si el frontend
   público debe verlo; los `secret` se cifran (AES-256-GCM) y jamás salen por la API.
2. La UI de admin (`wp-admin → HWE Config`), el comando `wp hwe setup` y el endpoint público
   `/wp-json/hwe/v1/config` se generan solos desde el Schema (walkers). No toques
   `AdminPage.php`/`RestApi.php` salvo que necesites un tipo de campo nuevo.
3. Actualiza `backend/scripts/instance.config.example.json` con los campos nuevos (los grupos
   deben coincidir con el Schema).
4. Al guardar en admin, el Control Center ya notifica al frontend (`Revalidation.php` → POST
   firmado a `/api/revalidate` → invalida el tag `site-config`). No hay nada que hacer.

### ¿Y si el dato es una lista?

El Schema no tiene (a propósito) un tipo "repeater": una lista corta se modela como `textarea`
con **una entrada por línea y campos separados por `|`**, más un parser puro en el frontend.
Ejemplos vivos: FAQ (`geo.faq` → `lib/seo/faq.ts`) y banners (`banners.slides` →
`lib/config/banners.ts`). Si tu lista necesita medioteca, orden drag-and-drop o decenas de
entradas, es señal de que toca un CPT, no un campo de config.

## 3. Leer la config desde PHP (lógica de negocio)

Patrón de `WooCurrencySync` (ejemplo canónico):

```php
$value = class_exists('\\HWE\\ControlCenter\\Storage')
    ? \HWE\ControlCenter\Storage::get(['mi_grupo', 'mi_campo'], $default)
    : $default;
```

Siempre con fallback: el plugin no debe fatal-ear si el Control Center no está.

## 4. Exponer el dato al frontend (contrato)

Para campos `public => true` no hay trabajo backend extra: ya salen en
`/wp-json/hwe/v1/config`. En el frontend del framework:

1. Añade el campo a `frontend/src/lib/config/types.ts` (interface `SiteConfig`).
2. Añade su default a `frontend/src/lib/config/defaults.ts` (DEBE coincidir con el `'default'`
   del Schema — es el fallback cuando WP no responde).
3. Si el dato necesita parseo (líneas, `|`…), crea un helper puro en `frontend/src/lib/…` con
   test unitario en `frontend/tests/unit/` (patrón: `lib/seo/faq.ts` + `lib/config/banners.ts`).
4. Si la funcionalidad es opcional, gatea la UI con el flag (patrón
   `config.ecommerce.*_enabled`).

Para datos que van por GraphQL (no por config): documenta la query en
`docs/FRONTEND_CONNECT.md` Parte A y añade los tipos a `frontend/src/types/` (o a
`frontend/src/lib/<dominio>/` si son del núcleo, como `lib/navigation/types.ts`).

**Frontera núcleo/instancia:** el código de `lib/`, `app/api/`, `proxy.ts` y `components/ui/`
no puede importar de `components/**` (salvo `ui/`) ni de `@/instance/**` (salvo
`@/instance/registry`) — hay una regla de lint que lo impide. Si tu helper necesita un
tipo que hoy vive en un componente, define el tipo en `lib/` (estructuralmente compatible) e
invierte la dependencia, como hace `BannerSlide` respecto a `HeroSlide`.

## 5. Checklist antes de dar por terminado el plugin

- [ ] `php -l` limpio en todos los `.php` nuevos.
- [ ] Campos reflejados en: `Schema.php` ↔ `instance.config.example.json` ↔ `types.ts` ↔ `defaults.ts`.
- [ ] Ningún secreto con `public => true` ni con prefijo `NEXT_PUBLIC_`.
- [ ] Contrato documentado en `docs/FRONTEND_CONNECT.md` (Parte A).
- [ ] Test unitario del parseo/lógica frontend (si aplica).
- [ ] `npx next build` en verde (si tocaste frontend).
- [ ] Entrada en `CHANGELOG.md` (`[Sin publicar]`).
- [ ] Si el mu-plugin es nuevo: fila en la tabla de `backend/wp-content/mu-plugins/README.md`.
