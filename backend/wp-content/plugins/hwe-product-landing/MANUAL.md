# Manual de usuario · Landing de producto

Guía para crear y administrar la landing de un producto desde su **ficha**
(WP-Admin → Productos → [producto]). No necesitas tocar código: todo se hace desde la caja
**«Landing del producto»**.

> **Idea clave:** cada landing es un molde fijo de 8 secciones de contenido (más el preámbulo
> de Publicación y SEO) que rellenas con tu contenido. La forma la pone automáticamente la web.
> Cuatro apartados (Educativa, Cualidades, Consejos y Video) tienen un interruptor
> «Mostrar»: desactivarlos retira esa sección de la landing publicada.

---

## 1. Conceptos básicos

| Concepto | Qué es |
|---|---|
| **Landing de producto** | Página de aprendizaje vinculada a un producto: explica para qué sirve, de qué se compone, sus cualidades, consejos de uso y una llamada a la compra. Vive en la dirección de la ficha seguida de `/learn`. |
| **Sección** | Cada bloque del molde: Hero, Educativa, Sobre el producto, Cualidades, etc. |
| **Interruptor «Mostrar sección»** | Aparece en Educativa, Cualidades, Consejos y Video. Desactivarlo oculta esa sección en la web publicada. En una landing nueva, **Educativa, Cualidades y Consejos vienen activadas** y **Video viene desactivada**. |
| **Lista dinámica** | Sección donde puedes añadir varias filas (componentes, cualidades, consejos). Botón «+ Añadir» y «×» para quitar. |
| **Idioma por defecto** | Los cuadros del formulario son los del idioma por defecto del sitio. |
| **Idiomas secundarios** | Si el sitio tiene más idiomas, cada campo traducible se traduce en la caja **«Traducciones»** de la ficha, y las filas de las listas llevan sus traducciones dentro de la propia fila. |
| **Publicar landing** | Casilla del preámbulo de Publicación y SEO. Sin marcar, la landing no se muestra en la web aunque rellenes el contenido. |

Publicar y despublicar se hace siempre desde la ficha del producto, con la casilla
**«Publicar landing»**. La landing solo existe en la web mientras esté marcada.

---

## 2. Estructura de la landing

El molde tiene un **preámbulo de Publicación y SEO, una paleta opcional y 8 secciones de
contenido en orden fijo**:

| Nº | Sección | Obligatoria | Mostrar/ocultar | Qué contiene |
|---|---|---|---|---|
| — | **Publicación y SEO** | Sí | — | Publicar landing, título y descripción SEO, imagen para compartir |
| — | **Paleta de la landing** | No | — | Dos colores de acento opcionales |
| **1** | **Hero** | Sí | — | Subtítulo e imagen del hero |
| **2** | **Educativa** | No | Interruptor | Antetítulo, título, cuerpo e imagen |
| **3** | **Sobre el producto + componentes** | Sí | — | Título, descripción y lista de componentes principales |
| **4** | **Cualidades** | No | Interruptor | Título y lista de cualidades con ícono o imagen y texto |
| **5** | **Consejos** | No | Interruptor | Título, intro opcional y lista de consejos y preguntas |
| **6** | **CTA de compra** | Sí | — | Título y texto de la llamada a la compra |
| **7** | **Video** | No | Interruptor | URL de YouTube, pie, miniatura y fecha |
| **8** | **Cierre** | Sí | — | Título, texto y botón de cierre opcional |

> **El diseño de la web puede disponer las secciones de otra manera** (por ejemplo, anidar
> las cualidades dentro de «Sobre el producto»). No cambia cómo se edita: rellenas sección por
> sección y sus interruptores y textos funcionan igual.

> **Los antetítulos son fijos** salvo el de la sección Educativa («Antetítulo educativa»); el
> resto los pone la plantilla de la web y se traducen solos.

---

## 3. Cómo rellenar cada sección

### Publicación y SEO

**Publicar landing** — casilla. Márcala para que se muestre en la web.

**SEO** (todos opcionales):
- **Título SEO** — el que aparece en buscadores, pestañas y al compartir. *Recomendado
  50–60 caracteres.* Vacío = se usa el nombre del producto.
- **Descripción SEO** — resumen para buscadores. *Recomendado 120–160 caracteres.*
- **Imagen para compartir** — portada al compartir en redes. Vacía = la del producto.
  *Medida recomendada: 1200×630 px (1,91:1).*

**Botón «Seleccionar» (imágenes):** abre la Biblioteca de medios. Elige una imagen y pulsa
«Seleccionar».

**Medidas de imagen:** cada campo de imagen lleva escrita debajo su medida recomendada,
deducida del recorte real que hace la web. **Ninguna necesita un archivo distinto para
móvil**: el recorte lo hace el navegador sobre el mismo original. Resumen: compartir
1200×630 · Hero 1200×1500 · Educativa 1200×1500 · Componentes 400×400 · Cualidades 200×200 ·
miniatura del video 1280×720.

### Paleta de la landing (opcional)

