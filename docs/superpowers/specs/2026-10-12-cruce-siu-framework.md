# Cruce de funcionalidades: Siu Tutuava → Headless Web Ecosystem

> Fecha: 2026-10-12 · Base común: framework `85c52c3` (v1.0, 2026-07-14), importado en Siu como
> `77a8c62f` (2026-07-20). Siu HEAD: `d008bc15` (2026-10-08). Framework HEAD: `6cb3f13`.

## 1. Resumen

| | Framework desde la base | Siu desde la base |
|---|---|---|
| Commits | 13 (todos: plugin HWE Banners) | ~2.000 |
| `backend/wp-content/mu-plugins` | — | 88 archivos, +19.010 / −930 |
| `backend/wp-content/plugins` (propios) | hwe-banners | hwe-banners (más evolucionado), hwe-shipping, hwe-product-landing (+5.851) |
| `backend/scripts` | — | 57 archivos, +13.330 |
| `frontend/src/lib` | banners | 395 archivos, +39.829 |
| `frontend/src/app/api` | revalidate (tag banners) | 62 archivos, +5.252 (12 rutas nuevas, casi todas las demás modificadas) |
| `components/ui`, `proxy.ts`, `next.config.mjs`, Caddy, Compose prod, CI | — | +4.000 aprox. |

**Conclusión:** en lo que se ve en este repo, el framework sigue en la v1.0 más Banners. Siu
corrigió en producción **bugs de seguridad y de funcionamiento que siguen presentes en el
framework**: algunos impiden que una instancia funcione (la config remota no se aplica y los
webhooks se bloquean). Además desarrolló unas **40 piezas genéricas** reutilizables. El
mecanismo de "funcionalidades de instancia" existe en Siu, pero tiene fugas hacia el núcleo y
hay que rediseñarlo antes de portar nada que dependa de él.

### Alcance y límites de este análisis

- **Verificado en el código del framework** (lectura directa o comparación con el archivo
  previo al fix): todos los puntos marcados ✅ en §2.
- **Clasificado por cabecera/docblock, nombre y mensaje de commit** (sin leer cada línea): el
  inventario de §3–§5. La clasificación es fiable para decidir, pero cada port necesitará una
  lectura completa del archivo.
- No se levantó ninguna pila: nada de esto se probó en vivo.

---

## 2. Nivel 0 — Bugs del núcleo presentes hoy en el framework (portar ya)

