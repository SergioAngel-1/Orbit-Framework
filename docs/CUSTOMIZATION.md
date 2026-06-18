# Personalización (White-label)

La plantilla está pensada para rebrandear **sin tocar la lógica**. Todo lo de marca
está centralizado.

## 1. Marca y datos del sitio

Edita `frontend/src/config/site.ts`:

```ts
export const siteConfig = {
  name: "TuMarca",
  tagline: "Tu eslogan",
  description: "…",
  url: process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000",
  social: { twitter: "@tumarca" },
  legal: { email: "hola@tumarca.com", company: "Tu Empresa S.L." },
  defaultOgImage: "/og-default.jpg",
};
```

El **nombre visible** (header, títulos, footer) se toma de los mensajes i18n:
edita `site.name`, `site.tagline` y `site.footer` en
`frontend/src/i18n/messages/es.json` y `en.json` (mantenlos paralelos).

## 2. Colores y tipografía (Tailwind v4, CSS-first)

No hay `tailwind.config`. El tema vive en `frontend/src/app/globals.css`:

```css
@theme {
  --color-brand: #2563eb;        /* cámbialo por tu color */
  --color-brand-dark: #1e40af;
  --color-brand-light: #3b82f6;
  --font-sans: var(--font-inter), system-ui, sans-serif;
}
```

Cada variable `--color-*` genera utilidades automáticamente (`bg-brand`,
`text-brand`, etc.). Para cambiar la tipografía, sustituye la fuente importada en
`frontend/src/app/[locale]/layout.tsx` (`next/font`).

## 3. Logo

Reemplaza el texto del header por tu logo en `layout.tsx` (el enlace de marca).
Coloca los assets en `frontend/public/` y usa `next/image`. Recuerda actualizar
`public/og-default.jpg` (imagen Open Graph por defecto).

## 4. Idiomas

- Locales en `frontend/src/i18n/routing.ts` (`es` canónico, `en` bajo `/en`).
- Textos en `messages/es.json` y `messages/en.json` — **toda clave debe existir en
  ambos**. Para añadir un idioma: amplía `routing.locales`, crea `messages/<loc>.json`
  y añade su entrada en `sitemap.ts` (hreflang).

## 5. Páginas legales

El contenido de privacidad/cookies/términos/devoluciones está en el namespace
`legal` de los mensajes i18n y se renderiza en `/[locale]/legal/[slug]`. Edítalo
para tu jurisdicción (son plantillas de ejemplo).

## 6. Navegación locale-aware

Usa **siempre** `Link`/`redirect`/`useRouter` de `@/i18n/navigation` (no de
`next/*`), o se pierde el idioma al navegar.

## Checklist de rebranding

- [ ] `config/site.ts` con tu marca, social y datos legales.
- [ ] `site.name` / `site.footer` en `es.json` y `en.json`.
- [ ] Colores `--color-brand*` en `globals.css`.
- [ ] Logo + `public/og-default.jpg`.
- [ ] Páginas legales adaptadas.
- [ ] `NEXT_PUBLIC_SITE_URL` y dominios en `.env`.
