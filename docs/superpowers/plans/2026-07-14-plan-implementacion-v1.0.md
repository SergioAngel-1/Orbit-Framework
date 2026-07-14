# Plan de implementación — Framework HWE hacia v1.0

> **✅ EJECUTADO el 2026-07-14** — las 16 tareas completadas; releases `v0.9.0` y `v1.0.0`
> taggeadas. Desviación registrada: la Fase 0 incluyó además reparar dos fallos preexistentes
> de la línea base (ESLint roto por FlatCompat sobre los flat configs nativos de
> eslint-config-next 16 + eslint 10 no soportado, y `format:check` en rojo en 122 archivos).
> Pendiente de verificación en vivo (pila WP no levantada durante la ejecución): locations de
> menú en wp-admin, siembra de `setup.sh`, render real del logo/menús/banners y el smoke test
> `examples/minimal-consumer`.

> **Para agentes que ejecuten este plan:** SKILL REQUERIDA: usa `superpowers:subagent-driven-development`
> (recomendado) o `superpowers:executing-plans` para implementarlo tarea a tarea. Los pasos usan
> checkboxes (`- [ ]`) para seguimiento.
>
> **Contexto obligatorio antes de empezar:** lee `AGENTS.md` (raíz del repo) y `CLAUDE.md`.
> Este plan nace de la auditoría del 2026-07-14 (ver conversación/CHANGELOG): el framework es
> **backend + BFF**, no un generador de UI. Ninguna tarea de este plan añade componentes visuales
> al núcleo; los datos se gestionan en WordPress y las vistas heredadas solo se tocan como
> *implementación de referencia*.

**Goal:** Cerrar las brechas detectadas en la auditoría (menús/logo/banners gestionables desde WP, SDK formalizado, guía de plugins, frontera núcleo/instancia ejecutable, estrategia de actualización) y publicar la release v1.0.0 del framework.

**Architecture:** WordPress headless (mu-plugins + HWE Control Center) expone datos por `/wp-json/hwe/v1/config` y WPGraphQL; Next.js 16 actúa de web pública + BFF. Todo dato nuevo se añade al **Schema del Control Center** (config) o al **contrato GraphQL** (menús), nunca hardcodeado en el frontend. Las vistas heredadas (`components/**` salvo `ui/`, `app/[locale]/*`) se adaptan solo como referencia de consumo.

**Tech Stack:** PHP 8 (mu-plugins WP), Next.js 16 (App Router) + React 19 + TypeScript, Tailwind v4, Vitest, WPGraphQL/WooGraphQL, WP-CLI, Docker Compose.

## Global Constraints

- Idioma del proyecto: **docs/comentarios/UI en español, código y nombres técnicos en inglés**.
- Toda clave i18n nueva en `es.json` va también en `en.json` (paralelas).
- Navegación interna SIEMPRE desde `@/i18n/navigation` (nunca `next/link`/`next/navigation`, salvo `notFound`).
- La marca/URL del sitio SIEMPRE sale de `getSiteConfig()`; ningún valor de marca hardcodeado.
- Módulos de servidor llevan `import "server-only";`.
- Secretos sin prefijo `NEXT_PUBLIC_`.
- **`npx next build` (desde `frontend/`) es obligatorio antes de dar por terminada cualquier tarea que toque el frontend.** `tsc`/`lint` no bastan.
- Campos nuevos del Control Center: deben coincidir en `Schema.php`, `instance.config.example.json`, `frontend/src/lib/config/types.ts` y `frontend/src/lib/config/defaults.ts` (los defaults de código deben coincidir con los `'default'` del Schema).
- Mensajes de commit en español, prefijo convencional (`feat:`, `fix:`, `docs:`, `chore:`).
- No metas secretos en el repo; solo placeholders en `.env.example`.
- Sé honesto sobre lo no verificado en vivo: login/carrito/checkout/webhooks/menús reales requieren la pila WP levantada. Si no la levantaste, dilo en el resumen de la tarea.
- Verificación de PHP sin pila: `php -l <archivo>`. Si no hay PHP nativo: `docker compose run --rm wpcli php -l /var/www/html/wp-content/<ruta>`.
- Los tests unitarios viven en `frontend/tests/unit/*.test.ts` y se corren con `npx vitest run tests/unit/<archivo>` desde `frontend/`.

## Fuera de alcance (post-v1.0 — NO lo implementes en este plan)

- Pasarela de pago real (Wompi/PayU/Bold) — la capa agnóstica ya existe; la integración real es un proyecto propio.
- E2E de compra completa en CI (nightly con pila Docker).
- GraphQL codegen para tipos (requiere pila/esquema en CI).
- Envío multi-zona/multi-método.
- CSP con nonce y endurecimientos de Fase 8 documentados.

---

# FASE 0 — Línea base limpia

*Objetivo: repo consistente y una versión formal (v0.9.0) desde la que medir el resto. Sin cambios funcionales.*

### Task 1: Higiene del repo (husky + CLAUDE.md)

Contexto: `.husky/pre-commit` está borrado del working tree sin commitear, pero `package.json`
conserva `"prepare": "husky"`, el bloque `lint-staged` y ambas devDependencies. La CI ya cubre
lint/format/tests, así que se consolida la **eliminación** de husky. `CLAUDE.md` existe en la raíz
pero está sin trackear.

**Files:**
- Modify: `frontend/package.json` (quitar `scripts.prepare`, bloque `lint-staged`, devDeps `husky` y `lint-staged`)
- Modify: `frontend/package-lock.json` (regenerado por npm)
- Delete (confirmar borrado ya hecho): `.husky/pre-commit`
- Commit (nuevo tracked): `CLAUDE.md`

**Interfaces:**
- Consumes: nada.
- Produces: repo sin referencias a husky; `CLAUDE.md` versionado.

- [ ] **Step 1: Confirmar el estado actual**

Run: `cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem" && git status --short`
Expected: línea ` D .husky/pre-commit` y `?? CLAUDE.md` (si además hay archivos de este plan, ignóralos aquí).

- [ ] **Step 2: Eliminar husky/lint-staged de package.json y desinstalar**

Run:
```bash
cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem/frontend"
npm pkg delete scripts.prepare lint-staged
npm uninstall husky lint-staged
```
Expected: `npm pkg get scripts.prepare lint-staged` devuelve `{}` para ambos; `package-lock.json` actualizado sin `husky` ni `lint-staged`.

- [ ] **Step 3: Verificar que nada más referencia husky**

Run: `cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem" && grep -rn "husky\|lint-staged" --include="*.json" --include="*.yml" --include="*.md" --exclude-dir=node_modules --exclude-dir=.git . | grep -v package-lock.json | grep -v CHANGELOG | grep -v docs/superpowers`
Expected: sin resultados (o solo menciones históricas en CHANGELOG, que no se tocan).

- [ ] **Step 4: Commit de la limpieza de husky**

```bash
cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem"
git add .husky frontend/package.json frontend/package-lock.json
git commit -m "chore: retira husky/lint-staged; la calidad la impone la CI (lint, format, tests, build)"
```

- [ ] **Step 5: Commitear CLAUDE.md**

```bash
git add CLAUDE.md
git commit -m "docs: versiona CLAUDE.md (guía de trabajo para Claude Code)"
```

- [ ] **Step 6: Verificación de humo del frontend**

Run: `cd frontend && npm run lint && npm run type-check`
Expected: ambos exit 0.

### Task 2: Release v0.9.0

Contexto: `git tag` está vacío y el CHANGELOG solo tiene `[Sin publicar]`. Sin una versión formal
no se puede hablar de actualización de instancias ni de v1.0.

**Files:**
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Task 1 commiteada.
- Produces: tag anotado `v0.9.0`; sección `[0.9.0]` en CHANGELOG; nueva sección `[Sin publicar]` vacía donde las fases siguientes irán anotando cambios.

- [ ] **Step 1: Convertir "[Sin publicar]" en la release 0.9.0**

En `CHANGELOG.md`, sustituye la línea:

```markdown
## [Sin publicar]
```

por:

```markdown
## [Sin publicar]

## [0.9.0] — 2026-07-14
```

(La fecha: usa la fecha real del día en que ejecutes esto, formato ISO. Todo el contenido que
colgaba de "[Sin publicar]" queda ahora bajo `[0.9.0]`.)

- [ ] **Step 2: Commit y tag anotado**

```bash
cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem"
git add CHANGELOG.md
git commit -m "chore: release v0.9.0 — línea base del framework previa al plan v1.0"
git tag -a v0.9.0 -m "HWE framework v0.9.0 — línea base auditada"
```

- [ ] **Step 3: Verificar**

Run: `git tag && git describe`
Expected: `v0.9.0` en ambos.

---

# FASE 1 — Control Center completo (logo + menús + footer)

*Objetivo: cerrar el roadmap "Plugin de Configuración": logo, menús con selección de menú activo y footer administrables desde `wp-admin`, expuestos como **datos del contrato** (no como UI del framework). Depende de Fase 0 solo por orden de commits.*

