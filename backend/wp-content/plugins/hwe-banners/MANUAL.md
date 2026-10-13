# Manual de usuario · Banners

Guía para crear y administrar los banners del sitio desde **WP-Admin → Banners**. No necesitas
tocar código: todo se hace desde el panel.

> **Idea clave:** cada banner ocupa **una celda** definida por su **tipo** (rol) y su
> **locación** (página donde aparece). Solo puede haber **un banner publicado por celda**.
> Los textos van en los campos; la **forma** (arcos, recortes, sangrados) la pone
> automáticamente la web. Exporta siempre **rectángulos opacos** (sin transparencias con
> forma), salvo los logos de Aliados.

Los tipos, locaciones y plantillas que ves dependen de la configuración de tu sitio: aquí se
describen los que trae el framework. Si tu sitio añade más, aparecen en los mismos selectores.

---

## 1. Conceptos básicos

| Concepto | Qué es |
|---|---|
| **Tipo de banner** | El rol del banner: Carrusel, Intermedio (franja), Aliados, Hero de página, Banner Landing. Define qué plantillas de slide admite, cuántos slides permite y en qué páginas puede ir. **Se fija al guardar y no se puede cambiar** (para cambiarlo, crea uno nuevo). |
| **Locación** | La página o superficie donde se muestra (Inicio, y las que añada tu sitio). |
| **Slide** | Cada «diapositiva» del banner. Un carrusel tiene varios; los demás tipos, uno solo. |
| **Plantilla (tipo de slide)** | El diseño del slide. Determina qué campos ves. |
| **Traducciones** | Un bloque «Overrides idioma» por cada idioma secundario del sitio, con sus textos e imágenes. Si la dejas vacía, la web usa el texto del idioma por defecto. |

**Regla de unicidad:** si publicas dos banners del mismo tipo en la misma locación, la web solo
muestra **uno** (el modificado más recientemente) y el panel te avisa con una nota amarilla.

---

## 2. Cómo crear un banner (flujo en 2 pasos)

1. **Banners → Añadir nuevo.**
2. Escribe un **título** (es solo interno, para que lo reconozcas en la lista).
3. **Paso 1 — Elige el tipo de banner** y pulsa **Publicar** (o Guardar borrador).
   - ⚠️ El tipo queda **fijado** tras guardar.
4. **Paso 2 — Configura:** elige la **locación**, el **intervalo** (solo carrusel) y añade los
   **slides** con el botón **«+ Añadir slide»**.
5. Rellena los campos de cada slide (ver §4).
6. **Publica.** Solo los banners **publicados** aparecen en la web (los borradores no).

> Consulta las **medidas recomendadas** de cada imagen en **Banners → Ayuda**, o en la caja
> «Medidas sugeridas» que aparece dentro del propio editor.

---

## 3. Tipos de banner

| Tipo | Slides | Intervalo | Para qué sirve |
|---|---|---|---|
| **Carrusel** | Varios | Sí | El carrusel principal de la portada. Cada slide puede usar una plantilla distinta. |
| **Intermedio (franja)** | 1 | No | Una franja con una sola imagen (retrato o fondo a sangrado, según la locación). |
| **Aliados** | Varios | No | La tira de logos. Cada slide es **un** logo. |
| **Hero de página** | 1 | No | **Solo la imagen** del encabezado de una página interna; el título y los textos ya están en la web. |
| **Banner Landing** | 1 | No | Hero a sangrado con un panel de texto opcional (para las landings). |

---

## 4. Campos de un slide

Cada plantilla muestra **solo** los campos que usa. Los campos posibles son:

### Imagen y visual

- **Imagen (desktop)** — imagen principal. **Obligatoria** salvo que el slide tenga texto o use
  un visual decorativo. Pulsa **Seleccionar** para elegirla de la Biblioteca de medios.
