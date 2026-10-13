# Manual de usuario · Landings

Guía para crear y mantener **landings** (páginas editoriales con su propia dirección) desde
**HWE Control Center → Landings**. Una landing usa un molde fijo: hero, entradilla, hasta 5
secciones, cita, preguntas frecuentes, productos y cierre. Rellenas lo que necesites; lo demás no
se pinta.

---

## 1. Conceptos

| Concepto | Qué es |
|---|---|
| **Landing** | Una página editorial con su dirección propia. |
| **Registro** | La lista de landings. Cada fila es una landing (identificador, nombre y tipo). |
| **Identificador** | Nombre interno de la landing (minúsculas, números y guiones). **No se puede cambiar** una vez creada: es donde cuelga todo su contenido. |
| **Tipo** | «Artículo» para páginas de contenido, «Persona» para páginas de perfil (cambia los datos estructurados para buscadores). |
| **Slot** | Cada bloque fijo del molde (entradilla, sección 1…5, cita, preguntas, productos, cierre). |
| **Landing con diseño propio** | La que el desarrollo del sitio ha construido a medida. Aparece con «(diseño propio)»: su contenido se edita aquí, pero no se crea ni se quita desde el registro. |

---

## 2. Crear una landing

1. Abre **Control Center → Landings → Registro de landings**.
2. Pulsa **+ Añadir landing** y rellena el **identificador**, el **nombre** y el **tipo**.
3. Pulsa **Guardar**.
4. En **Contenido de las landings** aparece un bloque con su nombre. Ábrelo y rellena lo que quieras.
5. Marca **Publicar** y guarda de nuevo.
6. Crea el **hero** en **Banners**: tipo «Landing», locación «Landing · <nombre>».

> **El identificador y el nombre quedan fijos tras guardar.** Si te equivocas, quita la fila y crea
> otra (el contenido de la anterior se conserva por si vuelves a registrarla).

Para **quitar** una landing, pulsa la × de su fila del registro y guarda. Su contenido se conserva,
pero deja de mostrarse en la web.

---

## 3. El bloque de cada landing

### Publicar y tipo
- **Publicar** — sin marcar, la landing no se muestra en la web aunque tenga contenido.
- **Tipo de landing** (solo las creadas desde el registro) — Artículo o Persona.

### URL pública
- **Ubicación de la URL** — «En el área educativa» (`/learn/<tramo>`) o «En la raíz del sitio»
  (`/<tramo>`). La otra dirección deja de responder.
- **Tramo de la URL** — solo el último tramo, sin barras. Vacío = el identificador. En la raíz no
  puede coincidir con una sección del sitio (tienda, blog, carrito, cuenta…): esos tramos están
  ocupados y la landing volvería sola al área.
- **Tramo de la URL (idioma)** — uno por idioma secundario. Vacío = el mismo que en el idioma por
  defecto. El tramo de cualquier idioma sigue funcionando y redirige al del idioma de la página.

> Cambiar el tramo cambia la dirección pública: la anterior deja de responder. Si ya estaba
> indexada, configura la redirección.

### Hero
Es un banner de tipo «Landing» en la locación «landing-<identificador>». El botón **Editar el hero
en Banners** te lleva a crearlo.

### Color de apoyo
Sustituye al color de acento de la marca solo en esta landing. Vacío = el de la marca.

### Producto asociado
Con un producto, la ficha de ese producto enlaza a esta landing. Si el producto ya tiene su propia
landing de producto publicada, esa gana y esta quedaría inalcanzable.

### SEO
- **Título SEO** y **Descripción SEO** — vacío = se usa el título del hero.
- **Imagen para compartir** — *1200×630 px.* Vacía = la del hero.
- **Fecha de publicación** y **Fecha de última revisión** — `AAAA-MM-DD`. Vacías no se declaran
  (mejor que una fecha inventada). La revisión es la señal de frescura para el buscador.
- **Firmado por** — nombre de quien firma; solo cuenta para datos estructurados (no se pinta).
- **Página de esa persona** — si esa persona tiene su propia landing de tipo «Persona», elígela.

### Entradilla
Texto de apertura con formato. Actívala con **Mostrar entradilla**.

### Secciones 1 a 5
Cada sección es un slot fijo:
- **Mostrar** — apagada no se pinta.
- **Orden** — dónde se pinta (1 a 5). Cambiarlo no mueve el contenido de sitio.
- **Disposición** — imagen a la izquierda, a la derecha, ancho completo, solo texto, bloques con
  subtítulos (reparte el cuerpo por sus `<h3>` en columnas), pareja en espejo y separador pegado al
  banner.
- **Antetítulo**, **Título**, **Cuerpo** (con formato) e **Imagen** con su texto alternativo.
- **Segunda imagen** — solo la usa «Pareja en espejo».

Las medidas de imagen están escritas bajo cada campo. Ninguna necesita archivo aparte para móvil.

### Cita destacada
Cita, autor, foto del autor (*400×400 px*), imagen de fondo (*1920×1080 px*) y logotipo que firma la
banda. El texto alternativo del logotipo se deja vacío si el nombre ya está en el texto.

### Preguntas frecuentes
Hasta seis preguntas con respuesta. Una pregunta sin respuesta no se pinta. Las preguntas se
publican además como «preguntas frecuentes» para los buscadores.

### Productos
- **Fuente** — «Por categoría» (los productos de una categoría) o «Selección manual».
- **Colocar después de** — tras qué bloque se pinta.
- Al cambiar la categoría, **guarda** para refrescar la lista de la selección manual.

### Cierre
Título, texto con formato, hasta dos botones (texto y enlace) e imagen de fondo. Un enlace es una
ruta interna (`/products`) o una dirección completa (`https://…`).

---

## 4. Idiomas

Cada texto tiene un campo por idioma secundario del sitio, junto al del idioma por defecto. **Vacío =
se usa el texto del idioma por defecto.** Un idioma solo se anuncia a los buscadores si la landing
tiene su traducción; sin ella, la dirección existe pero sirve el idioma por defecto.

No se traducen: imágenes, colores, interruptores, el orden ni la disposición.

---

## 5. Errores frecuentes

- **«Guardé pero la landing no aparece».** Comprueba que **Publicar** esté marcado y espera unos
  segundos a la revalidación.
- **«La landing de la raíz me lleva al área».** Su tramo coincide con una sección del sitio; cámbialo.
- **«No veo el bloque de mi landing nueva».** Guarda tras añadirla al registro: el bloque aparece al
  recargar.
- **«No encuentro mi producto en la lista».** Si usas «Por categoría», elige la categoría y guarda;
  con «Selección manual» la lista se acota a esa categoría.