| # | Hallazgo | Gravedad | Evidencia en el framework | Fix en Siu |
|---|---|---|---|---|
| 1 | **Next < 16.3.3: RCE sin autenticar en `/_next/image`** (AVIF, GHSA-2xp9-vwfh-vxw4) + sharp/libheif y transitivas | Crítica | `package.json`: `next 16.2.9` ✅ | `10fd6890`, `4b223dc9`, `9be779ed` |
| 2 | **Bypass del 2FA**: el `ephemeralToken` es un JWT *firmado, no cifrado*, que lleva `authToken`/`refreshToken` reales. Basta decodificarlo en base64 para tener la sesión sin el segundo factor | Crítica | `app/api/auth/login/route.ts:110-118` ✅ | `655e30dc` (`lib/auth/pending-login.ts`, id opaco en Redis, falla cerrado) |
| 3 | **`deepMerge` descarta toda la config remota**: devuelve el valor por defecto en cada hoja escalar, así que marca, paleta, moneda y flags del Control Center nunca llegan al frontend | Crítica (funcional) | `lib/config/remote.ts:27` ✅ | `c3c253d1` |
| 4 | **El proxy devuelve 403 a todos los webhooks** (no traen `Origin`/`Referer`) en cuanto hay `ALLOWED_ORIGIN`, es decir, en producción: no hay revalidación ISR, el dispatcher de pedidos no se entera de nada y el webhook de pago no llega | Crítica (funcional) | `proxy.ts:80-99` ✅ | `39344b99` |
| 5 | GraphQL, Store API y `/hwe/v1/auth/*` accesibles por parámetro (`/?graphql`, `?rest_route=`) saltándose Caddy, rate-limit y 2FA | Alta | No existe `hwe-bff-only.php` ✅ | `dab25cac`, `93730545` |
| 6 | Clave de cifrado de reserva escrita en el código (`hwe-fallback-insecure-key`) para los secretos del Control Center (pasarelas, SMTP) y el TOTP | Alta | `SecretsStorage.php` idéntico al pre-fix ✅ | `b4eb6ed7` |
| 7 | `CSRF_SECRET` cae a `GRAPHQL_JWT_AUTH_SECRET_KEY` y después a `"insecure-dev-csrf-secret"` | Alta | `lib/security/csrf.ts:22-24` ✅ | `a4e51ae4` |
| 8 | El endpoint WP de 2FA activa/desactiva sin prueba TOTP: un access-token robado basta | Alta | — (`hwe-auth.php` difiere; revisar al portar) | `572a9796`, `f697fbbf`, `006929b5` |
| 9 | El secreto TOTP viaja a `api.qrserver.com` (un tercero) en la query string | Alta | `two-factor-setup.tsx:196`, CSP `next.config.mjs:86` ✅ | `cce52c06`, `7981816d` (QR en servidor, `/api/auth/2fa/qr`) |
| 10 | `reserveIdempotencyKey` falla abierto cuando `SET NX` ya detectó un duplicado | Media-alta | `idempotency.ts` idéntico al pre-fix ✅ | `9f01a94f` |
| 11 | `returnUrl` de `payments/create` sin allowlist: redirección abierta a través de la pasarela | Media | `validation/payments.ts` idéntico al pre-fix ✅ | `cab6a23d` |
| 12 | Reseñas: nombre y email salen del input, no de la cuenta (suplantación) | Media | `reviews/[productId]/route.ts:81-82` ✅ | `afab4868` |
| 13 | Cambiar la contraseña no revoca las otras sesiones | Media | Sin revocación en `change-password/route.ts` ✅ | `c8c22abc` |
| 14 | `payload.id` de los webhooks de pedido sin validar el tipo | Media | `order-created/route.ts` idéntico al pre-fix ✅ | `ad34ce7d` |
| 15 | Falta rate-limit en la búsqueda de productos, `/api/revalidate` y la lectura del carrito | Media | Ninguno de los tres usa `rateLimit` ✅ | `e9d5d642`, `30d022b1`, `be101b58` |
| 16 | `sanitizeHtml` no fuerza `rel="noopener noreferrer"` en `target=_blank` | Baja-media | Sin hook en `sanitize.ts` ✅ | `a58de100`, `ffc65842` |
| 17 | Tokens de diseño inyectados en `<style>` sin validar (CSS injection desde el panel) | Baja-media | `tokens.ts` sin validación ✅ | `44bb5f5f` |
| 18 | `secret-guard` no exige `ALLOWED_ORIGIN` https en producción | Baja | Sin la regla en `secret-guard.ts` ✅ | `1c7080fb`, `6ea9f0ff` |
| 19 | Refresh del JWT en el proxy sin timeout (cuelga la navegación) | Baja | — | `e11bcc8d` |
| 20 | Ops: backups con `-p<password>`, fallos de `mariadb-dump` silenciosos, producción sin backups automáticos, Apache sin techo de memoria (OOM), cabeceras de seguridad que faltan en las rutas servidas por WP, sin checksum en `setup.sh` | Media | — | `7682b341`, `fbd0ca4f`, `e2761df8`, `88a851a4`, `ec4d8142`, `d4b13199` |

> Los puntos 3 y 4 explican por qué una instancia recién clonada del framework "no funciona"
> sin que nada falle: el sitio carga, pero con la config por defecto y sin revalidar.

---

## 3. Nivel 1 — Genérico: portar con cambios mínimos

Piezas que sus propios docblocks declaran genéricas y que no contienen negocio de Siu (o solo
un comentario o un ejemplo).