### Task 3: Campo de logo en el Control Center

**Files:**
- Modify: `backend/wp-content/mu-plugins/hwe-control-center/Schema.php` (grupo `brand`)
- Modify: `backend/scripts/instance.config.example.json` (grupo `brand`)
- Modify: `frontend/src/lib/config/types.ts`
- Modify: `frontend/src/lib/config/defaults.ts`
- Modify: `frontend/src/components/layout/site-header.tsx` (prop `logoUrl`, render de referencia)
- Modify: `frontend/src/app/[locale]/layout.tsx` (pasar `logoUrl`)

**Interfaces:**
- Consumes: `getSiteConfig(): Promise<SiteConfig>` de `@/lib/config` (ya existe; el layout ya la llama y tiene `config` en scope).
- Produces: `SiteConfig.brand.logo: string` (URL, vacío = sin logo); `SiteHeader` acepta prop opcional `logoUrl?: string`.

- [ ] **Step 1: Añadir el campo al Schema**

En `backend/wp-content/mu-plugins/hwe-control-center/Schema.php`, dentro de
`'brand' => [ 'children' => [ ... ] ]`, inmediatamente después del campo `'url'`, añade:

```php
                    'logo'        => [
                        'type'        => 'url',
                        'label'       => 'Logo (URL)',
                        'public'      => true,
                        'description' => 'URL del logo de cabecera (súbelo a la medioteca y pega aquí su URL). Vacío = se muestra el nombre del sitio como texto.',
                    ],
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run: `php -l "backend/wp-content/mu-plugins/hwe-control-center/Schema.php"`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Añadir el campo a la plantilla de instancia**

En `backend/scripts/instance.config.example.json`, en el objeto `"brand"`, tras `"url"`, añade:

```json
    "logo": "",
```

Run: `python3 -m json.tool backend/scripts/instance.config.example.json > /dev/null && echo OK`
Expected: `OK`.

- [ ] **Step 4: Tipar y dar default en el frontend**

En `frontend/src/lib/config/types.ts`, en `brand`, tras `url: string;` añade:

```ts
    /** URL del logo de cabecera. Vacío = mostrar el nombre del sitio como texto. */
    logo: string;
```

En `frontend/src/lib/config/defaults.ts`, en `brand`, tras la línea de `url`, añade:

```ts
    logo: "",
```

- [ ] **Step 5: Aceptar el logo en el header heredado (implementación de referencia)**

En `frontend/src/components/layout/site-header.tsx`:

1. Cambia la firma del componente para aceptar props:

```tsx
export interface SiteHeaderProps {
  /** URL del logo (config.brand.logo). Vacío/undefined = nombre del sitio como texto. */
  logoUrl?: string;
}