- **Imagen (móvil, opcional)** — versión vertical para móvil. Úsala cuando el recorte de la
  imagen de escritorio pierda el sujeto en pantallas pequeñas. Vacía = se usa la de escritorio.
- **Texto alternativo** — describe la imagen para lectores de pantalla y buscadores.
  **Imprescindible** si el slide es solo imagen con enlace: sin él el enlace no tiene nombre.
- **Visual lateral** (según plantilla) — qué va en el lado visual del slide:
  - **Imagen (foto)** → usas las imágenes de arriba.
  - **Visual decorativo por defecto (plantilla)** → un SVG decorativo de la marca (no subes nada).
  - **Visual decorativo (subir SVG)** → subes tu propio SVG.
  - **Imagen y visual decorativo son excluyentes.** Si eliges un visual decorativo, se ignoran las
    imágenes.
- **Visual decorativo de plantilla** — cuál de los visuales de la marca se muestra. Aparece
  **solo si tu sitio tiene más de uno** configurado y el visual lateral es «por defecto».
- **SVG decorativo** — selector que **solo acepta archivos `.svg`**. Si eliges «subir SVG» y no
  pones uno válido, ese slide **no se guarda**.

### Textos

- **Título** — titular del slide. *Recomendado 28–40 caracteres*; por encima de ~50 se parte en
  demasiadas líneas.
- **Subtítulo** — 1–2 frases. *Recomendado 90–120 caracteres.*
- **Badge** — kicker corto encima del título. *Recomendado 20–36 caracteres.*

### Botones (CTA)

- **Texto del botón (CTA)** — etiqueta del botón. *Recomendado 12–22 caracteres.*
- **URL del botón (CTA)** — a dónde lleva. **Obligatoria si el botón tiene texto.** Ver «Cómo
  escribir una URL», abajo.
- **Texto y URL del botón secundario (CTA 2)** — segundo botón, en las plantillas que lo usan.

### Enlaces y comportamiento

- **URL (clic en la imagen / slide)** — opcional. Hace **clicable** toda la imagen o el slide.
- **Habilitar sección de copy** (solo Landing) — casilla marcada por defecto. Si la
  **desmarcas**, la web muestra **solo la imagen a sangrado**, sin panel de texto.
- **Orden** (solo tipos con varios slides) — número para ordenar: `1, 2, 3…` de menor a mayor.
  Un `0` se interpreta como «sin orden» y ese slide se va **al final**.

### Cómo escribir una URL

Cada enlace se escribe **una sola vez** y vale para todos los idiomas: el sitio lo traduce solo.

- **Página del sitio → su ruta INTERNA**, no la dirección que se ve en el navegador:
  `/products`, `/categories/mi-categoria`, `/products/mi-producto`, `/blog/mi-entrada`. Escribe
  siempre el slug del idioma por defecto.
- **No pegues** una dirección que empiece por el prefijo de un idioma (`/en/…`) ni el dominio
  completo: el enlace funciona, pero pasa por una redirección (error en las auditorías SEO).
- **Otro sitio web →** la URL completa (`https://…`). Se abre en pestaña nueva.
- **Una sección de la misma página →** el ancla (`#selector`).

### Traducciones

Al final de cada slide hay un bloque «Overrides idioma» por cada **idioma secundario** del sitio. Rellénala
**solo** si quieres un texto o imagen distintos en ese idioma: Imagen, Imagen móvil, Texto
alternativo, Título, Subtítulo, Badge y el texto de los dos botones. **Lo que dejes vacío hereda
automáticamente la versión del idioma por defecto.** Las **URL no se traducen**: la del slide vale
para todos los idiomas. (Los overrides de imagen solo aplican cuando el visual es una foto, no un
visual decorativo.)

---

## 5. Recetas rápidas por tipo

### Carrusel (portada)
1. Tipo **Carrusel** → Publicar. Locación **Inicio**. Ajusta **Intervalo** (ms; mín. 1000, por
   defecto 6000).