### Backend (mu-plugins, salvo indicación)
- **Seguridad y acceso**: `hwe-bff-only` (sin la rama Atlas, ver §4), `hwe-login-slug`
  (slug configurable, `HWE_LOGIN_SLUG`), `hwe-svg-uploads` (SVG saneado), endurecimiento de
  `rate-limit.php`, `security.php`, `graphql-protection.php` y `headless-config.php`.
- **Control Center — infraestructura**:
  - tipos de campo nuevos en el Walker: `image`, `richtext`, `number`, `file`, `slug`, `lines`,
    `pairs`, `product_multiselect`, `product_fields_list`, `link`, `registry_type` (hoy el
    framework solo tiene `text/group/boolean/url/select/color/secret/textarea/email`);
  - `skip_persist`, guardado *sparse* en `wp hwe setup`, filtro `hwe_control_center_schema`;
  - `PaymentsInternalApi` y `IntegrationsInternalApi` (credenciales servidas al BFF con el
    secreto interno);
  - sincronización con Woo: `WooCurrencySync`, `WooGuestCheckoutSync`, `WooCashGatewaySync`;
  - `FreeShippingRule`;
  - backups: `BackupActions` (backup manual, exportar config, `status.json`) y `BackupNotify`;
  - fix «los secretos guardados se muestran como guardados» (`3f42d4fe`).
- **Revalidación**: `hwe-post-revalidate` (tag `posts`), `hwe-menu-revalidate` (`menus`;
  `82d73ad7`: el tag existía pero nada lo disparaba) y `ALLOWED_HWE_TAGS` ampliado
  (`site-config|banners|shipping|posts|menus|products|coupons`).
- **SEO editorial**: `hwe-post-seo`, `hwe-page-seo`, `hwe-product-seo`, `hwe-category-seo`.
- **Blog**: `hwe-featured-post`.
- **Pedidos y clientes**:
  - `hwe-site-reviews` (reseña del pedido);
  - `hwe-coupon-rules` (ventana de validez, primera compra, alcance, «requiere sesión», cupón
    público), con `coupons/used-up` y `public-coupons` en el BFF;
  - `hwe-guest-order-badge`;
  - `hwe-order-tracking` (transportadora/guía);
  - `hwe-best-sellers` (endpoint interno);
  - `hwe-contact` (formularios vía BFF).
- **Consentimiento**: `hwe-consent-log` + `ConsentStats` (registro legal de consentimientos
  separado de la medición anónima).
- **Correo**:
  - `hwe-email-chrome` + plantillas Woo en el tema, que sustituyen al
    `woocommerce-email-branding.php` del framework (Siu lo borró);
  - `hwe-email-test` (probar cualquier plantilla);
  - fixes: enlaces al frontend y no a WP (`62577135`), cron sin `localhost` (`275b3fbd`),
    plantillas en español sin paquete de idioma (`60a961ad`), no enviar «Login Details» al
    registrarse (`a8e5c046`), correos de auth en el idioma del visitante (`3dfcd630`).
- **Higiene de contenido y admin**: `hwe-space-hygiene`, `hwe-link-hygiene`,
  `hwe-media-reference`, `hwe-admin-product-image`.
- **Plugin `hwe-banners`**: la versión de Siu es la evolucionada. Añade filtros
  `banner_types`, `locations`, `slide_types`, `field_hints`, `size_guide` y
  `mandala_presets`; `MANUAL.md`, página de ayuda y guía de tamaños. El framework tiene la
  variante con `Placements.php` y menos hooks. **Reconciliar hacia la de Siu.**

### Frontend (`lib/`, `app/api/`)
- **Auth**:
  - `pending-login`, `two-factor-pending`, QR en servidor (`qrcode`), `email-verified`,
    `email-links`;
  - registro/login por teléfono (`auth/phone.ts`, `username.ts`), como funcionalidad opcional;
  - fixes de sesión tras el login (`d26551b9`).
