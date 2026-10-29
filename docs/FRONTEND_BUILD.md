# Construir el frontend de una instancia — entrevista de negocio

> Audiencia: un agente que va a levantar o adaptar el frontend para un **negocio concreto**
> (una instancia nueva del framework, o un rediseño de una existente). El objetivo de este
> documento es que **preguntes antes de construir**: no adivines el catálogo, las
> funcionalidades ni el tono de marca de un negocio que no conoces. El resultado de la
> entrevista se traduce en (a) un `instance.config.json` para `wp hwe setup` y (b) un plan
> concreto de qué construir, contra el contrato backend/BFF de **`docs/FRONTEND_CONNECT.md`
> Parte A**.

**Recuerda `AGENTS.md §1.1`**: el frontend (UI y vistas) NO es núcleo del framework — es
responsabilidad de esta instancia desde el momento en que se clona. Esta entrevista te ayuda a
decidir **qué construir**, sea reutilizando el código heredado que trae el repo
(`docs/FRONTEND_CONNECT.md` Parte B) o escribiéndolo desde cero contra el contrato (Parte A).
Ninguna de las dos opciones es "más correcta" — depende de si lo heredado encaja con el
negocio o conviene descartarlo.

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
  no las cubre el framework base y hay que construirlas a medida, en las rutas de la instancia
  (`mu-plugins/instance/`, `src/instance/`; nunca mezclado en el núcleo — ver
  `docs/CREATE_INSTANCE.md §7`, y la lección aprendida: este framework tuvo justamente ese
  problema con un mu-plugin y componentes de un cliente real que quedaron mezclados en el repo
  base. Hoy lo impide una puerta en CI y una regla de ESLint).
- Antes de proponer trabajo a medida, comprueba si ya existe un **módulo** (§7 bis) o un
  mecanismo declarativo (campos de producto, detalles de línea de pedido) que lo cubra.

---

## 2. Bloque: identidad de marca

Preguntas:
- Nombre del sitio, tagline corto, descripción en 1-2 frases.
- Paleta: ¿tienen ya colores de marca definidos (hex) y tipografía? ¿o hay que proponerlos?
- URL final del sitio, idioma principal y qué otros idiomas necesitan (¿inglés, francés, portugués…?).
- Logo, favicon/OG image.

**Determina**: `brand.*`, `design.colors.*`, `design.typography.*` en `instance.config.json`.
Si la marca tiene más matices que los 9 colores/2 tipografías del schema, documenta
el mapeo explícito ANTES de rellenar el JSON (tabla de ejemplo en `docs/CREATE_INSTANCE.md §4`).
Los idiomas se declaran con `i18n.locales` (los secundarios; `brand.locale` es el principal) y
el contenido del CMS se traduce por idioma desde `wp-admin` (`hwe-content-i18n`). Eso es config;
lo que sigue siendo código es el alta del idioma en `i18n/routing.ts` y su
`messages/<idioma>.json` (`npm run i18n:check` exige la paridad). El arranque avisa si
`routing.ts` e `i18n.locales` divergen.

---

## 3. Bloque: catálogo y productos

Preguntas:
- ¿Qué venden? ¿Cuántos productos aproximadamente, cuántas categorías?
- ¿Productos simples o con variantes (talla/color/etc.)? ¿Cuántas variantes por producto,
  típicamente?
- ¿Necesitan algo más allá de la ficha de producto estándar (comparador, configurador visual,
  contenido educativo por producto, etc.)? — esto es una señal de trabajo custom, no de config.
- ¿Reseñas de clientes? ¿Lista de deseos/wishlist? ¿Cupones de descuento? ¿Búsqueda?
- ¿Landing propia por producto? ¿Campos que cada producto necesita y WooCommerce no trae
  (modo de uso, ingredientes, medidas…)? ¿Datos que el cliente debe aportar por línea de pedido
  (dedicatoria, talla grabada…)? ¿Banners de campaña?

**Determina**:
- `ecommerce.reviews_enabled` / `wishlist_enabled` / `coupons_enabled` / `search_enabled` en
  `instance.config.json` — recuerda que estos flags **ya están conectados** a la UI (ver
  `docs/FRONTEND_CONNECT.md §A.3`), no hace falta tocar código para activarlos/desactivarlos.
- Si piden búsqueda con resultados reales: `SearchModal` existe pero no está conectado (tiene
  un TODO explícito) — es trabajo de código, no solo de flag. El BFF ya la sirve
  (`/api/store/products?search=`, gateada por `search_enabled`).
- Campos de producto propios → declaración en `hwe_product_fields` (PHP) y `productFields` en
  `src/instance/registry.ts`; detalles por línea de pedido → `hwe_order_item_details`. Son
  mecanismos del núcleo: se **declaran**, no requieren código de plomería (ver
  `docs/PLUGIN_DEVELOPMENT.md`).