Dos colores de acento. Vacíos, la landing usa los colores de la marca. El selector sugiere
tonos derivados de la paleta de la marca para que siempre combinen con el resto del sitio.

### Sección 1 · Hero

**Siempre visible.** Encabezado visual de la landing.

- **Subtítulo del hero** — frase corta junto al nombre del producto. *Recomendado 40–60
  caracteres.*
- **Imagen del hero** — portada principal. Vacía = la imagen del producto.
  *Medida recomendada: 1200×1500 px (4:5).*

El título del hero es el nombre del producto (automático).

### Sección 2 · Educativa

**Interruptor «Mostrar sección educativa»** — desactívalo para saltarte esta sección.

- **Antetítulo educativa** — texto corto sobre el título. *~15 caracteres.*
- **Título educativa** — el título principal.
- **Cuerpo educativa** — explicación larga con formato (negrita, cursiva, listas, enlaces).
  Usa los botones del editor; no escribas HTML a mano.
- **Imagen educativa (opcional)** — ilustración de apoyo. *Medida recomendada: 1200×1500 px
  (4:5).* Si dejas el cuerpo vacío pasa a banda ancha (1920×820 px, 21:9) y en móvil se
  recorta a 4:3: centra el sujeto.

### Sección 3 · Sobre el producto + componentes

**Siempre visible.**

- **Título «sobre el producto»** — encabezado.
- **Cuerpo «sobre el producto»** — descripción larga con formato.
- **Componentes principales** — lista dinámica.

**Lista de componentes:**

1. Pulsa **+ Añadir** por cada componente.
2. En cada fila:
   - **Imagen** — foto del componente. *Medida recomendada: 400×400 px (1:1)*; se recorta a
     un retrato circular, así que el sujeto va centrado y con aire por los cuatro lados.
   - **Ícono (si no hay imagen)** — selector curado (los mismos símbolos que Cualidades).
     Solo se usa si la fila no tiene imagen.
   - **Nombre** — nombre del componente.
   - **Nota (opcional)** — una línea que lo describe.
   - **Traducciones:** una por idioma secundario, bajo Nombre y Nota.
3. Pulsa **×** para quitar una fila.

**Máximo: 11 componentes.** Al llegar a 11 el botón «+ Añadir» se desactiva; si un
producto trae más filas, las que pasan de 11 se descartan al guardar.

### Sección 4 · Cualidades

**Interruptor «Mostrar cualidades»**.

- **Título de cualidades (opcional)** — vacío = la web usa el título por defecto.

**Lista de cualidades:**

1. Pulsa **+ Añadir** por cada cualidad.
2. En cada fila:
   - **Ícono** — selector curado. El set por defecto tiene 12 símbolos (ver §8); la
     instancia puede ampliarlo.
   - **Imagen (reemplaza al ícono)** — si subes una imagen, sustituye al ícono.
     *Medida recomendada: 200×200 px (1:1)*; se pinta muy pequeña, así que solo se lee un
     símbolo simple.
   - **Título** y **Texto** — nombre y descripción corta de la cualidad.
   - **Traducciones** por idioma secundario.
3. Pulsa **×** para quitar.

**Máximo: 11 cualidades.**

### Sección 5 · Consejos

**Interruptor «Mostrar consejos»**.

- **Título de consejos** — encabezado.
- **Intro de consejos (opcional)** — párrafo introductorio con formato.

**Lista de consejos y preguntas:**

1. Pulsa **+ Añadir** por cada fila.
2. En cada fila:
   - **Encabezado (vacío = ítem de lista)** — si lo rellenas, la fila es una **pregunta**
     desplegable. Si lo dejas vacío, es un **consejo** de la lista.
   - **Cuerpo** — la respuesta o el consejo. **Solo texto plano**: al guardar se elimina
     todo el HTML.
   - **Traducciones** por idioma secundario.
3. Pulsa **×** para quitar.

**Máximo: 10 consejos y 10 preguntas** (20 filas en total). Los dos tipos se cuentan por
separado. Una fila sin cuerpo no aparece en la web.

> **Buscadores:** las preguntas con encabezado y respuesta se publican además como
> «preguntas frecuentes» para los buscadores. Lo que se ve y lo que se declara coincide.

### Sección 6 · CTA de compra

**Siempre visible.** Enlaza a la ficha del producto para comprarlo.

- **Título del CTA (opcional)** — vacío = el título por defecto de la plantilla.
- **Texto del CTA (opcional)** — descripción breve de la oferta.

### Sección 7 · Video

**Interruptor «Mostrar video»**.

- **URL de YouTube** — pega el enlace completo (`https://www.youtube.com/watch?v=…` o
  `https://youtu.be/…`). La web lo incrusta.
- **Pie del video (opcional)** — texto bajo el reproductor.
- **Miniatura del video (opcional)** — imagen que se ve antes de reproducir.
  *Medida recomendada: 1280×720 px (16:9).* Vacía = la que genera YouTube.