- **Checkout y carrito**:
  - compra como invitado configurable (`af020cd9`) y reclamo del pedido de invitado
    (`guest-order-claim`, `-cookie`);
  - `order-access`, `return-url`, `customer-seed`;
  - `cart-session` y `cart-stock`; descartar respuestas obsoletas de mutaciones concurrentes
    (`ce6b959f`);
  - evitar el doble envío y fijar una idempotency-key estable por intento (`6206a546`);
  - el pedido se bloquea si Woo no ofrece tarifa de envío (`b092857b`);
  - "gracias" espera la confirmación de la pasarela (`afc815c3`).
- **Cuenta**: volver a pedir (`reorder`), reseña del pedido, `order-status`, `order-payable`,
  `order-support`, elegibilidad de reseñas.
- **Pagos**:
  - credenciales y pasarela elegidas desde el Control Center (`payments/credentials.ts`, caché
    en memoria y nunca en la Data Cache);
  - sandbox deducido de las credenciales;
  - `checkout-options` (varios medios de pago independientes: pasarela, efectivo, link por
    WhatsApp);
  - **Wompi implementado de extremo a extremo** (en el framework es un stub);
  - **AvalPay/PlacetoPay** de extremo a extremo.
- **Analítica**: GA4/GTM/Meta Pixel + Conversions API/TikTok/HubSpot vía
  `config.integrations` (no env vars), eventos con la forma de GA4, cola hasta el
  consentimiento (`setTrackingReady`), `consent-ledger`, `consent-stats`, `/api/consent*`.
- **SEO/GEO** (`lib/seo/`):
  - JSON-LD puro y centralizado (`jsonld.ts`, `breadcrumbs.ts`, `safe-json-ld.ts`);
  - `opengraph.ts` (Next *reemplaza* `openGraph`: gotcha documentado), `meta-description`,
    `title`;
  - `sitemap-segments`;
  - `robots.txt` con `Content-Signal` y separación de crawlers de IA; `/llms.txt` por idioma;
  - `noindex` sistemático en vistas privadas y filtradas.
- **Utilidades**: `http/fetch-retry`, `api/wc-message` (errores de Woo como texto plano),
  `navigation/safe-return`, `security/internal-secret`, `wordpress/media` + `CmsImage`,
  `youtube`, `validation/{email,form,checkout-guard}`.
- **Blog**: categorías con página propia, archivo completo enlazado, scroll infinito con
  cursor (`/api/blog/posts`), tags/autor clicables.
- **Rendimiento y build**:
  - `prerender-budget.ts` + `scripts/static-generation-options.mjs` (catálogos grandes en VPS
    pequeños);
  - filtrado de mensajes i18n por namespace (`11a1d30b`);
  - `warm-cache.sh`, `purge-old-build-pages.sh`.
- **Tooling**:
  - `scripts/check-i18n-parity.mjs` (`npm run i18n:check`: claves, forma, args, etiquetas y
    handlers de `t.rich`);
  - **regla ESLint de frontera núcleo/instancia** (`eslint.config.mjs`, `no-restricted-imports`);
  - `eslint-plugin-jsx-a11y`; Testing Library + jsdom; tests colocados junto al código.
- **Infra**:
  - servicio `wp-cron`; panel en `admin.<dominio>`; Caddy sirve los estáticos;
  - tuning de MariaDB; build del frontend que alcanza WP; arreglos del CD (cache de capas, no
    construir el stage dev);
  - frontend sin 502 durante el deploy (`aeb8e585`); `docker compose config` en CI.

> **Ojo con `patch-next-isr-headers.mjs` y `patch-next-image-lru.mjs`**: parchean
> `node_modules` de Next 16.3. Son genéricos, pero frágiles y dependen de la versión. Al
> portarlos, que comprueben la versión de Next y fallen ruidosamente si no coincide, o
> sustitúyelos si una versión posterior de Next ya corrige el problema.

---

## 4. Nivel 2 — Útil pero con supuestos de Siu: adaptar antes de portar

