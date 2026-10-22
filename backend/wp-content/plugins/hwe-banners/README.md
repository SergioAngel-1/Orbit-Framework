# HWE Banners (módulo `banners`)

Plugin de banners administrables para instancias de **HWE Orbit**. Genérico y
reutilizable: no contiene lógica de negocio de ninguna instancia.

## Qué hace

- Registra el CPT `hwe_banner` (menú **Banners** en wp-admin).
- **Flujo en dos pasos:** al crear un banner se elige primero su **tipo** (`carousel` · `band` ·
  `allies` · `page_hero` · `landing`) y se guarda; el tipo queda fijado. En el segundo paso se
  elige la **locación** (página/superficie), el intervalo si aplica y los slides. El admin muestra
  una **guía de medidas** sugeridas por tipo y plantilla (`hwe_banners_size_guide`), una página de
  **Ayuda** con todas y pistas de longitud por campo (`hwe_banners_field_hints`).
- **Unicidad:** solo se sirve **un** banner publicado por celda `(tipo, locación)` (gana el más
  recientemente modificado). El admin avisa si hay duplicados.
- Cada slide tiene imagen desktop/móvil, textos, CTAs, enlace, orden (si el tipo admite varios
  slides), **plantilla**, **visual decorativo** (SVG) y **traducciones** a cada idioma secundario
  del sitio (textos e imágenes; las URL son una sola ruta interna por slide, que el frontend
  localiza).
- **Un slide se descarta solo si no tiene nada que pintar** (sin imagen, sin visual decorativo y
  sin una línea de texto): un slide con titular y botones se sirve aunque aún no tenga foto. Al
  guardar, el admin avisa de cuántos descartó.
- Expone los datos en:
  - `GET /wp-json/hwe-banners/v1/banners?location=<loc>&lang=<loc>`
  - `GET /wp-json/hwe-banners/v1/banners/<type>?location=<loc>&lang=<loc>`
  (`location` omiso → la primera declarada, `home`; un `type` inexistente → 404).
- Al guardar o borrar, revalida el tag ISR `banners` del frontend (por el núcleo del Control
  Center) y purga su caché local.

Desde el **HWE Control Center** solo se activa/desactiva el renderizado (módulo `banners`); la
autoría vive aquí.

## Idiomas

Los idiomas de las traducciones son los **secundarios del sitio** (`i18n.locales` del Control
Center, vía el mu-plugin `hwe-content-i18n`): un bloque «Overrides idioma» por cada idioma en cada slide.
Con un solo idioma no hay bloques. Sustituye al filtro `hwe_banners_secondary_locales` de la versión
anterior (un único idioma secundario, `en`).

## Extensibilidad (hooks)

- `hwe_banners_banner_types` — tipos de banner (rol). Cada uno: `label`, `description`, `types`
  (plantillas de slide), `max_slides`, `interval`, `locations` (slugs admitidos).
- `hwe_banners_locations` — catálogo `slug => etiqueta` de locaciones (por defecto, solo `home`).
- `hwe_banners_slide_types` — plantillas de slide (`fields` visibles en el formulario; por
  defecto, solo `default`).
- `hwe_banners_size_guide` — medidas sugeridas por tipo (tablas del admin).
- `hwe_banners_field_hints` — guías de longitud de los campos de texto.
- `hwe_banners_decor_presets` — visuales decorativos de plantilla (`clave => etiqueta`) que ofrece
  el campo «Visual decorativo de plantilla» cuando el visual lateral es «por defecto». El plugin
  solo guarda y sirve la CLAVE (`decorPreset` en el JSON): el archivo vive en el frontend
  (`InstanceRegistry.decorPresets`), que la resuelve. Sin filtro, o con un solo preset, el campo no
  se pinta. El PRIMERO de la lista es el respaldo de los slides que no traen clave.
  **Antes `hwe_banners_mandala_presets`** (ver `docs/UPGRADE.md`).
- `hwe_banners_slide_visible` / `hwe_banners_slide` — filtros al serializar.

## Slides guardados antes de v2

El visual decorativo se guardaba como `mandala_mode` / `mandala_preset` / `mandala_svg`; ahora es
`decor_mode` / `decor_preset` / `decor_svg` (y `decorMode` / `decorPreset` / `decorSvg` en el
JSON). `LegacySlide` lee las claves viejas sin tocar la base de datos: no hay migración de datos.

## Frontend

Lector en `frontend/src/lib/banners/`:

- `getBanners(locale, location = "home", { enabled })` → `{ carousel, band, allies, page_hero, landing }`
- `getBanner(type, location, locale, { enabled })` → un placement
- `getPageHeroSlide(location, locale)` y `getLandingHeroSlide(location, locale)` → el primer slide
  (o `null`)

`localize` lleva los enlaces de los slides a la ruta de cada idioma; `decor-presets` resuelve la
clave del visual decorativo al archivo. Con el módulo apagado, los lectores devuelven vacío.