- Landing por producto → módulo `product-landing`; banners → módulo `banners`.
- Vistas de `/products`: con pocas categorías, `CategoryCard` en la home puede bastar; con
  catálogo grande, conecta `FilterChips`/`SortDropdown` (ninguno está conectado hoy).

---

## 4. Bloque: sedes físicas y atención al cliente

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
- Si hay sedes físicas: hasta **5** en `legal.locations` (nombre, dirección, ciudad, teléfono,
  horario, mapa, coordenadas) y `legal.phone`/`mobile`. Ya alimentan el JSON-LD `LocalBusiness`
  (`getConfiguredLocations`, `businessPhone`); para **mostrarlas** en `/contact` pásalas a
  `<ContactForm branches={...} phone={...} />` en `app/[locale]/contact/page.tsx` (props ya
  soportadas, ver `docs/FRONTEND_CONNECT.md §B.2`). Más de 5 sedes sí es trabajo de instancia.

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
  de conectarla (ver `docs/FRONTEND_CONNECT.md §B.2`, las etiquetas actuales son ejemplo).

---

## 6. Bloque: envío, impuestos y pagos

Preguntas:
- ¿País(es) donde operan? ¿Moneda?
- ¿Tarifa de envío plana, por ciudad, envío gratis a partir de un monto, extras (entrega
  urgente, regalo)? ¿Hay una zona donde no se reparte?
- ¿Medios de pago: pago en línea (¿qué pasarela?), pedido por WhatsApp, efectivo? ¿Compra de
  invitado o solo con cuenta?
- ¿Piden documento de identidad o aceptación de términos al comprar o registrarse?
- ¿Venden membresías con niveles, ventajas, catálogo exclusivo o carné de socio?
- ¿Un sistema externo (ERP, logística) que deba leer los pedidos?

**Determina**:
- `ecommerce.currency` (defecto `EUR`; fíjala siempre) y `ecommerce.country` (elige el paquete por país: ciudades,
  tipos de documento, direcciones), `ecommerce.free_shipping.*` y
  `ecommerce.checkout_login_required` en `instance.config.json`.
- Módulos: `shipping` (tarifa por ciudad y extras; las tarifas se cargan en el menú «Envío» de
  wp-admin, no en el JSON), `customer-identity`, `external-integration`, `coupon-rules`,
  `memberships`.
- Pasarela: **Wompi y Aval Pay ya están de punta a punta** y se eligen desde el panel
  (`payments.provider`, `gateway_enabled`, `whatsapp_enabled`, `cash_enabled`); PayU y Bold son
  stubs. Otra pasarela = implementar `PaymentProvider` en `frontend/src/lib/payments/providers/`
  (ver `AGENTS.md §6.8`): trabajo de backend/BFF, no de vistas.
- Un país sin paquete propio: añade el suyo con el filtro `hwe_geo_packs` desde la instancia
  (`docs/PLUGIN_DEVELOPMENT.md`); mientras tanto el paquete `generic` no fuerza ciudades ni
  documentos.

---

## 7. Bloque: SEO / GEO (visibilidad en buscadores y en IA)

Preguntas:
- ¿Tienen cuenta de Google Search Console y de Bing Webmaster Tools (códigos de verificación)?
- ¿Sustituye a una web anterior? Necesitamos la lista de sus URLs para las redirecciones 301
  (`redirects` del registro de la instancia).
- ¿Habrá un staging público? Lleva `HWE_NOINDEX=true`.
- ¿Quieren aparecer citados por asistentes de IA (ChatGPT, Perplexity, Claude)? ¿O prefieren
  bloquear el entrenamiento de IA sobre su contenido?
- ¿Hay una persona fundadora/responsable que quieran destacar (para E-E-A-T)?
- Política de devoluciones (días, categoría), coste de envío — para datos estructurados de
  producto.

**Determina**: `seo.*`, `geo.*` en `instance.config.json` — todo esto ya está implementado y
conectado (`/about`, JSON-LD de producto, `robots.txt`, `/llms.txt`), es puramente config.
La lista de comprobación del lanzamiento está en `docs/GO-LIVE.md` §6 bis.

---

## 7 bis. Bloque: módulos (qué enciende cada respuesta)

Cada funcionalidad opcional de la entrevista se corresponde con un módulo. Enciende solo los
que el negocio usa: un módulo apagado desaparece del todo (lector vacío, rutas del BFF en 404,
interfaz sin pintar).

