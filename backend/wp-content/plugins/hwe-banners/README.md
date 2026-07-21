# HWE Banners

Plugin de banners administrables para instancias del **Headless Web Ecosystem**.
Genérico y reutilizable: no contiene lógica de negocio de ninguna instancia.

## Qué hace

- Registra el CPT `hwe_banner` (menú **Banners** en wp-admin).
- Cada banner tiene una **posición** (placement), un **intervalo** de carrusel y una
  lista de **slides** ordenables (imagen desktop/móvil, título, subtítulo, CTA, badge,
  enlace) con **overrides por idioma**.
- Expone los datos en `GET /wp-json/hwe-banners/v1/banners[/{placement}]?lang=<loc>`.
- Al guardar/borrar un banner, dispara la revalidación ISR del frontend (tag `banners`)
  reutilizando `HWE_REVALIDATION_SECRET` + `HEADLESS_FRONTEND_URL`.

Desde el **HWE Control Center** solo se activa/desactiva el renderizado
(`config.banners.enabled`); la autoría vive aquí.

## Extensibilidad (hooks)

- `hwe_banners_placements` (filter): mapa `slug => etiqueta` de posiciones.
- `hwe_banners_secondary_locales` (filter): locales con overrides (default `['en']`).
- `hwe_banners_slide_visible` (filter): `bool` — oculta un slide (p. ej. por membresía).
- `hwe_banners_slide` (filter): muta el slide serializado antes de servirlo.

## Frontend

El lector core está en `frontend/src/lib/banners/` (`getBannerPlacement(placement, locale)`).