- **Fecha del video (opcional)** — `AAAA-MM-DD` o `AAAA-MM`. Los buscadores piden la fecha
  completa para destacar el video; con solo el mes el video se publica igual pero no opta a
  ese resultado enriquecido. Una fecha inventada o mal escrita se ignora.

La URL, la miniatura y la fecha son las mismas en todos los idiomas.

### Sección 8 · Cierre

**Siempre visible.**

- **Título de cierre** y **Texto de cierre** — encabezado y párrafo final con formato.
- **Texto del botón de cierre (opcional)** — etiqueta del botón.
- **Enlace del botón de cierre (opcional)** — dirección interna (`/products`) o completa.
  Vacío = el botón vuelve a la ficha del producto.

---

## 4. Campos de texto y campos con formato

- **Texto simple:** títulos, nombres, notas, encabezados y el cuerpo de los consejos. Si
  escribes etiquetas HTML, al guardar se eliminan.
- **Con formato (exactamente cuatro):** **Cuerpo educativa**, **Cuerpo «sobre el producto»**,
  **Intro de consejos** y **Texto de cierre**. Admiten negrita, cursiva, listas y enlaces con
  los botones del editor.

---

## 5. Idiomas

- Los cuadros del formulario son los del **idioma por defecto**.
- Cada idioma secundario del sitio se traduce en la caja **«Traducciones»** (campos sueltos) y
  dentro de cada fila de las listas (nombre, nota, título, texto, encabezado y cuerpo).
- **Si dejas una traducción vacía**, la web muestra ese campo en el idioma por defecto.
- **Un idioma solo se anuncia a los buscadores si la landing tiene traducción** (título SEO,
  subtítulo del hero o alguno de los cuerpos principales). Sin ella, la dirección existe pero
  no aparece en el mapa del sitio ni como alternativa de idioma.
- No se traducen: imágenes, la URL, la miniatura y la fecha del video, los colores ni los
  interruptores.

---

## 6. Reglas de guardado

- **Una landing incompleta es válida.** Puedes rellenar solo algunas secciones y desactivar
  las demás. Lo importante es que **Publicar landing** esté marcado.
- **No se guarda una fila vacía.** Una fila con todos sus campos en blanco se descarta.
- **Basta con un campo.** Un componente sin imagen o una cualidad solo con ícono se guardan.
  En Cualidades, si subes imagen además del ícono, gana la imagen.
- **Una fila con solo traducciones se descarta.** Lo que decide si una fila se guarda son los
  campos del idioma por defecto: rellena al menos uno.
- **El orden de las secciones es fijo.** En las listas, el orden es el que aparece en el
  formulario.

---

## 7. Flujo de trabajo

1. **Productos → [tu producto]** abre la ficha.
2. Baja hasta la caja **«Landing del producto»** y rellena sección por sección.
3. Si el sitio tiene varios idiomas, completa las traducciones.
4. Marca **Publicar landing** y pulsa **Actualizar**.
5. La web se actualiza sola (revalidación) en unos segundos.

Recomendación de relleno inicial:

```
Hero:         Subtítulo + imagen
Educativa:    Antetítulo + título + cuerpo (+ imagen)
Componentes:  3–5 filas
Cualidades:   3–5 filas
Consejos:     3–5 filas (preguntas con encabezado)
CTA:          automático
Video:        URL de YouTube (+ pie)
Cierre:       Título + texto
```

---

## 8. Íconos por defecto

| Clave | Nombre |
|---|---|
| `sparkles` | Destellos |
| `heart` | Corazón |
| `leaf` | Hoja |
| `shield` | Escudo |
| `sun` | Sol |
| `moon` | Luna |
| `droplets` | Gotas |
| `wind` | Viento |
| `flame` | Llama |
| `anchor` | Ancla |
| `compass` | Brújula |
| `sprout` | Brote |

Si subes una **Imagen**, reemplaza al ícono. Una instancia puede añadir más íconos (ver
`README.md`).

---

## 9. Errores frecuentes

- **«Guardé pero la landing no aparece en la web».** Comprueba que **Publicar landing** esté
  marcado y espera unos segundos a la revalidación.
- **«En otro idioma veo el texto en el idioma por defecto».** Esa traducción está vacía:
  rellénala en «Traducciones» o dentro de la fila.
- **«Añadí un componente y no se guardó».** Solo se descarta si todos los campos del idioma
  por defecto de la fila están vacíos.
- **«Cambié el video y la web sigue con el anterior».** Guarda de nuevo la ficha; si persiste,
  desactiva «Mostrar video», guarda, vuelve a activarlo y guarda.
- **«No puedo añadir más filas».** Se llegó al máximo de la lista (11, 11 y 20).

---

## 10. Referencia técnica

- Datos: meta del producto con prefijo `_hwe_landing_*` (no es un tipo de contenido propio).
- API: campo `landing` de `Product` en WPGraphQL (nulo si no está publicada).
- Revalidación: al guardar se revalida el catálogo (`products`) del frontend.

Documentación técnica: [`README.md`](README.md).
