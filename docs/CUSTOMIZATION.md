# Personalización (White-label)

> Nota de arquitectura: todo lo de esta guía vive en la capa de UI/vistas del frontend, que es
> **responsabilidad de cada instancia**, no del framework (ver `AGENTS.md §1.1`). Lo que sí es
> dinámico y centralizado (sin tocar código) es la config de marca del HWE Control Center —
> empieza siempre por ahí.

## 1. Marca y datos del sitio (dinámico, sin tocar código)

La fuente de verdad es **`wp-admin → HWE Config`** (o `wp hwe setup <archivo.json>` para
seedearla de una vez — ver `docs/CREATE_INSTANCE.md`), no un archivo estático del frontend.
Ahí se define: nombre, tagline, descripción, URL, idioma por defecto, redes sociales, datos
legales (razón social/NIF/email/dirección), y los flags de funcionalidad opcional
(reseñas/wishlist/cupones/búsqueda). El frontend lo lee vía `getSiteConfig()`
(`frontend/src/lib/config/index.ts`) — no hay un `siteConfig` estático que editar; si tu
componente necesita un dato de marca, sale de ahí (ver `docs/FRONTEND_CONNECT.md §A.3`).

El **nombre visible en la UI que no viene de la config** (labels de navegación, textos fijos
del header/footer heredado) se toma de los mensajes i18n: `frontend/src/i18n/messages/es.json`
y `en.json` (mantenlos paralelos).

## 2. Colores y tipografía (dinámico, sin tocar código)

También en **`wp-admin → HWE Config → Diseño`**: 9 colores + 2 tipografías por instancia.
Next.js los aplica en runtime como variables CSS (`--color-brand`, `--color-secondary`,
`--color-accent`, `--color-surface`, `--font-sans`, `--font-heading`…) — no hay que tocar
`globals.css` ni un `tailwind.config` para cambiar la paleta. Detalle del mapeo campo →
variable: `frontend/src/lib/config/tokens.ts`; contrato completo en
`docs/FRONTEND_CONNECT.md §A.4`.

Si necesitas una utilidad Tailwind nueva que no exista (p. ej. una escala de grises propia),
ahí sí edita el bloque `@theme` de `frontend/src/app/globals.css` — pero eso es código de tu
instancia, no algo que reconfigures desde `wp-admin`.

## 3. Logo y menús

- **Logo**: súbelo a la medioteca de WordPress y pega su URL en `wp-admin → HWE Config →
  Identidad de marca → Logo (URL)` (`brand.logo`). El header heredado lo renderiza
  automáticamente; vacío = nombre del sitio como texto. `brand.og_image` sigue siendo la
  imagen Open Graph y `seo.organization_logo` alimenta el JSON-LD `Organization`.
- **Menús**: se gestionan en `wp-admin → Apariencia → Menús` asignando un menú a las
  locations `primary`/`footer` (+ variantes `_en`). Contrato y reglas de contenido en
  `docs/FRONTEND_CONNECT.md §A.6`. Sin menú asignado, el frontend heredado usa su
  navegación local por defecto (`NAV_ITEMS` + i18n).

## 4. Idiomas

- Locales en `frontend/src/i18n/routing.ts` (`es` canónico, `en` bajo `/en`).
- Textos en `messages/es.json` y `messages/en.json` — **toda clave debe existir en
  ambos**. Para añadir un idioma: amplía `routing.locales`, crea `messages/<loc>.json`
  y añade su entrada en `sitemap.ts` (hreflang).

## 5. Páginas legales

El contenido de privacidad/cookies/términos/devoluciones está en el namespace
`legal` de los mensajes i18n y se renderiza en `/[locale]/legal/[slug]`. Edítalo
para tu jurisdicción (son plantillas de ejemplo) — con asesoría legal antes de publicar.

## 6. Navegación locale-aware

Usa **siempre** `Link`/`redirect`/`useRouter` de `@/i18n/navigation` (no de
`next/*`), o se pierde el idioma al navegar.

## 7. Vistas y componentes (home, catálogo, marketing…)

Esto ya no es "personalización ligera" — es construir el frontend de la instancia. Sigue
`docs/FRONTEND_BUILD.md` (entrevista de negocio → plan de vistas) y usa
`docs/FRONTEND_CONNECT.md` Parte B como inventario de lo que trae el repo heredado.

## Checklist de rebranding

- [ ] `wp-admin → HWE Config` (o `instance.config.json` + `wp hwe setup`) con marca, social,
      legal, diseño y flags de funcionalidad.
- [ ] Textos fijos de UI (no cubiertos por la config) en `es.json`/`en.json`.
- [ ] Logo + `public/og-default.jpg` (o `brand.og_image` desde `wp-admin`).
- [ ] Páginas legales adaptadas.
- [ ] `NEXT_PUBLIC_SITE_URL` y dominios en `.env`.
- [ ] Vistas de marketing/catálogo (`docs/FRONTEND_BUILD.md`) — home y `/products` en
      particular, ver `docs/FRONTEND_CONNECT.md §B.1`.