2. **+ Añadir slide** por cada diapositiva; si tu sitio ofrece varias plantillas, elige el **Tipo
   de plantilla** de cada una.
3. Usa **Orden** para colocarlas. Publica.

### Intermedio / franja
1. Tipo **Intermedio (franja)** → Publicar. Elige la **Locación**.
2. Un solo slide: **Imagen** (+ móvil opcional). Publica.

### Aliados
1. Tipo **Aliados** → Publicar.
2. **+ Añadir slide** por cada logo: **Imagen** (PNG/SVG con fondo transparente), **Título**
   (nombre/alt) y **URL** opcional. Ordena con **Orden**. Publica.

### Hero de página
1. Tipo **Hero de página** → Publicar. Elige la **Locación** (la página interna).
2. Un solo slide: **Imagen** (+ móvil opcional). Nada más: los textos de esas páginas están en
   la web. Publica.

### Banner Landing
1. Tipo **Banner Landing** → Publicar. Elige la **Locación** de la landing.
2. Un slide: **Imagen** a sangrado. Marca **«Habilitar sección de copy»** si quieres el panel con
   badge, título, subtítulo y dos CTAs; desmárcala para dejar solo la imagen. Publica.

---

## 6. Medidas de imagen

Las medidas recomendadas (desktop/móvil) de **todos** los tipos y plantillas están en:

- **Banners → Ayuda** (página con todas las tablas), y
- la caja **«Medidas sugeridas»** dentro del editor de cada banner.

Principios generales:

- Exporta **rectángulos opacos**. La web aplica los arcos y recortes por CSS; si entregas un PNG
  ya recortado con transparencia, se verá mal.
- Entrega también **imagen móvil** cuando el recorte de escritorio corte el sujeto.
- **Aliados** es la excepción: logos en **PNG/SVG con fondo transparente**.

---

## 7. Reglas y errores frecuentes

- **El tipo no se puede cambiar** tras guardar. Si te equivocaste, crea un banner nuevo.
- **Un banner por celda (tipo + locación).** Si ves una nota amarilla de «duplicado», despublica
  o borra el que sobra: la web solo mostrará el más reciente.
- **Un slide sin nada que mostrar se descarta al guardar** (sin imagen, sin visual decorativo
  válido y sin ninguna línea de texto), y el panel te avisa de cuántos descartó. Un slide con
  titular y botones **sí se guarda** aunque aún no tenga foto. Con «subir SVG», el archivo debe
  terminar en `.svg`.
- **CTA sin URL no sirve.** Si pones texto de botón, completa su URL.
- **El intervalo** solo existe en el Carrusel (mínimo 1000 ms).
- **Máximo de slides:** los tipos de un solo slide guardan solo el primero (según el Orden).
- **Publica** para que aparezca. Los borradores no se muestran en la web.
- **Cambios en vivo:** al guardar o borrar, la web se actualiza sola (revalidación automática).

---

## 8. Referencia técnica (para desarrollo)

Orientado al equipo técnico; el uso diario **no** lo necesita.

- CPT: `hwe_banner`. Metas: `_hwe_banner_type`, `_hwe_banner_location`,
  `_hwe_banner_interval_ms`, `_hwe_banner_slides` (array de slides).
- API de lectura (headless):
  - `GET /wp-json/hwe-banners/v1/banners?location=<loc>&lang=<loc>`
  - `GET /wp-json/hwe-banners/v1/banners/<type>?location=<loc>&lang=<loc>`
  - Si omites `location`, se asume la primera declarada (`home`).
- Extensible por filtros: `hwe_banners_banner_types`, `hwe_banners_locations`,
  `hwe_banners_slide_types`, `hwe_banners_size_guide`, `hwe_banners_field_hints`,
  `hwe_banners_decor_presets`, `hwe_banners_slide_visible` / `hwe_banners_slide`.
- Documentación de desarrollo: [`README.md`](README.md).