export function SiteHeader({ logoUrl }: SiteHeaderProps = {}) {
```

2. Sustituye el contenido del `<Link href="/" ...>` de la marca (el que hoy renderiza
`{tSite("name")}`) por:

```tsx
          <Link
            href="/"
            className="flex items-center text-lg font-bold tracking-tight text-[--foreground]"
            aria-label={tSite("name")}
          >
            {logoUrl ? (
              // eslint-disable-next-line @next/next/no-img-element -- URL remota definida por la instancia (medioteca WP); no se conocen los dominios en build.
              <img src={logoUrl} alt={tSite("name")} className="h-8 w-auto" />
            ) : (
              tSite("name")
            )}
          </Link>
```

- [ ] **Step 6: Pasar el logo desde el layout**

En `frontend/src/app/[locale]/layout.tsx`, sustituye `<SiteHeader />` por:

```tsx
              <SiteHeader logoUrl={config.brand.logo || undefined} />
```

(`config` ya está en scope: el layout llama `getSiteConfig()`.)

- [ ] **Step 7: Verificar con build**

Run: `cd frontend && npm run type-check && npm run lint && npx next build`
Expected: exit 0 en los tres. Si `next build` falla por prerender, lee el error: casi siempre es un límite server/client.

- [ ] **Step 8: Commit**

```bash
git add backend/wp-content/mu-plugins/hwe-control-center/Schema.php backend/scripts/instance.config.example.json frontend/src/lib/config/types.ts frontend/src/lib/config/defaults.ts frontend/src/components/layout/site-header.tsx "frontend/src/app/[locale]/layout.tsx"
git commit -m "feat: logo de marca administrable desde HWE Control Center (brand.logo)"
```

### Task 4: Menús de WordPress — backend (locations + seed)

Contexto: WPGraphQL expone los menús nativos de WP (`menuItems(where:{location:…})`), pero solo si
el tema registra *nav menu locations*. La "selección del menú activo" es la asignación nativa de
WP en Apariencia → Menús. Se registran locations por área e idioma (convención: sin sufijo = locale
por defecto `es`; sufijo `_en` = inglés).

**Files:**
- Modify: `backend/wp-content/themes/hwe-headless-base/functions.php`
- Modify: `backend/scripts/setup.sh` (siembra de menú por defecto)

**Interfaces:**
- Consumes: nada.
- Produces: locations `primary`, `primary_en`, `footer`, `footer_en` (en WPGraphQL: enums `PRIMARY`, `PRIMARY_EN`, `FOOTER`, `FOOTER_EN`); un menú "Principal" sembrado y asignado a `primary` en instalaciones nuevas.

- [ ] **Step 1: Registrar las nav menu locations en el tema**

En `backend/wp-content/themes/hwe-headless-base/functions.php`, dentro del callback de
`after_setup_theme`, tras `add_theme_support( 'title-tag' );`, añade:

```php
			// Locations de menú para el frontend headless (WPGraphQL las expone
			// vía menuItems(where:{location:…}). Convención: sin sufijo = locale
			// por defecto (es); sufijo _en = versión en inglés del menú.
			register_nav_menus(
				[
					'primary'    => 'Navegación principal',
					'primary_en' => 'Navegación principal (EN)',
					'footer'     => 'Pie de página',
					'footer_en'  => 'Pie de página (EN)',
				]
			);
```

Run: `php -l backend/wp-content/themes/hwe-headless-base/functions.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: Sembrar un menú por defecto en setup.sh**

En `backend/scripts/setup.sh`, localiza el bloque donde se activa el tema
(busca `hwe-headless-base`) e inserta **después** de ese bloque:

```bash
# ── Menú de navegación por defecto ─────────────────────────────────────────
# Gestionable después desde wp-admin → Apariencia → Menús. Los items son
# "enlaces personalizados" con rutas RELATIVAS del frontend (contrato en
# docs/FRONTEND_CONNECT.md §A.6).
if ! $WP menu list --fields=slug --format=csv 2>/dev/null | grep -q "^principal$"; then
	echo "==> Creando menú de navegación por defecto (Principal)..."
	$WP menu create "Principal" 2>/dev/null || true
	$WP menu item add-custom principal "Inicio" "/" 2>/dev/null || true
	$WP menu item add-custom principal "Tienda" "/products" 2>/dev/null || true
	$WP menu item add-custom principal "Blog" "/blog" 2>/dev/null || true
	$WP menu item add-custom principal "Sobre nosotros" "/about" 2>/dev/null || true
	$WP menu location assign principal primary 2>/dev/null || true
fi
```

(Usa la misma variable `$WP` que el resto del script y tabs si el archivo usa tabs.)

- [ ] **Step 3: Verificar sintaxis del script**

Run: `bash -n backend/scripts/setup.sh && echo OK`
Expected: `OK`.

- [ ] **Step 4: (Solo si la pila está levantada) verificación en vivo**

Si `docker compose ps` muestra la pila corriendo:

```bash
docker compose run --rm wpcli wp theme activate hwe-headless-base --path=/var/www/html --allow-root
docker compose run --rm wpcli wp menu location list --path=/var/www/html --allow-root
```
Expected: las cuatro locations listadas. Si la pila NO está levantada, declara en el resumen que la verificación en vivo queda pendiente.

- [ ] **Step 5: Commit**

```bash
git add backend/wp-content/themes/hwe-headless-base/functions.php backend/scripts/setup.sh
git commit -m "feat: nav menu locations en el tema headless + siembra de menú por defecto"
```

### Task 5: Menús de WordPress — frontend (helper del núcleo + header de referencia)

**Files:**
- Create: `frontend/src/lib/navigation/types.ts`
- Create: `frontend/src/lib/navigation/menu.ts`
- Test: `frontend/tests/unit/menu.test.ts`
- Modify: `frontend/src/components/layout/site-header.tsx`
- Modify: `frontend/src/app/[locale]/layout.tsx`

**Interfaces:**
- Consumes: `fetchGraphQL<TData>(query, { variables, revalidate, tags })` de `@/lib/graphql-client`; `routing.defaultLocale` de `@/i18n/routing`; `SiteHeaderProps` de Task 3.
- Produces:
  - `MenuLink { label: string; href: string; children?: MenuLink[] }` (en `@/lib/navigation/types`).
  - `getMenu(area: "primary" | "footer", locale: string): Promise<MenuLink[] | null>` (server-only; `null` = sin menú asignado o WP caído → el caller usa su fallback).
  - `buildMenuTree(nodes: MenuItemNode[]): MenuLink[]` (pura, exportada para test).
  - `SiteHeader` acepta prop `items?: MenuLink[]`.

- [ ] **Step 1: Crear el tipo compartido (sin server-only, importable desde client con `import type`)**

Create `frontend/src/lib/navigation/types.ts`:

```ts
/** Enlace de menú resuelto desde WordPress (Apariencia → Menús) o desde el fallback local. */
export interface MenuLink {
  label: string;
  /** Ruta relativa del frontend (p. ej. "/products") o URL absoluta externa. */
  href: string;
  children?: MenuLink[];
}
```

- [ ] **Step 2: Escribir el test (falla primero)**

Create `frontend/tests/unit/menu.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { buildMenuTree, type MenuItemNode } from "@/lib/navigation/menu";

const node = (id: string, label: string, uri: string, parentId: string | null = null): MenuItemNode => ({
  id,
  label,
  uri,
  parentId,
});

describe("buildMenuTree", () => {
  it("devuelve lista plana cuando no hay jerarquía", () => {
    const tree = buildMenuTree([node("1", "Inicio", "/"), node("2", "Tienda", "/products")]);
    expect(tree).toEqual([
      { label: "Inicio", href: "/" },
      { label: "Tienda", href: "/products" },
    ]);
  });

  it("anida hijos bajo su padre respetando el orden", () => {
    const tree = buildMenuTree([
      node("1", "Tienda", "/products"),
      node("2", "Ofertas", "/products?on_sale=1", "1"),
      node("3", "Novedades", "/products?orderby=date", "1"),
      node("4", "Blog", "/blog"),
    ]);
    expect(tree).toEqual([
      {
        label: "Tienda",
        href: "/products",
        children: [
          { label: "Ofertas", href: "/products?on_sale=1" },
          { label: "Novedades", href: "/products?orderby=date" },
        ],
      },
      { label: "Blog", href: "/blog" },
    ]);
  });

  it("ignora huérfanos cuyo padre no existe (los promociona a nivel raíz)", () => {
    const tree = buildMenuTree([node("2", "Suelto", "/x", "no-existe")]);
    expect(tree).toEqual([{ label: "Suelto", href: "/x" }]);
  });

  it("descarta items sin label o sin uri", () => {
    const tree = buildMenuTree([node("1", "", "/"), node("2", "Ok", "")]);
    expect(tree).toEqual([]);
  });
});
```

- [ ] **Step 3: Verificar que falla**

Run: `cd frontend && npx vitest run tests/unit/menu.test.ts`
Expected: FAIL — no existe `@/lib/navigation/menu`.

- [ ] **Step 4: Implementar el helper**

Create `frontend/src/lib/navigation/menu.ts`:

```ts
import "server-only";
import { fetchGraphQL } from "@/lib/graphql-client";
import { routing } from "@/i18n/routing";
import type { MenuLink } from "./types";

// ============================================================================
//  Menús de WordPress (Apariencia → Menús) para el frontend headless.
//
//  El tema hwe-headless-base registra las locations `primary`/`footer` (locale
//  por defecto) y `primary_en`/`footer_en` (inglés). "Seleccionar el menú
//  activo" = asignar un menú a la location en wp-admin. Si la location no
//  tiene menú asignado (o WP no responde), getMenu devuelve null y el caller
//  usa su fallback local. Contrato: docs/FRONTEND_CONNECT.md §A.6.
// ============================================================================

export interface MenuItemNode {
  id: string;
  parentId: string | null;
  label: string;
  uri: string;
}

interface MenuQueryData {
  menuItems: { nodes: MenuItemNode[] };
}

const MENU_QUERY = /* GraphQL */ `
  query MenuByLocation($location: MenuLocationEnum!) {
    menuItems(where: { location: $location }, first: 100) {
      nodes {
        id
        parentId
        label
        uri
      }
    }
  }
`;

/** Convierte los nodos planos de WPGraphQL en un árbol de dos niveles. */
export function buildMenuTree(nodes: MenuItemNode[]): MenuLink[] {
  const valid = nodes.filter((n) => n.label && n.uri);
  const ids = new Set(valid.map((n) => n.id));

  const roots: MenuLink[] = [];
  const byId = new Map<string, MenuLink>();

  for (const n of valid) {
    const link: MenuLink = { label: n.label, href: n.uri };
    byId.set(n.id, link);
    if (n.parentId && ids.has(n.parentId)) {
      const parent = byId.get(n.parentId);
      if (parent) {
        parent.children = parent.children ?? [];
        parent.children.push(link);
        continue;
      }
    }
    roots.push(link);
  }
  return roots;
}

/** Location de WPGraphQL para un área e idioma (enum en MAYÚSCULAS). */
function toLocationEnum(area: "primary" | "footer", locale: string): string {
  const suffix = locale === routing.defaultLocale ? "" : `_${locale}`;
  return `${area}${suffix}`.toUpperCase();
}

/**
 * Menú asignado a la location del área/idioma, o `null` si no hay menú
 * asignado, la location no existe o WordPress no responde (fail-soft:
 * la navegación nunca debe tumbar el render).
 */
export async function getMenu(
  area: "primary" | "footer",
  locale: string,
): Promise<MenuLink[] | null> {
  try {
    const data = await fetchGraphQL<MenuQueryData>(MENU_QUERY, {
      variables: { location: toLocationEnum(area, locale) },
      revalidate: 300,
      tags: ["menus"],
    });
    const tree = buildMenuTree(data.menuItems.nodes);
    return tree.length > 0 ? tree : null;
  } catch {
    return null;
  }
}
```

- [ ] **Step 5: Verificar que el test pasa**

Run: `cd frontend && npx vitest run tests/unit/menu.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 6: Aceptar items externos en el header (referencia)**

En `frontend/src/components/layout/site-header.tsx`:

1. Añade el import de tipo (arriba, junto a los demás):

```tsx
import type { MenuLink } from "@/lib/navigation/types";
```

2. Amplía las props (definidas en Task 3):

```tsx
export interface SiteHeaderProps {
  /** URL del logo (config.brand.logo). Vacío/undefined = nombre del sitio como texto. */
  logoUrl?: string;
  /** Menú resuelto desde WP (getMenu). undefined/null = fallback NAV_ITEMS + i18n. */
  items?: MenuLink[] | null;
}

export function SiteHeader({ logoUrl, items }: SiteHeaderProps = {}) {
```

3. Dentro del componente, tras obtener `t` y antes del `return`, resuelve los items efectivos:

```tsx
  const navItems: MenuLink[] =
    items ??
    NAV_ITEMS.map((item) => ({
      label: t(item.key),
      href: item.href,
      children: item.children?.map((child) => ({
        label: t(child.key),
        href: child.href,
      })),
    }));
```

4. En el render de navegación desktop, cambia `NAV_ITEMS.map((item) => {` por
`navItems.map((item) => {`, y dentro del map:
   - sustituye cada `key={item.key}` por `key={item.href + item.label}` y cada
     `key={child.key}` por `key={child.href + child.label}`;
   - sustituye cada `{t(item.key)}` por `{item.label}` y cada `{t(child.key)}` por `{child.label}`;
   - la condición de dropdown pasa de `if (item.children)` a
     `if (item.children && item.children.length > 0)`.
5. Haz los mismos reemplazos en el menú móvil (más abajo en el archivo, itera también sobre
   `NAV_ITEMS`): usa `navItems`, `item.label` y keys por `href + label`.
6. Las interfaces locales `NavChild`/`NavItem` y la constante `NAV_ITEMS` se quedan como están
   (son el fallback i18n).

- [ ] **Step 7: Pasar el menú desde el layout**

En `frontend/src/app/[locale]/layout.tsx`:

1. Añade el import:

```tsx
import { getMenu } from "@/lib/navigation/menu";
```

2. Tras `const config = await getSiteConfig();` añade:

```tsx
  const primaryMenu = await getMenu("primary", locale);
```

3. Sustituye el `<SiteHeader …>` por:

```tsx
              <SiteHeader logoUrl={config.brand.logo || undefined} items={primaryMenu} />
```

- [ ] **Step 8: Verificar con build y tests completos**

Run: `cd frontend && npm run test && npm run type-check && npm run lint && npx next build`
Expected: todo exit 0. Nota: en build sin WP levantado, `getMenu` devuelve `null` (fail-soft) y el header usa el fallback — el build NO debe fallar por WP caído.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/lib/navigation frontend/tests/unit/menu.test.ts frontend/src/components/layout/site-header.tsx "frontend/src/app/[locale]/layout.tsx"
git commit -m "feat: menús gestionables desde WordPress (locations + getMenu) con fallback local"
```

### Task 6: Footer administrable desde el menú `footer`

**Files:**
- Modify: `frontend/src/components/layout/site-footer.tsx`
- Modify: `frontend/src/i18n/messages/es.json` y `frontend/src/i18n/messages/en.json` (clave `footer.links`)

**Interfaces:**
- Consumes: `getMenu("footer", locale)` y `MenuLink` de Task 5; `FooterColumn { heading, links }` ya definido en `site-footer.tsx`.
- Produces: el footer heredado consume el menú `footer` de WP si está asignado; mantiene sus columnas por defecto si no.

- [ ] **Step 1: Añadir la clave i18n paralela**

En `frontend/src/i18n/messages/es.json`, dentro del objeto `"footer"`, añade:

```json
    "links": "Enlaces",
```

En `frontend/src/i18n/messages/en.json`, dentro del objeto `"footer"`, añade:

```json
    "links": "Links",
```

- [ ] **Step 2: Consumir el menú footer en el componente**

En `frontend/src/components/layout/site-footer.tsx`:

1. Añade imports:

```tsx
import { getLocale } from "next-intl/server";
import { getMenu } from "@/lib/navigation/menu";
import type { MenuLink } from "@/lib/navigation/types";
```

2. Añade esta función a nivel de módulo (encima del componente):

```tsx
/**
 * Convierte el menú `footer` de WP en columnas: cada item raíz CON hijos es
 * una columna (heading = label del padre); los items raíz sueltos se agrupan
 * en una única columna final con el heading genérico indicado.
 */
function menuToColumns(menu: MenuLink[], looseHeading: string): FooterColumn[] {
  const columns: FooterColumn[] = [];
  const loose: FooterLink[] = [];

  for (const item of menu) {
    if (item.children && item.children.length > 0) {
      columns.push({
        heading: item.label,
        links: item.children.map((c) => ({ label: c.label, href: c.href })),
      });
    } else {
      loose.push({ label: item.label, href: item.href });
    }
  }
  if (loose.length > 0) {
    columns.push({ heading: looseHeading, links: loose });
  }
  return columns;
}
```

3. Dentro del componente, amplía el `Promise.all` existente para obtener también el locale:

```tsx
  const [tFooter, tNav, tSite, config, locale] = await Promise.all([
    getTranslations("footer"),
    getTranslations("nav"),
    getTranslations("site"),
    getSiteConfig(),
    getLocale(),
  ]);

  const footerMenu = await getMenu("footer", locale);
```

4. Cambia el cálculo de columnas. La expresión actual es
`const footerColumns: FooterColumn[] = columns ?? [ …dos columnas por defecto… ];`
Sustitúyela por (conservando literalmente las dos columnas por defecto que ya existen como
último fallback):

```tsx
  const footerColumns: FooterColumn[] =
    columns ??
    (footerMenu ? menuToColumns(footerMenu, tFooter("links")) : null) ??
    [
      {
        heading: tNav("store"),
        links: [
          { label: tNav("allProducts"), href: "/products" },
          { label: tNav("offers"), href: "/products" },
          { label: tNav("blog"), href: "/blog" },
        ],
      },
      {
        heading: tFooter("company"),
        links: [
          { label: tNav("about"), href: "/about" },
          { label: tNav("contact"), href: "/contact" },
          { label: tNav("terms"), href: "/legal/terms" },
        ],
      },
    ];
```

- [ ] **Step 3: Verificar**

Run: `cd frontend && npm run type-check && npm run lint && npx next build`
Expected: exit 0. (El orden de prioridad queda: props explícitas > menú WP > defaults i18n.)

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/layout/site-footer.tsx frontend/src/i18n/messages/es.json frontend/src/i18n/messages/en.json
git commit -m "feat: footer heredado consume el menú 'footer' de WordPress si está asignado"
```

### Task 7: Documentar menús y logo en el contrato + CHANGELOG

**Files:**
- Modify: `docs/FRONTEND_CONNECT.md`
- Modify: `CHANGELOG.md` (sección `[Sin publicar]`)

**Interfaces:**
- Consumes: lo implementado en Tasks 3–6.
- Produces: contrato §A.6 (menús) y mención del logo en §A.3.

- [ ] **Step 1: Añadir la sección de menús al contrato**

En `docs/FRONTEND_CONNECT.md`, al final de la Parte A (tras la sección `## A.5 Webhooks salientes…`
y antes de `# Parte B`), añade:

```markdown
## A.6 Menús de navegación (WordPress → cualquier frontend)

Los menús se gestionan en **wp-admin → Apariencia → Menús** y se exponen por WPGraphQL.
El tema `hwe-headless-base` registra cuatro *locations*; asignar un menú a una location
es la "selección del menú activo":

| Location | Enum WPGraphQL | Uso |
|---|---|---|
| `primary` | `PRIMARY` | Navegación principal (locale por defecto, `es`) |
| `primary_en` | `PRIMARY_EN` | Navegación principal en `/en` |
| `footer` | `FOOTER` | Columnas del pie (locale por defecto) |
| `footer_en` | `FOOTER_EN` | Columnas del pie en `/en` |

**Regla de contenido:** los items deben ser **enlaces personalizados con rutas relativas del
frontend** (`/products`, `/blog`, `/contact`…). No uses páginas/entradas de WP: sus permalinks
apuntan a WordPress, no al frontend.

Query de referencia:

​```graphql
query MenuByLocation($location: MenuLocationEnum!) {
  menuItems(where: { location: $location }, first: 100) {
    nodes { id parentId label uri }
  }
}
​```

En el frontend heredado ya hay un consumidor listo: `lib/navigation/menu.ts`
(`getMenu(area, locale)` — ISR 5 min, tag `menus`, fail-soft a `null`). Un item raíz **con
hijos** se renderiza como dropdown (header) o columna (footer). Si la location no tiene menú
asignado, el frontend heredado usa su navegación local por defecto.
​```
```

(Nota para el ejecutor: los ``` interiores del bloque anterior van SIN el carácter zero-width —
escríbelos como fences normales de markdown anidados o usa indentación de 4 espacios para el
ejemplo GraphQL si el render lo rompe.)

- [ ] **Step 2: Mencionar el logo en A.3**

En `docs/FRONTEND_CONNECT.md`, sección `## A.3 Config pública dinámica`, donde se listan los
campos de `brand`, añade una línea:

```markdown
- `brand.logo` — URL del logo de cabecera (medioteca WP). Vacío = renderizar `brand.name` como texto.
```

(Si la sección lista campos en tabla o en prosa, intégralo con el formato existente.)

- [ ] **Step 3: Anotar el CHANGELOG**

En `CHANGELOG.md`, bajo `## [Sin publicar]`, añade:

```markdown
### Añadido
- **Logo de marca** administrable desde HWE Control Center (`brand.logo`), consumido por el
  header heredado y expuesto en `/wp-json/hwe/v1/config`.
- **Menús gestionables desde WordPress**: locations `primary`/`footer` (+ variantes `_en`) en el
  tema headless, siembra de menú por defecto en `setup.sh`, helper de núcleo
  `lib/navigation/menu.ts` (`getMenu`) y consumo de referencia en header/footer heredados con
  fallback local. Contrato documentado en `docs/FRONTEND_CONNECT.md §A.6`.
```

- [ ] **Step 4: Commit**

```bash
git add docs/FRONTEND_CONNECT.md CHANGELOG.md
git commit -m "docs: contrato de menús (A.6) y logo de marca en FRONTEND_CONNECT + CHANGELOG"
```

---

# FASE 2 — SDK formalizado (errores tipados + guía de plugins + ejemplo mínimo)

*Objetivo: que un frontend externo (u otro agente) pueda integrarse sin leer el código del frontend heredado. Depende de Fase 1 porque el contrato documentado ya incluye menús/logo.*

### Task 8: Errores tipados en el cliente GraphQL

Contexto: `fetchGraphQL` lanza `Error` genérico; los consumidores no pueden distinguir
red / HTTP / error GraphQL sin parsear strings. Se introduce `GraphQLClientError` **extendiendo
`Error`** (compatible hacia atrás: los `catch (e) { e.message }` existentes siguen funcionando).

**Files:**
- Modify: `frontend/src/lib/graphql-client.ts`
- Test: `frontend/tests/unit/graphql-client.test.ts`

**Interfaces:**
- Consumes: nada nuevo.
- Produces:
  ```ts
  class GraphQLClientError extends Error {
    kind: "network" | "http" | "graphql" | "empty";
    status?: number;               // solo kind === "http"
    errors?: { message: string }[]; // solo kind === "graphql"
  }
  ```
  exportada desde `@/lib/graphql-client`.

- [ ] **Step 1: Escribir el test (falla primero)**

Create `frontend/tests/unit/graphql-client.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { fetchGraphQL, GraphQLClientError } from "@/lib/graphql-client";

const QUERY = "query { __typename }";

function mockFetchOnce(response: Partial<Response> & { jsonBody?: unknown; textBody?: string }) {
  const { jsonBody, textBody, ...rest } = response;
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      statusText: "OK",
      json: async () => jsonBody,
      text: async () => textBody ?? "",
      ...rest,
    } as Response),
  );
}

describe("fetchGraphQL — errores tipados", () => {
  beforeEach(() => vi.unstubAllGlobals());
  afterEach(() => vi.unstubAllGlobals());

  it("devuelve data en el caso feliz", async () => {
    mockFetchOnce({ jsonBody: { data: { __typename: "RootQuery" } } });
    await expect(fetchGraphQL(QUERY)).resolves.toEqual({ __typename: "RootQuery" });
  });

  it("lanza kind=http con status cuando la respuesta no es OK", async () => {
    mockFetchOnce({ ok: false, status: 502, statusText: "Bad Gateway", textBody: "upstream" });
    const err = await fetchGraphQL(QUERY).catch((e) => e);
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("http");
    expect(err.status).toBe(502);
  });

  it("lanza kind=graphql con los errores originales", async () => {
    mockFetchOnce({
      jsonBody: { errors: [{ message: "Cannot query field X" }, { message: "Otro" }] },
    });
    const err = await fetchGraphQL(QUERY).catch((e) => e);
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("graphql");
    expect(err.errors).toHaveLength(2);
    expect(err.message).toContain("Cannot query field X");
  });

  it("lanza kind=empty si no hay data ni errors", async () => {
    mockFetchOnce({ jsonBody: {} });
    const err = await fetchGraphQL(QUERY).catch((e) => e);
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("empty");
  });

  it("lanza kind=network cuando fetch revienta", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("fetch failed")));
    const err = await fetchGraphQL(QUERY).catch((e) => e);
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("network");
  });
});
```

- [ ] **Step 2: Verificar que falla**

Run: `cd frontend && npx vitest run tests/unit/graphql-client.test.ts`
Expected: FAIL — `GraphQLClientError` no existe.

- [ ] **Step 3: Implementar**

En `frontend/src/lib/graphql-client.ts`:

1. Añade la clase justo después de las interfaces existentes (`GraphQLResponse<T>`):

```ts
/** Error tipado del cliente GraphQL: permite distinguir red / HTTP / GraphQL sin parsear mensajes. */
export class GraphQLClientError extends Error {
  readonly kind: "network" | "http" | "graphql" | "empty";
  readonly status?: number;
  readonly errors?: GraphQLErrorShape[];

  constructor(
    kind: "network" | "http" | "graphql" | "empty",
    message: string,
    extras: { status?: number; errors?: GraphQLErrorShape[]; cause?: unknown } = {},
  ) {
    super(message, extras.cause !== undefined ? { cause: extras.cause } : undefined);
    this.name = "GraphQLClientError";
    this.kind = kind;
    this.status = extras.status;
    this.errors = extras.errors;
  }
}
```

2. Envuelve el `fetch` para tipar el fallo de red. Sustituye la sentencia
`const response = await fetch(getEndpoint(), { … });` por:

```ts
  let response: Response;
  try {
    response = await fetch(getEndpoint(), {
      method: "POST",
      headers,
      body: JSON.stringify({ query, variables }),
      // Integración con la caché de Next.js (ISR / revalidación on-demand).
      next: {
        ...(typeof revalidate === "number" ? { revalidate } : {}),
        ...(tags && tags.length > 0 ? { tags } : {}),
      },
    });
  } catch (cause) {
    throw new GraphQLClientError("network", "No se pudo conectar con el endpoint GraphQL.", {
      cause,
    });
  }
```

3. Sustituye los tres `throw new Error(...)` restantes:

```ts
  if (!response.ok) {
    const text = await response.text().catch(() => "");
    throw new GraphQLClientError(
      "http",
      `Error HTTP de GraphQL: ${response.status} ${response.statusText}. ${text}`,
      { status: response.status },
    );
  }
```

```ts
  if (json.errors && json.errors.length > 0) {
    const message = json.errors.map((e) => e.message).join(" | ");
    throw new GraphQLClientError("graphql", `Error de GraphQL: ${message}`, {
      errors: json.errors,
    });
  }
```

```ts
  if (!json.data) {
    throw new GraphQLClientError("empty", "La respuesta de GraphQL no contiene datos.");
  }
```

- [ ] **Step 4: Verificar que pasa todo**

Run: `cd frontend && npx vitest run tests/unit/graphql-client.test.ts && npm run test && npm run type-check && npx next build`
Expected: PASS (5 tests nuevos) y suites existentes en verde; build exit 0.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/graphql-client.ts frontend/tests/unit/graphql-client.test.ts
git commit -m "feat: GraphQLClientError tipado (network/http/graphql/empty) en el cliente GraphQL"
```

### Task 9: Guía de desarrollo de plugins

Contexto: `backend/wp-content/mu-plugins/README.md` ya explica la convención de carga. Falta la
receta completa: extender el Schema del Control Center y llevar el dato hasta el contrato del
frontend. El plugin de envío (`woocommerce-shipping-config.php`) es el ejemplo canónico a citar.

**Files:**
- Create: `docs/PLUGIN_DEVELOPMENT.md`
- Modify: `AGENTS.md` (§11, añadir referencia)

**Interfaces:**
- Consumes: patrón existente (Schema/Storage/walkers; `README.md` de mu-plugins).
- Produces: guía que Task 11 (Banner Manager) seguirá como caso real.

- [ ] **Step 1: Crear la guía**

Create `docs/PLUGIN_DEVELOPMENT.md` con este contenido exacto:

```markdown
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

## 3. Leer la config desde PHP (lógica de negocio)

Patrón de `woocommerce-shipping-config.php` (ejemplo canónico):

​```php
$value = class_exists('\\HWE\\ControlCenter\\Storage')
    ? \HWE\ControlCenter\Storage::get(['mi_grupo', 'mi_campo'], $default)
    : $default;
​```

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
`docs/FRONTEND_CONNECT.md` Parte A y añade los tipos a `frontend/src/types/`.

## 5. Checklist antes de dar por terminado el plugin

- [ ] `php -l` limpio en todos los `.php` nuevos.
- [ ] Campos reflejados en: Schema.php ↔ instance.config.example.json ↔ types.ts ↔ defaults.ts.
- [ ] Ningún secreto con `public => true` ni con prefijo `NEXT_PUBLIC_`.
- [ ] Contrato documentado en `docs/FRONTEND_CONNECT.md` (Parte A).
- [ ] Test unitario del parseo/lógica frontend (si aplica).
- [ ] `npx next build` en verde (si tocaste frontend).
- [ ] Entrada en `CHANGELOG.md` (`[Sin publicar]`).
- [ ] Si el mu-plugin es nuevo: fila en la tabla de `backend/wp-content/mu-plugins/README.md`.
```

(Nota: los fences internos marcados con ​``` llevan un separador para anidar — al escribir el
archivo real usa fences de código normales.)

- [ ] **Step 2: Referenciar la guía en AGENTS.md**

En `AGENTS.md`, sección `## 11. Documentación`, en la lista de "Cliente/instalación", añade al
final de esa línea (tras `docs/ACCESIBILIDAD.md`):

```markdown
, `docs/PLUGIN_DEVELOPMENT.md` (desarrollar plugins del framework)
```

- [ ] **Step 3: Commit**

```bash
git add docs/PLUGIN_DEVELOPMENT.md AGENTS.md
git commit -m "docs: guía de desarrollo de plugins (mu-plugin + Schema del Control Center + contrato)"
```

### Task 10: Ejemplo mínimo de integración (consumidor externo del contrato)

Contexto: la única prueba viviente del contrato es el frontend heredado. Este ejemplo demuestra el
contrato desde fuera, sin Next.js: Node puro contra la config pública, GraphQL y el BFF.

**Files:**
- Create: `examples/minimal-consumer/index.mjs`
- Create: `examples/minimal-consumer/README.md`

**Interfaces:**
- Consumes: `/wp-json/hwe/v1/config`, `/graphql` (WPGraphQL), `/api/health` y `/api/csrf` del BFF.
- Produces: script ejecutable `node index.mjs` que imprime marca, 3 productos y el estado del BFF.

- [ ] **Step 1: Crear el script**

Create `examples/minimal-consumer/index.mjs`:

```js
// ============================================================================
//  Consumidor mínimo del contrato HWE (docs/FRONTEND_CONNECT.md) — Node ≥ 20.
//
//  Demuestra, sin ningún framework, las tres superficies estables:
//   1. Config pública dinámica  → GET  {WP}/wp-json/hwe/v1/config
//   2. Catálogo (lectura)       → POST {WP}/graphql        (WPGraphQL/WooGraphQL)
//   3. BFF                      → GET  {BFF}/api/health  +  GET {BFF}/api/csrf
//
//  Uso:  WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 node index.mjs
// ============================================================================

const WP = (process.env.WP_URL ?? "http://localhost:8080").replace(/\/$/, "");
const BFF = (process.env.BFF_URL ?? "http://localhost:3000").replace(/\/$/, "");

const PRODUCTS_QUERY = /* GraphQL */ `
  query LatestProducts($first: Int!) {
    products(first: $first) {
      nodes {
        id
        name
        ... on SimpleProduct { price }
        ... on VariableProduct { price }
      }
    }
  }
`;

async function main() {
  // 1. Config pública (marca, diseño, flags) ---------------------------------
  const configRes = await fetch(`${WP}/wp-json/hwe/v1/config`);
  if (!configRes.ok) throw new Error(`config: HTTP ${configRes.status}`);
  const config = await configRes.json();
  console.log(`✔ Marca: ${config.brand?.name} — ${config.brand?.tagline}`);
  console.log(`  Flags: reviews=${config.ecommerce?.reviews_enabled} wishlist=${config.ecommerce?.wishlist_enabled}`);

  // 2. Catálogo por GraphQL ---------------------------------------------------
  const gqlRes = await fetch(`${WP}/graphql`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ query: PRODUCTS_QUERY, variables: { first: 3 } }),
  });
  if (!gqlRes.ok) throw new Error(`graphql: HTTP ${gqlRes.status}`);
  const gql = await gqlRes.json();
  if (gql.errors) throw new Error(`graphql: ${gql.errors.map((e) => e.message).join(" | ")}`);
  for (const p of gql.data.products.nodes) {
    console.log(`✔ Producto: ${p.name} (${p.price ?? "sin precio"})`);
  }

  // 3. BFF: salud + token CSRF (primer paso de CUALQUIER escritura) -----------
  const health = await fetch(`${BFF}/api/health`).then((r) => r.json());
  console.log(`✔ BFF health: ${JSON.stringify(health.status ?? health)}`);

  const csrfRes = await fetch(`${BFF}/api/csrf`);
  const csrf = await csrfRes.json();
  console.log(`✔ CSRF token emitido: ${String(csrf.token ?? "").slice(0, 12)}…`);
  console.log("  (Las escrituras requieren reenviar este token en X-CSRF-Token junto a la");
  console.log("   cookie de la respuesta — flujo completo en docs/FRONTEND_CONNECT.md §A.2.)");
}

main().catch((err) => {
  console.error(`✘ ${err.message}`);
  console.error("  ¿Está levantada la pila? (docker compose up -d  +  frontend en :3000)");
  process.exit(1);
});
```

- [ ] **Step 2: Crear el README del ejemplo**

Create `examples/minimal-consumer/README.md`:

```markdown
# Consumidor mínimo del contrato HWE

Script Node (≥ 20, sin dependencias) que demuestra las tres superficies estables del
framework descritas en `docs/FRONTEND_CONNECT.md`: config pública, catálogo GraphQL y BFF
(health + CSRF). Sirve como *smoke test* del contrato y como punto de partida para integrar
cualquier frontend que no herede el de la plantilla.

## Uso

Con la pila levantada (`docker compose up -d` + frontend, o modo híbrido):

​```bash
WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 node index.mjs
​```

Salida esperada: la marca configurada, hasta 3 productos y el estado del BFF con un token
CSRF emitido. Cualquier `✘` indica qué superficie del contrato no responde.
```

(Mismo aviso de fences anidados que en tareas anteriores.)

- [ ] **Step 3: Verificar sintaxis (sin pila)**

Run: `node --check examples/minimal-consumer/index.mjs && echo OK`
Expected: `OK`.

- [ ] **Step 4: (Solo si la pila está levantada) ejecutar de verdad**

Run: `WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 node examples/minimal-consumer/index.mjs`
Expected: líneas `✔` para config, productos y BFF. Si la pila no está levantada, decláralo como no verificado en vivo.

- [ ] **Step 5: Anotar CHANGELOG y commit**

En `CHANGELOG.md`, bajo `## [Sin publicar]` → `### Añadido`, agrega:

```markdown
- **Errores tipados** en el cliente GraphQL (`GraphQLClientError`: network/http/graphql/empty).
- **Guía de desarrollo de plugins** (`docs/PLUGIN_DEVELOPMENT.md`).
- **Ejemplo mínimo de integración** (`examples/minimal-consumer/`): consumidor Node del
  contrato (config pública + GraphQL + BFF) sin framework.
```

```bash
git add examples/minimal-consumer CHANGELOG.md
git commit -m "feat: ejemplo mínimo de integración — consumidor Node del contrato HWE"
```

---

# FASE 3 — Banner Manager

*Objetivo: banners de portada gestionables desde `wp-admin` (roadmap "Plugins base" #1), siguiendo la receta de `docs/PLUGIN_DEVELOPMENT.md` (Task 9). El dato vive en el Control Center (patrón FAQ: textarea con formato por líneas — no requiere tipos de campo nuevos); el carrusel heredado `HeroCarousel`, hoy huérfano, pasa a ser la implementación de referencia.*

### Task 11: Sección `banners` en el Control Center (backend)

**Files:**
- Modify: `backend/wp-content/mu-plugins/hwe-control-center/Schema.php`
- Modify: `backend/scripts/instance.config.example.json`

**Interfaces:**
- Consumes: infraestructura Schema/walkers existente (cero código nuevo aparte de la definición).
- Produces: `banners.enabled: boolean`, `banners.interval_ms: string`, `banners.slides: string` en `/wp-json/hwe/v1/config` (todos `public`).

- [ ] **Step 1: Añadir el grupo al Schema**

En `backend/wp-content/mu-plugins/hwe-control-center/Schema.php`, inmediatamente **después** del
grupo `'ecommerce' => [ ... ],` (y antes de `'payments'`), añade:

```php
            /* ------------------------------------------------------------------ */
            /* BANNERS (hero de portada)                                           */
            /* ------------------------------------------------------------------ */
            'banners' => [
                'type'     => 'group',
                'label'    => 'Banners',
                'children' => [
                    'enabled' => [
                        'type'    => 'boolean',
                        'label'   => 'Mostrar carrusel de banners en la portada',
                        'default' => false,
                        'public'  => true,
                    ],
                    'interval_ms' => [
                        'type'        => 'text',
                        'label'       => 'Intervalo de rotación (ms)',
                        'default'     => '6000',
                        'public'      => true,
                        'description' => 'Milisegundos entre transiciones automáticas del carrusel.',
                    ],
                    'slides' => [
                        'type'        => 'textarea',
                        'label'       => 'Slides',
                        'public'      => true,
                        'description' => 'Un banner por línea, campos separados por "|": imagen | título | subtítulo | texto del botón | URL del botón | badge. Solo imagen y título son obligatorios; deja el resto vacío si no aplica. Ej.: https://…/verano.jpg | Rebajas de verano | Hasta -50% | Ver ofertas | /products | Nuevo',
                    ],
                ],
            ],
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l backend/wp-content/mu-plugins/hwe-control-center/Schema.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Reflejar en la plantilla de instancia**

En `backend/scripts/instance.config.example.json`, tras el objeto `"ecommerce"`, añade:

```json
  "banners": {
    "enabled": false,
    "interval_ms": "6000",
    "slides": ""
  },
```

Run: `python3 -m json.tool backend/scripts/instance.config.example.json > /dev/null && echo OK`
Expected: `OK`.

- [ ] **Step 4: Commit**

```bash
git add backend/wp-content/mu-plugins/hwe-control-center/Schema.php backend/scripts/instance.config.example.json
git commit -m "feat: Banner Manager — sección banners (enabled/interval/slides) en el Control Center"
```

### Task 12: Parser de banners en el núcleo del frontend

**Files:**
- Modify: `frontend/src/lib/config/types.ts`
- Modify: `frontend/src/lib/config/defaults.ts`
- Create: `frontend/src/lib/config/banners.ts`
- Test: `frontend/tests/unit/banners.test.ts`

**Interfaces:**
- Consumes: `SiteConfig` ampliado.
- Produces:
  ```ts
  interface BannerSlide {
    id: number;
    image: string;
    title: string;
    subtitle?: string;
    cta?: string;
    ctaHref?: string;
    badge?: string;
  }
  function parseBanners(raw: string): BannerSlide[];
  ```
  desde `@/lib/config/banners`. `BannerSlide` es **estructuralmente compatible** con la prop
  `HeroSlide` de `components/layout/hero-carousel.tsx` — a propósito, para no importar tipos de
  `components/**` desde `lib/**` (frontera núcleo/instancia, ver Task 14).

- [ ] **Step 1: Ampliar tipos y defaults**

En `frontend/src/lib/config/types.ts`, tras el bloque `ecommerce: { ... };`, añade:

```ts
  banners: {
    enabled: boolean;
    /** Milisegundos entre transiciones del carrusel. */
    interval_ms: string;
    /** Líneas "imagen | título | subtítulo | cta | ctaHref | badge" (parsear con parseBanners). */
    slides: string;
  };
```

En `frontend/src/lib/config/defaults.ts`, tras el bloque `ecommerce: { ... },`, añade:

```ts
  banners: {
    enabled: false,
    interval_ms: "6000",
    slides: "",
  },
```

- [ ] **Step 2: Escribir el test (falla primero)**

Create `frontend/tests/unit/banners.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { parseBanners } from "@/lib/config/banners";

describe("parseBanners", () => {
  it("parsea una línea completa", () => {
    expect(
      parseBanners("https://x/img.jpg | Rebajas | Hasta -50% | Ver ofertas | /products | Nuevo"),
    ).toEqual([
      {
        id: 0,
        image: "https://x/img.jpg",
        title: "Rebajas",
        subtitle: "Hasta -50%",
        cta: "Ver ofertas",
        ctaHref: "/products",
        badge: "Nuevo",
      },
    ]);
  });

  it("omite los campos opcionales vacíos", () => {
    expect(parseBanners("https://x/a.jpg | Solo título")).toEqual([
      { id: 0, image: "https://x/a.jpg", title: "Solo título" },
    ]);
    expect(parseBanners("https://x/a.jpg | Título |  | CTA | /go")).toEqual([
      { id: 0, image: "https://x/a.jpg", title: "Título", cta: "CTA", ctaHref: "/go" },
    ]);
  });

  it("descarta líneas sin imagen o sin título y líneas en blanco", () => {
    const raw = "\n | Sin imagen \nhttps://x/b.jpg |  \n\nhttps://x/c.jpg | Válido\n";
    // id = índice de la línea original ("https://x/c.jpg | Válido" es la línea 4, contando las inválidas)
    expect(parseBanners(raw)).toEqual([{ id: 4, image: "https://x/c.jpg", title: "Válido" }]);
  });

  it("devuelve [] para entrada vacía", () => {
    expect(parseBanners("")).toEqual([]);
  });
});
```

Nota: el `id` es el índice de línea original (estable aunque haya líneas inválidas intercaladas).

- [ ] **Step 3: Verificar que falla**

Run: `cd frontend && npx vitest run tests/unit/banners.test.ts`
Expected: FAIL — `@/lib/config/banners` no existe.

- [ ] **Step 4: Implementar el parser**

Create `frontend/src/lib/config/banners.ts`:

```ts
// ============================================================================
//  Banner Manager — parseo de `config.banners.slides` (HWE Control Center).
//  Formato: una línea por banner, campos separados por "|":
//    imagen | título | subtítulo | texto CTA | URL CTA | badge
//  Solo imagen y título son obligatorios. Patrón hermano de lib/seo/faq.ts.
//
//  BannerSlide es estructuralmente compatible con la prop HeroSlide del
//  carrusel heredado — definido aquí (y no importado de components/**) para
//  respetar la frontera núcleo/instancia.
// ============================================================================

export interface BannerSlide {
  id: number;
  image: string;
  title: string;
  subtitle?: string;
  cta?: string;
  ctaHref?: string;
  badge?: string;
}

export function parseBanners(raw: string): BannerSlide[] {
  return raw
    .split(/\r?\n/)
    .map((line, index) => {
      const [image = "", title = "", subtitle = "", cta = "", ctaHref = "", badge = ""] = line
        .split("|")
        .map((part) => part.trim());

      if (!image || !title) return null;

      return {
        id: index,
        image,
        title,
        ...(subtitle ? { subtitle } : {}),
        ...(cta ? { cta } : {}),
        ...(ctaHref ? { ctaHref } : {}),
        ...(badge ? { badge } : {}),
      };
    })
    .filter((slide): slide is BannerSlide => slide !== null);
}
```

- [ ] **Step 5: Verificar que pasa**

Run: `cd frontend && npx vitest run tests/unit/banners.test.ts && npm run type-check`
Expected: PASS (4 tests) y tipos en verde.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/config/types.ts frontend/src/lib/config/defaults.ts frontend/src/lib/config/banners.ts frontend/tests/unit/banners.test.ts
git commit -m "feat: Banner Manager — tipos, defaults y parseBanners en el núcleo del frontend"
```

### Task 13: Conectar el carrusel heredado (referencia) + contrato + CHANGELOG

**Files:**
- Modify: `frontend/src/app/[locale]/page.tsx`
- Modify: `docs/FRONTEND_CONNECT.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: `parseBanners`/`BannerSlide` (Task 12); `HeroCarousel` + `HeroSlide` de `@/components/layout/hero-carousel` (ya existe, hoy sin uso); `config.banners.*`.
- Produces: la home heredada muestra el carrusel cuando `banners.enabled` y hay slides válidos (patrón de gating por flag, como `ecommerce.*_enabled`).

- [ ] **Step 1: Renderizar el carrusel en la home**

En `frontend/src/app/[locale]/page.tsx`:

1. Añade los imports:

```tsx
import { parseBanners } from "@/lib/config/banners";
import { HeroCarousel } from "@/components/layout/hero-carousel";
```

2. Tras `const faqItems = parseFaq(config.geo.faq);` añade:

```tsx
  const bannerSlides = config.banners.enabled ? parseBanners(config.banners.slides) : [];
```

3. En el JSX, inmediatamente **antes** de la `<section className="mb-12">` del título, añade:

```tsx
      {bannerSlides.length > 0 && (
        <HeroCarousel
          slides={bannerSlides}
          interval={Number(config.banners.interval_ms) || 6000}
          className="mb-12"
        />
      )}
```

- [ ] **Step 2: Verificar con build**

Run: `cd frontend && npm run test && npm run type-check && npm run lint && npx next build`
Expected: todo exit 0. `HeroCarousel` deja de ser código huérfano.

- [ ] **Step 3: Documentar en el contrato**

En `docs/FRONTEND_CONNECT.md`, sección `## A.3 Config pública dinámica`, añade junto al resto de
campos documentados:

```markdown
- `banners.enabled` / `banners.interval_ms` / `banners.slides` — Banner Manager. `slides` es un
  textarea con una línea por banner: `imagen | título | subtítulo | texto CTA | URL CTA | badge`
  (solo imagen y título obligatorios). Parser de referencia: `lib/config/banners.ts`
  (`parseBanners`); consumidor de referencia: `HeroCarousel` en la home heredada, gateado por
  `banners.enabled`.
```

- [ ] **Step 4: CHANGELOG y commit**

En `CHANGELOG.md`, bajo `## [Sin publicar]` → `### Añadido`:

```markdown
- **Banner Manager**: banners de portada administrables desde HWE Config (sección Banners),
  expuestos en la config pública, con parser en el núcleo (`parseBanners`) y el carrusel
  heredado (`HeroCarousel`) conectado como implementación de referencia.
```

```bash
git add "frontend/src/app/[locale]/page.tsx" docs/FRONTEND_CONNECT.md CHANGELOG.md
git commit -m "feat: Banner Manager — carrusel de referencia en la home gateado por banners.enabled"
```

---

# FASE 4 — Frontera ejecutable, estrategia de actualización y release v1.0.0

*Objetivo: hacer cumplir mecánicamente la separación núcleo/instancia, dar a las instancias un camino de actualización documentado, y publicar v1.0.0 validada.*

### Task 14: Frontera núcleo/instancia como regla de ESLint

Contexto: la decisión de arquitectura (§1.1 de AGENTS.md) hoy se cumple por disciplina. Esta regla
hace fallar el lint si el núcleo (`lib/`, `app/api/`, `proxy.ts`) importa código de instancia
(`components/**`, salvo `ui/`).

**Files:**
- Modify: `frontend/eslint.config.mjs`

**Interfaces:**
- Consumes: nada.
- Produces: `npm run lint` falla ante imports núcleo→instancia (protege también a Tasks 5/12, que se diseñaron para no violarla).

- [ ] **Step 1: Añadir el bloque de regla**

En `frontend/eslint.config.mjs`, añade al array `eslintConfig`, después del bloque de `ignores`:

```js
  // ── Frontera núcleo/instancia (AGENTS.md §1.1) ─────────────────────────────
  // El núcleo del framework (lib/, app/api/, proxy) NO puede depender de código
  // de instancia (components/**, salvo las primitivas ui/). Los `import type`
  // también cuentan: el contrato de tipos del núcleo debe vivir en el núcleo.
  {
    files: ["src/lib/**/*.{ts,tsx}", "src/app/api/**/*.{ts,tsx}", "src/proxy.ts"],
    rules: {
      "no-restricted-imports": [
        "error",
        {
          patterns: [
            {
              group: ["@/components/**", "!@/components/ui", "!@/components/ui/**"],
              message:
                "El núcleo del framework no puede importar código de instancia (components/**, salvo ui/). Mueve el tipo/lógica a lib/ o invierte la dependencia.",
            },
          ],
        },
      ],
    },
  },
```

- [ ] **Step 2: Verificar que el lint pasa (el código actual no viola la frontera)**

Run: `cd frontend && npm run lint`
Expected: exit 0. Si aparece alguna violación real: NO la silencies — mueve el tipo/función al
núcleo (`lib/`) como se hizo con `BannerSlide` (Task 12) y reporta el caso en el resumen.

- [ ] **Step 3: Probar que la regla muerde (verificación negativa, luego revertir)**

Añade temporalmente a `frontend/src/lib/format.ts` la línea
`import "@/components/layout/site-header";` y corre `npm run lint`.
Expected: error `no-restricted-imports` con el mensaje de la frontera.
**Elimina la línea** y confirma que `npm run lint` vuelve a exit 0.

- [ ] **Step 4: Commit**

```bash
git add frontend/eslint.config.mjs
git commit -m "feat: frontera núcleo/instancia ejecutable — lint prohíbe imports lib/api → components (salvo ui/)"
```

### Task 15: Estrategia de actualización de instancias (UPGRADE.md)

Contexto: el hallazgo más grave de la auditoría — no hay camino definido para que un fix del
núcleo llegue a una instancia ya clonada. Estrategia elegida (barata y suficiente hasta que haya
2-3 instancias reales): **el repo del framework como remote `framework`** + merge dirigido, con la
lista explícita de rutas de núcleo.

**Files:**
- Create: `docs/UPGRADE.md`
- Modify: `README.md` (enlace en la sección de documentación)
- Modify: `docs/CREATE_INSTANCE.md` (paso final: configurar el remote)

**Interfaces:**
- Consumes: tags de release (v0.9.0 de Task 2; v1.0.0 de Task 16).
- Produces: procedimiento de actualización documentado y enlazado.

- [ ] **Step 1: Crear docs/UPGRADE.md**

Create `docs/UPGRADE.md` con este contenido exacto:

```markdown
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

​```bash
git remote add framework <URL-del-repo-del-framework>
git fetch framework --tags
​```

## 3. Procedimiento de actualización

1. Lee `CHANGELOG.md` del framework entre tu versión y la nueva (los tags `vX.Y.Z` delimitan).
   Las entradas marcadas **BREAKING** requieren acción manual descrita en la propia entrada.
2. Crea una rama: `git checkout -b chore/framework-vX.Y.Z`.
3. Trae los cambios: `git merge vX.Y.Z` (merge del tag, no de `framework/main`: actualiza
   siempre a una release, nunca a trabajo en curso).
4. Resuelve conflictos con la regla de §1: rutas de núcleo → versión del framework; rutas de
   instancia → versión local; zona gris → a mano.
5. Verifica desde `frontend/`: `npm ci && npm run test && npm run lint && npx next build`.
6. Prueba de humo con la pila levantada (login, carrito, checkout `noop`) y despliega como
   cualquier otro cambio (`docs/DEPLOYMENT.md`).

## 4. Reglas para el framework (para que esto siga funcionando)

- Todo cambio de núcleo se publica como release taggeada + entrada de CHANGELOG.
- Un cambio que exija tocar código de instancia se marca **BREAKING** en el CHANGELOG con su
  instrucción de migración.
- El núcleo no importa código de instancia (regla de lint en `eslint.config.mjs`) — esto es lo
  que hace que los merges de arriba sean limpios.
```

- [ ] **Step 2: Enlazar desde README y CREATE_INSTANCE**

En `README.md`, en la sección donde se lista la documentación de `docs/`, añade una línea:

```markdown
- [`docs/UPGRADE.md`](docs/UPGRADE.md) — actualizar una instancia con los cambios del framework.
```

En `docs/CREATE_INSTANCE.md`, al final del procedimiento de creación, añade:

```markdown
## Último paso: prepara las actualizaciones futuras

Configura el repo del framework como remote para poder traer sus releases:

​```bash
git remote add framework <URL-del-repo-del-framework>
git fetch framework --tags
​```

El procedimiento de actualización completo está en [`docs/UPGRADE.md`](UPGRADE.md).
```

- [ ] **Step 3: Commit**

```bash
git add docs/UPGRADE.md README.md docs/CREATE_INSTANCE.md
git commit -m "docs: estrategia de actualización de instancias (UPGRADE.md, remote framework + merge de tags)"
```

### Task 16: Sincronizar AGENTS.md, validar todo y publicar v1.0.0

**Files:**
- Modify: `AGENTS.md` (§3 mapa, §5 estado, §6.7)
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: todas las tareas anteriores commiteadas.
- Produces: tag `v1.0.0`.

- [ ] **Step 1: Actualizar AGENTS.md**

1. En §3 (mapa del repo), en el árbol de `frontend/src/lib/`, la lista de dominios entre
   paréntesis del párrafo final ("está organizado por dominio…") debe incluir `navigation/`.
2. En §5, sustituye la línea `> Actualizado: **2026-06-18**. Estados realistas…` por:

```markdown
> Esta tabla se congela en cada release; el estado vivo entre releases está en
> `CHANGELOG.md` (`[Sin publicar]`). Actualizada por última vez: **v1.0.0**.
```

3. En §5, añade a la tabla de estado (antes de la fila de "Calidad / CI/CD"):

```markdown
| Menús + logo + banners administrables desde WP (contrato §A.6 / config pública) | ✅ |
| Frontera núcleo/instancia (regla de lint) + estrategia de actualización (UPGRADE.md) | ✅ |
```

4. En §6.7, tras el punto de los flags `config.ecommerce.*_enabled`, añade:

```markdown
- **Menús**: se gestionan en WP (Apariencia → Menús) y se leen con `getMenu(area, locale)`
  (`lib/navigation/menu.ts`, fail-soft a `null` → fallback local del componente). **Banners**:
  `config.banners.*` + `parseBanners` (`lib/config/banners.ts`), gateados por `banners.enabled`.
  **Logo**: `config.brand.logo` (vacío = nombre del sitio como texto).
```

- [ ] **Step 2: Cerrar el CHANGELOG como 1.0.0**

En `CHANGELOG.md`, convierte la sección `## [Sin publicar]` acumulada en:

```markdown
## [Sin publicar]

## [1.0.0] — <fecha de hoy ISO>
```

con todo el contenido acumulado (Fases 1–4) bajo `[1.0.0]`, y añade a esa sección:

```markdown
### Añadido
- Regla de lint que hace ejecutable la frontera núcleo/instancia (AGENTS.md §1.1).
- `docs/UPGRADE.md`: procedimiento de actualización de instancias (remote + merge de tags).
```

(Fusiona con los `### Añadido` ya acumulados — un solo bloque `### Añadido` por release.)

- [ ] **Step 3: Validación completa**

```bash
cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem/frontend"
npm run lint && npm run type-check && npm run test && npx next build
node --check ../examples/minimal-consumer/index.mjs
php -l ../backend/wp-content/mu-plugins/hwe-control-center/Schema.php
php -l ../backend/wp-content/themes/hwe-headless-base/functions.php
bash -n ../backend/scripts/setup.sh
```
Expected: todo exit 0.

- [ ] **Step 4: (Muy recomendado, requiere pila) validación en vivo**

Si es posible, levanta la pila (`docker compose up -d` + `docker compose run --rm wpcli` en frío,
o modo híbrido) y verifica:

1. `wp-admin → HWE Config` muestra las secciones **Banners** y el campo **Logo**.
2. `wp-admin → Apariencia → Menús` permite crear/asignar un menú a "Navegación principal"; el
   header del frontend lo refleja (tras ≤ 5 min o reinicio del dev server).
3. `curl http://localhost:8080/wp-json/hwe/v1/config | python3 -m json.tool` incluye
   `brand.logo` y `banners.*`.
4. `node examples/minimal-consumer/index.mjs` termina con `✔` en las tres superficies.

Si no levantas la pila, documenta en el resumen final EXACTAMENTE qué quedó sin verificar en vivo.

- [ ] **Step 5: Commit y tag**

```bash
cd "/home/sergi/Documentos/Proyectos/Headless Web Ecosystem"
git add AGENTS.md CHANGELOG.md
git commit -m "chore: release v1.0.0 — Control Center completo, SDK formalizado, Banner Manager, frontera y UPGRADE"
git tag -a v1.0.0 -m "HWE framework v1.0.0"
git tag
```
Expected: `v0.9.0` y `v1.0.0`.

---

## Criterio de éxito del plan (v1.0 funcional)

- [ ] Logo, menús (con selección de menú activo nativa de WP), footer y banners administrables desde `wp-admin` sin tocar código.
- [ ] Contrato documentado y consumible desde fuera (`FRONTEND_CONNECT.md` A.1–A.6 + `examples/minimal-consumer` en verde contra la pila).
- [ ] `docs/PLUGIN_DEVELOPMENT.md` permite añadir un campo de config extremo a extremo siguiendo solo la guía.
- [ ] `npm run lint` protege la frontera núcleo/instancia.
- [ ] `docs/UPGRADE.md` + tags `v0.9.0`/`v1.0.0` dan a las instancias un camino de actualización.
- [ ] CI en verde y `npx next build` limpio.