| Pieza | Supuesto de Siu | Adaptación a plantilla |
|---|---|---|
| Traducción de contenido (`hwe-content-translation`, `-slug-`, `-category-`, `-tag-`, `-blog-category-translation`; `lib/i18n/{pick-localized,slug-resolution,url-slug}`) | Escrito para **un único segundo idioma fijo, `_en`** (`titleEn`, `_hwe_slug_en`…) | Generalizar a N locales: meta `_hwe_<campo>_<locale>` y campos GraphQL por locale (o `translations { locale … }`), derivados de `routing.locales`. Es la pieza genérica más valiosa y la que más hay que tocar. |
| `hwe-shipping` (tarifa por ciudad, extras aditivos, envío gratis por regla) | Comentarios y fallback de la «zona Colombia»; coincidencia por nombre de ciudad | Ya está casi genérico. Hacer configurables el país base y la zona de fallback; documentar que la clave es la ciudad. |
| `geo/colombia-{cities,states}`, `shipping/destination`, `validation/address` | Departamentos y ciudades de Colombia | **"Country packs"**: `lib/geo/<ISO>/` seleccionado por `config.ecommerce.country`; Colombia como primer pack incluido. |
| `hwe-customer-identity` (+ `checkout/identity`, `validation/identity`) | Tipos de documento colombianos (CC/NIT/CE…), «se pide para facturar» | Tipos de documento por country pack y activable por flag (`ecommerce.identity_document_enabled`). Mecanismo (único entre cuentas, escritura única, enmascarado en correos, vía `extensions` de la Store API) genérico. |
| `hwe-product-landing` | `Fields::ICONS` ligado 1:1 a `quality-icons.tsx` de Siu; molde de 8 secciones pensado para fórmulas | Iconos vía filtro (`hwe_product_landing_icons`) más un set neutro por defecto; secciones activables. El molde puede seguir siendo el genérico. |
| Landings editoriales (`Instance/SiuEducationalLandings.php`, 1.428 líneas; `lib/landings/*`) | Motor y contenido mezclados: registro, slots, URL configurable (`base`+`path`), secciones únicas por código, `consumesBase`, tonos de banda, guardas de URL reservadas… junto a 4 landings estáticas de Siu | **Extraer el motor** a un plugin `hwe-landings` (registro desde el panel, slots fijos, URL configurable, `getEditorialHref`, `RESERVED_ROOT_SEGMENTS`) y dejar en la instancia solo `STATIC_EDITORIAL_ENTRIES` y las secciones únicas. |
| Integración Atlas (`hwe-atlas-access`, `hwe-atlas-graphql-guard`, rama de IPs en `hwe-bff-only`, `trim-atlas-schema.py`, `verify-atlas-integration.sh`) | Nombre y operaciones del integrador DALA | Generalizar a **"integración externa"**: rol `hwe_integration`, Application Password ligada a una IP, allowlist de operaciones y de claves `metaData` declaradas por config o filtro. Atlas pasa a ser la configuración de la instancia. |
| `hwe-order-item-details` | Esencias del quiz y modalidad de los cursos | Extraer el mecanismo genérico («datos de extensión de `cart/add-item` → meta visible de la línea», validado por un callback) como filtro `hwe_order_item_details`; esencias y cursos se quedan en la instancia. |
| `hwe-product-dosage`, `hwe-product-line-card`, `hwe-essence-copy` | Campos de la ficha propios del negocio | El patrón «meta de producto con par `_en` expuesto por GraphQL» se repite unas 6 veces. Generalizar a un **registro declarativo de campos de producto** (`hwe_product_fields`: clave, tipo, traducible, GraphQL) y dejar las definiciones en la instancia. |
| `hwe-glb-uploads` + visor 3D | Un frasco compartido + etiqueta por producto | Instancia. Como mucho, un módulo opcional futuro; no es prioritario. |
| Precio y moneda (`formatStoreAmount(amount, currencyCode)`, `useCurrency`, `079a7801`) | — | Genérico; implica cambiar firmas en el núcleo. Portar junto con el fix de `deepMerge`. |