| Si el negocio… | Módulo | Notas |
|---|---|---|
| Quiere banners de campaña en la home u otras zonas | `banners` | Tipo × locación; los visuales decorativos de plantilla se declaran en `registry.ts` (`decorPresets`) |
| Cobra el envío por ciudad, con extras o con envío gratis por regla | `shipping` | Tarifas en el menú «Envío» de wp-admin |
| Quiere una landing de venta por producto | `product-landing` | Caja «Landing del producto» en la ficha, a N idiomas |
| Publica guías, artículos o páginas de persona con diseño editorial | `landings` | Las landings con diseño propio se declaran en `registry.ts` (`landings`) y en `hwe_landings_static_entries` |
| Debe demostrar el consentimiento de cookies (RGPD) | `consent-log` | Retención en `integrations.consent_retention_years` |
| Quiere que el cliente valore el servicio tras un pedido completado | `site-reviews` | Distinta de la reseña de producto (`reviews_enabled`) |
| Usa cupones con condiciones (primera compra, ventana, alcance) | `coupon-rules` | Aparte del flag `coupons_enabled` |
| Necesita documento de identidad o aceptación de términos | `customer-identity` | Tipos de documento y patrones del paquete de país |
| Quiere fidelizar con puntos y referidos | `loyalty` | Control Center → Fidelización; canje en el carrito y BFF `/api/loyalty/*` (sin UI propia; ver `docs/FRONTEND_CONNECT.md`) |
| Vende membresías con niveles, catálogo exclusivo, ventajas o carné | `memberships` | Menú «Membresías» y Control Center → Membresías; el frontend consume `/api/memberships/*`, la extensión del carrito y `hweMembership` (sin UI propia; ver `docs/FRONTEND_CONNECT.md`) |
| Conecta un ERP u otro sistema que lee pedidos | `external-integration` | IP exacta + contraseña de aplicación; política cerrada |

Además, dos mecanismos **siempre activos** que se declaran en la instancia, sin módulo:
campos de producto (`hwe_product_fields`) y detalles de línea de pedido
(`hwe_order_item_details`).

---

## 8. De la entrevista a `instance.config.json`

Copia `backend/scripts/instance.config.example.json` a
`backend/scripts/instance.config.json` y rellena los grupos según las respuestas de arriba:
`brand`, `social`, `legal`, `design`, `ecommerce`, `i18n`, `modules`, `auth`, `payments`, `seo`,
`geo`, `integrations`, `backups`. Aplícalo con:

```bash
docker compose run --rm --entrypoint wp wpcli hwe setup /scripts/instance.config.json
```

(Es idempotente — puedes reejecutarlo cuando cambien las respuestas. `modules` **activa los
listados y desactiva los demás**.) Detalle completo del comando en `docs/CREATE_INSTANCE.md §3`;
cada campo en `docs/CONFIGURATION.md`.

Lo que **no** cabe en el JSON: credenciales (pasarelas, SMTP, Meta CAPI: campos `secret` del
panel o variables de entorno), las tarifas de envío y los cupones (wp-admin) y el contenido
(productos, banners, landings, traducciones).

## 9. Componentes: qué usar y de dónde

Regla general: **si un componente ya existe y encaja, úsalo antes de escribir uno nuevo** —
pero recuerda que solo `components/ui/` es un contrato del framework (`AGENTS.md §1.1`); todo
lo demás es el punto de partida heredado (`docs/FRONTEND_CONNECT.md` Parte B) y puedes
reemplazarlo sin miedo si no encaja con el negocio.

### 9.1 Obligatorios — siempre de `components/ui/` (núcleo del framework)

No reinventes estos con HTML/CSS suelto: son el vocabulario visual base, ya accesibles,
consistentes con los design tokens (§A.5 de `docs/FRONTEND_CONNECT.md`) y con soporte de modo
oscuro.

| Componente | Úsalo para |
|---|---|
| `Button` | Cualquier acción/CTA (variantes `solid`/`outline`, con `loading`/iconos) |
| `Input`, `Select`, `Textarea`, `Checkbox` | Cualquier campo de formulario |
| `Card` | Contenedor con borde/sombra reutilizable (base de `AllyCard`, `VideoCard`, etc.) |
| `Badge` | Etiquetas de estado (oferta, stock, categoría) — colores `brand`/`secondary`/`accent`/`success`/`warning`/`error`/`gray` |
| `Modal` | Cualquier diálogo/overlay (usado por `SearchModal`, `CountrySelector`) |
| `Alert` | Mensajes de error/aviso/éxito |
| `Skeleton` | Estados de carga (todas las tarjetas de catálogo lo soportan vía prop `loading`) |
| `Spinner` | Carga inline en botones/acciones |
| `Paginator` | Paginación clásica (alternativa a scroll infinito) |
| `QuantityCounter` | Selector de cantidad (carrito, ficha de producto) |
| `DarkModeToggle` | Ya integrado en `SiteHeader`; solo lo tocas si rediseñas el header |

