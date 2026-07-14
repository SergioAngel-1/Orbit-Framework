# Desarrollar un plugin del framework (mu-plugin + Control Center)

> Receta para añadir **lógica de negocio reutilizable** al backend headless y exponerla al
> contrato del frontend. Antes de empezar: `AGENTS.md` (mapa del proyecto) y
> `backend/wp-content/mu-plugins/README.md` (convención de carga de mu-plugins).
>
> **Criterio de admisión:** solo pertenece al framework si aporta valor reutilizable para
> múltiples proyectos. Lo específico de un cliente vive en su instancia, no aquí.

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

Patrón de `woocommerce-shipping-config.php` (ejemplo canónico):

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

**Frontera núcleo/instancia:** el código de `lib/` y `app/api/` no puede importar de
`components/**` (salvo `ui/`) — hay una regla de lint que lo impide. Si tu helper necesita un
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