---

## 5. Nivel 3 — Solo de la instancia: se queda en Siu

- `Instance/Siu*` (MegaMenu, BannerTypes/Mandalas/Placements, Newsletter, Features —quiz y
  emociones—, WebsiteImages, PageCategories, Mascotas, Sheilak, Cursos, LineCards).
- Dominio en `lib/`:
  - catálogo: `catalog/siu-*`, `sheilak-*`, `mascota-format`, `bach-guide*`,
    `discover-essence*`, `cursos`, `lineas`, `product-linea`, `drop-dosage`,
    `bottle-volume`;
  - landings y media: `landings/dormilab-*`, `consulta-contact`, `media/model3d-*`.
- `components/siu/**` y las vistas de `app/[locale]/**`.
- Seeds `seed-*-siu.sh`, `go-live-siu.sh`, migración v3, `legal/*.en.html`, redirecciones del
  dominio heredado.

Sirven como **casos de prueba del mecanismo de instancia** (§6): si el mecanismo nuevo no
puede alojar todo esto sin tocar el núcleo, está mal diseñado.

---

## 6. Mecanismo de "funcionalidades de instancia"

### 6.1 Cómo lo hizo Siu y dónde se rompe

Siu creó `hwe-control-center/Instance/*.php`, que se enganchan con
`add_filter('hwe_control_center_schema', …)` y con los filtros de `hwe-banners`. La idea es
buena (la instancia **añade** schema por filtros), pero tiene seis fugas:

1. **El registro está en un archivo del núcleo.** `hwe-control-center.php` lista a mano
   `Instance\SiuMegaMenu::register()` … `Instance\SiuLineCards::register()`. Cada
   actualización del framework choca con ese archivo.
2. **`AdminPage::handleSave()` conoce la instancia.** Lee del POST `hwe_essence_copy`,
   `hwe_mascota_fields`, `hwe_sheilak_fields`, `hwe_curso_fields`, `hwe_line_cards` y
   `landing_pages.product.published`, y dispara 6 acciones con nombres de Siu (unas 25
   referencias a Siu en ese archivo).
3. **El Walker lleva nombres de Siu** en comentarios, clases CSS (`hwe-essence-item`) y en la
   semántica de `item_meta_fields`. Se intentó limpiar en `515fe429`, pero no se completó (21
   referencias).
4. **`Instance/` vive dentro del directorio del núcleo** del Control Center: no se puede
   sustituir el núcleo sin arrastrar la instancia.
5. **Los mu-plugins de instancia conviven con los del núcleo** sin distinción
   (`hwe-essence-copy`, `hwe-atlas-*`, `hwe-order-item-details`…).
6. **En el frontend la regla es la contraria a la deseable.** Siu documenta «la lógica de
   dominio Siu va en `lib/`», así que `lib/` (núcleo) contiene decenas de módulos con dominio
   de Siu (`catalog/siu-*`, `sheilak-*`, `mascota-*`, `landings/dormilab-*`…), y `lib/config/types.ts` declara `siu_features`, `mascotas`, `sheilak`, `cursos`… La
   regla ESLint solo impide que el núcleo importe `components/`, no que importe dominio de
   instancia.

### 6.2 Propuesta (a validar contigo antes de implementar)

**Principio:** el núcleo expone puntos de extensión con nombres genéricos; la instancia vive
en rutas que una actualización del framework **nunca toca**; el núcleo funciona igual con la
carpeta de instancia vacía.

**Backend**
- Carpeta reservada `backend/wp-content/mu-plugins/instance/` (el framework la publica
  vacía, con un `README.md` y un ejemplo). La carga un único loader del núcleo,
  `hwe-instance-loader.php`, que hace `require` de `instance/*.php` en orden alfabético. Las
  piezas grandes pueden ser **plugins regulares** `hwe-instance-<slug>`, activables, como ya
  hacen banners/shipping/landing.