Junto con estos componentes, son igual de obligatorios los **patrones** de
`docs/FRONTEND_CONNECT.md` Parte A: `getSiteConfig()` como única fuente de marca, el gating por
`config.ecommerce.*_enabled`, y `Link`/`redirect` de `@/i18n/navigation` en vez de `next/*`.

### 9.2 Recomendados — heredados, según lo que decidas en cada bloque de la entrevista

| Bloque | Componente heredado | Úsalo si… |
|---|---|---|
| 2. Identidad de marca | *(ninguno — es 100% `wp-admin → HWE Config`; `ThemeTokens` aplica la paleta sola)* | — |
| 3. Catálogo | `ProductCard`, `ProductGrid` / `InfiniteProductGrid` | Siempre — ya conectados, es el listado estándar de producto |
| 3. Catálogo | `ProductCardHorizontal` | Resultados de búsqueda o listas densas (usado por `SearchModal`) |
| 3. Catálogo | `CategoryCard` | El negocio tiene categorías que merecen destacarse (home o cabecera de `/products`) |
| 3. Catálogo | `FilterChips` + `SortDropdown` | Catálogo grande que necesita filtrar/ordenar en `/products` |
| 3. Catálogo | `SearchModal` | Piden búsqueda con resultados reales — **requiere conectar `onSearch`** (tiene un TODO explícito), no es solo activar `search_enabled` |
| 4. Sedes físicas / contacto | `ContactForm` (props `email`/`phone`/`socials`/`branches`) | Casi siempre — ya conectado en `/contact`; pasa `branches`/`phone` si hay sedes físicas |
| 5. Contenido editorial | `HeroCarousel` | La home necesita un banner rotativo de campañas/producto destacado |
| 5. Contenido editorial | `PageHero` + `Breadcrumb` | Quieres dar identidad a cabeceras de página en vez del `<h1>` plano actual |
| 5. Contenido editorial | `TrustBar` | Franja de confianza bajo el header — **ajusta las 4 etiquetas de ejemplo al negocio real antes de conectarla** |
| 5. Contenido editorial | `AllyCard` | Hay partners/aliados reales (necesitas fuente de datos propia — CPT de instancia, no del framework) |
| 5. Contenido editorial | `VideoCard` | Hay testimonios/contenido en vídeo |
| 5. Contenido editorial | `FaqSection` | Ya conectado en home si rellenas `geo.faq` — no hace falta decidir nada |
| 6/7. Envío, pagos, SEO/GEO | *(ninguno — 100% config `instance.config.json` + `lib/payments/` si integras una pasarela real)* | — |

### 9.3 Layout — ya conectado, normalmente no hay que decidir nada

`SiteHeader` y `SiteFooter` ya están wireados en `app/[locale]/layout.tsx` para todas las
páginas. Lo único que suele ajustarse por instancia: `SiteFooter` acepta un prop `columns?`
si el negocio necesita otra estructura de enlaces a la que trae por defecto (tienda/blog/sobre
nosotros/contacto/legal).

Detalle de props/estado de conexión de cada componente de esta sección:
`docs/FRONTEND_CONNECT.md §B.2`.

---

## 10. De la entrevista al plan de vistas

Con las respuestas de los bloques 2-7 y la selección de componentes de §9, arma un plan
concreto página por página. Plantilla de razonamiento:

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
5. Recorre el checklist de composición de vistas (`docs/FRONTEND_CONNECT.md §B.3`) para cada
   página que toques.

---

## 11. Checklist final antes de dar el frontend por "construido"

- [ ] `instance.config.json` aplicado y revisado en `wp-admin → HWE Config` (colores,
      tipografía, flags de ecommerce, SEO/GEO).
- [ ] Home y `/products` responden al negocio real, no al esqueleto genérico (o se decidió
      explícitamente mantenerlo así para el lanzamiento).
- [ ] Cada funcionalidad opcional mencionada en la entrevista corresponde a un flag activado
      Y a una vista que lo respeta (§B.2 de `docs/FRONTEND_CONNECT.md`).
- [ ] `/contact` tiene los canales reales del negocio (o se dejó vacío a propósito).
- [ ] Hay un `i18n/messages/<idioma>.json` por cada idioma (`routing.ts` e `i18n.locales`
      coinciden) y `npm run i18n:check` pasa.
- [ ] Los módulos encendidos son los que el negocio usa y `sh backend/scripts/verify-modules.sh`
      pasa contra la pila.
- [ ] Nada de contenido de OTRO negocio (marca, categorías de ejemplo, direcciones) quedó
      hardcodeado en el código — todo lo que es específico de esta instancia sale de
      `instance.config.json`, de props, o está aislado en su propia carpeta si es código.
- [ ] `npm run lint`, `npm run type-check`, `npm run test`, `npm run i18n:check` y `npx next build`
      en verde (y `composer test` desde `backend/` si añadiste PHP).
