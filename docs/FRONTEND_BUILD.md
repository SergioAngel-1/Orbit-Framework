# Construir el frontend de una instancia — entrevista de negocio

> Audiencia: un agente que va a levantar o adaptar el frontend para un **negocio concreto**
> (una instancia nueva del framework, o un rediseño de una existente). El objetivo de este
> documento es que **preguntes antes de construir**: no adivines el catálogo, las
> funcionalidades ni el tono de marca de un negocio que no conoces. El resultado de la
> entrevista se traduce en (a) un `instance.config.json` para `wp hwe setup` y (b) un plan
> concreto de qué páginas/componentes tocar, usando el inventario de
> **`docs/FRONTEND_CONNECT.md`**.

No sustituye a `docs/CREATE_INSTANCE.md` (esa guía cubre clonar, secretos, Docker, WooCommerce
— el "chore" de infraestructura). Esta guía asume que **el backend ya está levantado** y se
centra solo en las decisiones de negocio que determinan las vistas.

---

## 1. Cómo usar esta guía

- Pregunta **de a un bloque por vez**, en lenguaje natural, no como un formulario burocrático.
  Si el interlocutor ya te dio información en el pedido inicial (p. ej. "es una tienda de
  ropa deportiva"), no vuelvas a preguntarlo — infiere lo que puedas y confirma solo lo dudoso.
- No necesitas respuesta a TODAS las preguntas antes de empezar: prioriza **Identidad de
  marca** y **Catálogo** (bloques 2 y 3) porque bloquean casi todo lo demás; el resto puede
  completarse mientras construyes.
- Cuando una respuesta implica un cambio de código (no solo de config), dilo explícitamente
  — algunas decisiones (p. ej. "necesitamos comparar variantes con un configurador visual")
  no las cubre el framework base y hay que construirlas a medida, aislado en su propia carpeta
  de instancia (nunca mezclado en el framework base — ver `docs/CREATE_INSTANCE.md §7`, y la
  lección aprendida: este framework tuvo justamente ese problema con un mu-plugin y componentes
  de un cliente real que quedaron mezclados en el repo base por error).

---

## 2. Bloque: identidad de marca

Preguntas:
- Nombre del sitio, tagline corto, descripción en 1-2 frases.
- Paleta: ¿tienen ya colores de marca definidos (hex) y tipografía? ¿o hay que proponerlos?
- URL final del sitio, idioma principal (¿necesitan también inglés u otro idioma?).
- Logo, favicon/OG image.

**Determina**: `brand.*`, `design.colors.*`, `design.typography.*` en `instance.config.json`
(ver §4). Si la marca tiene más matices que los 9 colores/2 tipografías del schema, documenta
el mapeo explícito ANTES de rellenar el JSON (tabla de ejemplo en `docs/CREATE_INSTANCE.md §4`).
Si piden un segundo idioma más allá de es/en, es trabajo de código (`i18n/routing.ts` +
mensajes nuevos), no de config — dilo.

---

## 3. Bloque: catálogo y productos

Preguntas:
- ¿Qué venden? ¿Cuántos productos aproximadamente, cuántas categorías?
- ¿Productos simples o con variantes (talla/color/etc.)? ¿Cuántas variantes por producto,
  típicamente?
- ¿Necesitan algo más allá de la ficha de producto estándar (comparador, configurador visual,
  contenido educativo por producto, etc.)? — esto es una señal de trabajo custom, no de config.
- ¿Reseñas de clientes? ¿Lista de deseos/wishlist? ¿Cupones de descuento? ¿Búsqueda?

**Determina**:
- `ecommerce.reviews_enabled` / `wishlist_enabled` / `coupons_enabled` / `search_enabled` en
  `instance.config.json` — recuerda que estos flags **ya están conectados** a la UI (ver
  `docs/FRONTEND_CONNECT.md §1.1`), no hace falta tocar código para activarlos/desactivarlos.
- Si piden búsqueda con resultados reales: `SearchModal` existe pero no está conectado (tiene
  un TODO explícito) — es trabajo de código, no solo de flag.
- Vistas de `/products`: con pocas categorías, `CategoryCard` en la home puede bastar; con
  catálogo grande, conecta `FilterChips`/`SortDropdown` (ninguno está conectado hoy).

---

## 4. Bloque: presencia física y atención al cliente

Preguntas:
- ¿Tienda(s) física(s)? ¿Cuántas, dónde? (nombre, dirección, teléfono, horario, si quieren
  mostrar esto en la web).
- ¿Canales de contacto? (email, teléfono, WhatsApp).
- ¿Redes sociales activas y con contenido real (no vale poner el enlace si la cuenta está
  vacía)?

**Determina**:
- `legal.email`/`legal.address`, `social.facebook`/`instagram`/`youtube`/`twitter`/`linkedin`
  en `instance.config.json` → alimentan `/contact` automáticamente (`ContactForm` ya lee
  `config.legal.email` y `config.social.*`).
- Si hay sedes físicas: **no hay campo en el schema para esto** (es de más alta cardinalidad
  que la config plana del Control Center) — pásalas explícitas a `<ContactForm branches={...}
  phone={...} />` en `app/[locale]/contact/page.tsx` (prop ya soportada, ver
  `docs/FRONTEND_CONNECT.md §3`).

---

## 5. Bloque: contenido editorial y confianza

Preguntas:
- ¿Van a mantener un blog? ¿Con qué frecuencia?
- ¿Preguntas frecuentes (FAQ) que respondan objeciones típicas de compra?
- ¿Partners/aliados, certificaciones, testimonios en vídeo?
- ¿Qué "trust signals" tienen de verdad (envío gratis a partir de X, devoluciones en N días,
  pago seguro, garantía)? — no inventes badges que no son ciertos.

**Determina**:
- `geo.faq` (formato `pregunta | respuesta`, una por línea) → se renderiza en home vía
  `FaqSection` y se emite como JSON-LD `FAQPage` (bueno para citación por IA).
- Si hay aliados/partners reales: `AllyCard` existe pero necesita una fuente de datos — eso es
  un CPT propio de la instancia (mu-plugin aparte, NO en el framework base).
- `TrustBar` (si la usas) — ajusta las 4 etiquetas a lo que es cierto para este negocio antes
  de conectarla (ver `docs/FRONTEND_CONNECT.md §3`, las etiquetas actuales son ejemplo).

---

## 6. Bloque: envío, impuestos y pagos

Preguntas:
- ¿País(es) donde operan? ¿Moneda?
- ¿Tarifa de envío plana, envío gratis a partir de un monto, o algo más complejo (múltiples
  zonas)?
- ¿Pasarela de pago real a integrar (Wompi/PayU/Bold) o sandbox (`noop`) por ahora?

**Determina**: `ecommerce.currency`/`country`, `shipping.*` en `instance.config.json`.
Pasarela real = trabajo de backend (`frontend/src/lib/payments/providers/`, ver `AGENTS.md
§6.8`), no de frontend/vistas — coordina con quien lleve esa parte si aplica.

---

## 7. Bloque: SEO / GEO (visibilidad en buscadores y en IA)

Preguntas:
- ¿Tienen cuenta de Google Search Console (código de verificación)?
- ¿Quieren aparecer citados por asistentes de IA (ChatGPT, Perplexity, Claude)? ¿O prefieren
  bloquear el entrenamiento de IA sobre su contenido?
- ¿Hay una persona fundadora/responsable que quieran destacar (para E-E-A-T)?
- Política de devoluciones (días, categoría), coste de envío — para datos estructurados de
  producto.

**Determina**: `seo.*`, `geo.*` en `instance.config.json` — todo esto ya está implementado y
conectado (`/about`, JSON-LD de producto, `robots.txt`, `/llms.txt`), es puramente config.

---

## 8. De la entrevista a `instance.config.json`

Copia `backend/scripts/instance.config.example.json` a
`backend/scripts/instance.config.json` y rellena los grupos según las respuestas de arriba:
`brand`, `social`, `legal`, `design`, `ecommerce`, `seo`, `shipping`, `geo`, `backups`. Aplícalo
con:

```bash
docker compose run --rm wpcli wp hwe setup /scripts/instance.config.json
```

(Es idempotente — puedes reejecutarlo cuando cambien las respuestas.) Detalle completo del
comando en `docs/CREATE_INSTANCE.md §3`.

---

## 9. De la entrevista al plan de vistas

Con las respuestas de los bloques 2-7, arma un plan concreto usando el inventario de
`docs/FRONTEND_CONNECT.md §2-3`. Plantilla de razonamiento:

1. **Home (`/`)** — ¿el negocio necesita una home de marketing (hero, categorías destacadas,
   trust bar) o el esqueleto actual (posts + FAQ) es suficiente para el lanzamiento? Si hace
   falta más: `HeroCarousel` + `CategoryCard` + `TrustBar` son los building blocks disponibles.
2. **`/products`** — ¿catálogo grande con necesidad de filtrar/ordenar? Conecta `FilterChips`
   + `SortDropdown`. ¿Catálogo pequeño y curado? El listado simple actual puede bastar.
3. **`/contact`** — ¿tiene sedes físicas o solo email/redes? Ajusta las props de `ContactForm`
   según el bloque 4.
4. **Contenido a medida** (partners, testimonios, configurador de producto) — identifica qué
   es "conectar un componente existente" (rápido) vs. "construir algo nuevo" (más trabajo, y
   si es específico del negocio, aíslalo en su propia carpeta de instancia, no en el framework
   base).
5. Recorre el checklist de composición de vistas (`docs/FRONTEND_CONNECT.md §4`) para cada
   página que toques.

---

## 10. Checklist final antes de dar el frontend por "construido"

- [ ] `instance.config.json` aplicado y revisado en `wp-admin → HWE Config` (colores,
      tipografía, flags de ecommerce, SEO/GEO).
- [ ] Home y `/products` responden al negocio real, no al esqueleto genérico (o se decidió
      explícitamente mantenerlo así para el lanzamiento).
- [ ] Cada funcionalidad opcional mencionada en la entrevista corresponde a un flag activado
      Y a una vista que lo respeta (§3 de `docs/FRONTEND_CONNECT.md`).
- [ ] `/contact` tiene los canales reales del negocio (o se dejó vacío a propósito).
- [ ] `i18n/messages/es.json` y `en.json` están en paralelo (si aplica un segundo idioma).
- [ ] Nada de contenido de OTRO negocio (marca, categorías de ejemplo, direcciones) quedó
      hardcodeado en el código — todo lo que es específico de esta instancia sale de
      `instance.config.json`, de props, o está aislado en su propia carpeta si es código.
- [ ] `npx tsc --noEmit`, `npm run test`, `npx next build` en verde.