- Contrato de hooks del núcleo, versionado y documentado en `docs/PLUGIN_DEVELOPMENT.md`:
  - `hwe_control_center_schema` (filtro, ya existe en Siu);
  - `hwe_control_center_field_types` (filtro: tipos de campo con su render y su saneado), para
    que el Walker no conozca `item_meta_fields` de esencias;
  - `hwe_control_center_saved( array $publicData, array $rawPost )`: **una** acción genérica
    que sustituye a las 6 con nombre de Siu (cada módulo lee su propia clave del POST);
  - `hwe_revalidation_tags` (filtro de `ALLOWED_HWE_TAGS`), `hwe_bff_only_routes`,
    `hwe_integration_operations` y `hwe_order_item_details`;
  - los de plugins, que ya existen: `hwe_banners_*`, `hwe_shipping_free_rule`.
- Config de instancia **bajo una sola clave raíz `instance.*`** del schema (no `siu_features`
  suelto entre `ecommerce` y `seo`). `PublicConfigWalker` la expone igual y el núcleo no
  necesita conocer su forma.
- **Módulos opcionales del framework** (activables desde `instance.config.json` →
  `modules: [...]` y desde `wp hwe setup`): banners, shipping, product-landing, landings,
  consent-log, site-reviews, coupon-rules, customer-identity, external-integration. Que los
  flags `config.*.enabled` los gateen en el BFF y no solo en la UI (Siu lo aprendió con los
  cupones: `755a75cc`).

**Frontend**
- Carpeta `frontend/src/instance/`:
  - `lib/`: dominio de la instancia;
  - `config.d.ts`: *module augmentation* de `SiteConfig["instance"]`;
  - `registry.ts`: secciones únicas, iconos, etc.
- Ampliar la regla ESLint: `src/lib/**`, `src/app/api/**` y `src/proxy.ts` **no pueden
  importar `@/instance/**`**. La instancia sí puede importar el núcleo.
- `lib/config/types.ts` declara `instance: unknown` (o una interfaz vacía extensible). Siu lo
  aumenta con sus tipos.
- Revertir la regla documentada en Siu: el dominio de la instancia va en `src/instance/lib/`,
  no en `lib/`.

**Criterio de aceptación:** se puede actualizar `hwe-control-center/`, `lib/`, `app/api/` y
los plugins del framework en Siu **sin conflictos**, y todo lo de §5 sigue funcionando.

---

## 7. Orden de trabajo recomendado

1. **Parche de seguridad v1.0.x**, todo §2, sin cambiar la arquitectura. Cada punto con un
   test de regresión (Siu ya tiene la mayoría: `csrf.test.ts`, `idempotency.test.ts`,
   `proxy-origin.test.ts`, `remote.test.ts`, `2fa/qr/route.test.ts`,
   `change-password/route.test.ts`, `secret-guard-origin.test.ts`). Es lo urgente y lo menos
   discutible.
2. **Contrato de instancia (§6)**: loader, hooks genéricos, clave `instance.*`,
   `src/instance/`, regla ESLint y documentación. Hay que hacerlo **antes** de portar §3–§4,
   o lo portado nacerá acoplado.
3. **Infraestructura del Control Center** (tipos de campo, APIs internas, syncs con Woo,
   backups). Muchas piezas de §3 dependen de ella.
4. **Nivel 1 por dominios**, en este orden por dependencia: auth → pagos → checkout/carrito →
   cuenta → analítica/consentimiento → SEO/GEO → blog → correo → utilidades → infra/CD.
5. **Nivel 2**: i18n multi-locale de contenido, country packs, motor de landings, integración
   externa y campos de producto declarativos.
6. **Migrar Siu al contrato nuevo** como prueba de fuego: mover `Instance/` y el dominio de
   `lib/` a las rutas de instancia y comprobar que una actualización del núcleo no da
   conflictos.

Siu está **por delante** del framework, así que cuando termine el paso 6 la sincronización
pasa a ser framework → Siu (como estaba previsto), y no al revés.
