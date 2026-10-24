# Desarrollar un plugin del framework (mu-plugin + Control Center)

> Receta para añadir **lógica de negocio reutilizable** al backend headless y exponerla al
> contrato del frontend. Antes de empezar: `AGENTS.md` (mapa del proyecto) y
> `backend/wp-content/mu-plugins/README.md` (convención de carga de mu-plugins).
>
> **Criterio de admisión:** solo pertenece al framework si aporta valor reutilizable para
> múltiples proyectos. Lo específico de un cliente vive en su instancia, no aquí.

## 0. Contrato núcleo ↔ instancia (dónde vive tu código)

El framework es **backend + arquitectura**; la UI y el dominio de cada tienda son de la
instancia (`AGENTS.md §1.1`). El código de una instancia vive en rutas RESERVADAS que el
framework nunca toca, y se engancha al núcleo por hooks estables, versionados por
`HWE_CORE_CONTRACT_VERSION` (hoy `2.1.0`):

| Lado | Ruta de instancia | Qué va ahí |
|---|---|---|
| Backend | `backend/wp-content/mu-plugins/instance/*.php` | Campos del Control Center, tipos de campo, tags ISR. Los carga `hwe-instance-loader.php` en orden alfabético (ver su `README.md`). |
| Backend (grande) | `backend/wp-content/plugins/hwe-instance-<slug>/` | Plugins enteros (CPT, tablas propias). Patrón preparado en el `.gitignore`. |
| Frontend | `frontend/src/instance/` | `registry.ts` (datos del `InstanceRegistry`), `config.d.ts` (ampliación de `InstanceConfig`), `lib/` (tu dominio). |
| Frontend | `frontend/src/components/**` (salvo `ui/`) y `frontend/src/app/[locale]/**` | Tu UI y tus vistas. |

**Hooks estables del backend:**

| Hook | Tipo | Para qué |
|---|---|---|
| `hwe_control_center_schema` | filtro | Añadir campos, solo dentro del grupo `instance`. Una clave raíz nueva se descarta y un grupo del núcleo eliminado se restaura (ambos se registran). |
| `hwe_control_center_field_types` | filtro | Registrar tipos de campo propios (desde la tarea 26). |
| `hwe_control_center_saved` | acción | Reaccionar al guardado: `($publicData, $rawPost)`. Sustituye a las acciones con nombre de instancia; `AdminPage` ya no lee claves del POST fuera del schema. |
| `hwe_revalidation_tags` | filtro | Declarar tags ISR propios (el frontend debe declararlos también en `registry.ts`). |
| `hwe_geo_packs` | filtro | Añadir o sustituir paquetes por país (divisiones, ciudades, documentos, prefijo); ver `docs/FRONTEND_CONNECT.md` «Paquetes por país». |
| `hwe_modules_catalog` | filtro | Añadir módulos propios (id → slug de plugin). |
| `hwe_landings_static_entries` | filtro | Declarar las landings con **diseño propio** de la instancia (`id => ['label', 'jsonld', 'base']`): su contenido se edita en el panel del módulo `landings`. Deben coincidir con `instanceRegistry.landings.staticEntries`. |
| `hwe_product_fields` | filtro | Declarar campos de producto (`clave => ['label', 'type', 'translatable', 'box', 'description', 'options', 'max']`): el núcleo pinta la caja en la ficha, sanea y expone `Product.hweFields(locale)`. El frontend los declara en `instanceRegistry.productFields`. |
| `hwe_order_item_details` | filtro | Declarar detalles de línea de pedido (`clave => ['label', 'validate', 'applies_to', 'required', 'meta_key']`): el núcleo los toma de `extensions` en `cart/add-item`, los valida en el servidor, impide pagar una línea sin ellos y los guarda como meta **visible** de la línea (correos, wp-admin, API). El validador devuelve el valor, `['value', 'data']` o un `WP_Error`. |
| `hwe_product_landing_icons` | filtro | Ampliar los íconos del selector de la landing de producto (clave en minúsculas → etiqueta); la instancia declara los mismos dibujos en su frontend. |
| `hwe_control_center_public_config` | filtro | `( array $public ): array`. Añade campos **públicos** (nunca secretos) a `GET /wp-json/hwe/v1/config`, p. ej. `shipping.distance_enabled` del módulo `shipping`. Lo aplica `RestApi` del Control Center justo antes de responder. |

**Grupos reservados a un módulo:** el núcleo declara vacíos los grupos raíz `shipping`,
`landings`, `loyalty` y `memberships`; el plugin del módulo los puebla con
`hwe_control_center_schema` (no van en `instance`: una clave raíz ajena al núcleo se descarta).

**Módulos opcionales:** la instancia los elige en `instance.config.json` (`modules:
[<id>…]`); `wp hwe setup` activa los listados y desactiva los demás del catálogo
(`banners`, `shipping`, `product-landing`, `landings`, `consent-log`, `site-reviews`,
`coupon-rules`, `customer-identity`, `external-integration`, `loyalty`, `memberships`). La config pública expone
`modules: { <id>: boolean }` según si cada plugin está activo: es la fuente de verdad que
el frontend usa con `isModuleEnabled(config, id)` y `requireModule(id)` (las rutas BFF de
un módulo apagado responden 404).

### Hooks estables de los módulos

Un módulo que quiera ser contrato publica sus hooks en su `README.md` y aquí. Los del módulo
`loyalty` (fidelización), ya estables:

| Hook | Tipo | Firma / para qué |
|---|---|---|
| `hwe_loyalty_credited` | acción | `( int $userId, int $points, string $type, array $ref )` tras un abono; `$ref` = `['type', 'id']`. |
| `hwe_loyalty_debited` | acción | `( int $userId, int $points, string $type, array $ref )` tras un cargo. |
| `hwe_loyalty_expired` | acción | `( int $userId, int $points, string $type, array $ref )` tras caducar un abono (`$ref = ['type' => 'entry', 'id' => …]`). |
| `hwe_loyalty_transferred` | acción | `( int $fromUserId, int $toUserId, int $points, int $received, int $fee )`. |
| `hwe_loyalty_attribution_confirmed` | acción | `( int $userId, ?int $referrerId )` la primera vez que una atribución pasa a confirmada. |
| `hwe_loyalty_order_earnings` | filtro | `( array $earnings, WC_Order $order, float $base, array $settings ): array` para reglas propias de puntos por compra. |
| `hwe_loyalty_collected_on_completion` | filtro | `( string[] $methods ): string[]` medios que esperan a `completed` (defecto `cod`). |
| `hwe_loyalty_entry_types` | filtro | `( array<string, "credit"\|"debit"> $types ): array` tipos de movimiento extra. |
| `hwe_loyalty_participant_roles` | filtro | `( string[] $roles ): string[]` roles que participan. |

**Funciones públicas de `loyalty`:** además de los hooks, el módulo publica cuatro funciones
globales (en `includes/Api.php`, cargadas con el plugin) para que otro módulo abone o revierta
puntos —o lea el referidor confirmado— sin conocer sus clases. Quien las usa comprueba
`function_exists()` y, después, la función concreta con `hwe_loyalty_available()`:

| Función | Firma | Para qué |
|---|---|---|
| `hwe_loyalty_available` | `( string $feature ): bool` | `points` exige el módulo activo y «Puntos» encendido; `referrals`, además, «Referidos». Un valor desconocido devuelve `false`. |
| `hwe_loyalty_credit` | `( int $userId, int $points, string $type, string $key, array $ref = [], array $data = [] ): ?int` | Abono por el libro con idempotencia por `$key`, referencia tipada y nota estructurada. `null` sin escribir si los puntos están apagados, el usuario no participa, el tipo no está declarado como abono o la clave ya existía. |
| `hwe_loyalty_reverse_credit` | `( string $key, string $reversalKey ): ?int` | Revierte el abono `$key` con un `earn_reversal` limitado al saldo e idempotente por `$reversalKey`. `null` si la clave original no existe o no es un abono, si no queda saldo o si ya se revirtió. |
| `hwe_loyalty_referrer_of` | `( int $userId ): ?int` | Referidor N1 **confirmado** del usuario; `null` si no hay atribución, sigue pendiente, el referidor ya no existe o el plugin no está cargado. Solo el id numérico. Los módulos que pagan por la red deben resolverlo en cada cálculo con esta función, nunca con una copia cacheada. |

El módulo que abona declara su tipo de movimiento con `hwe_loyalty_entry_types` (`'credit'`);
sin declararlo, el abono se rechaza. Revertir no exige que «Puntos» siga encendido: es una
corrección contable. Contrato completo en el `README.md` de `hwe-loyalty` («Hooks estables →
Funciones públicas»). El primer consumidor es `memberships` (puntos al activar, comisión extra de
referidos y reversión al revocar una compra); no llama a ninguna clase interna de `loyalty`.

### Hooks estables del módulo `memberships`

Publicados también en `backend/wp-content/plugins/hwe-memberships/README.md` («Hooks estables»).
Los eventos del ciclo (`hwe_membership_*`) se disparan **después del COMMIT** y dejan fila en
`hwe_memberships_events`; sus argumentos son siempre ids numéricos, nunca datos personales:

| Hook | Tipo | Firma / para qué |
|---|---|---|
| `hwe_membership_activated`, `_renewed`, `_upgraded`, `_deferred`, `_granted_by_admin`, `_referral_gift_granted`, `_revoked` | acción | `( int $userId, int $grantId, int $levelId )`. |
| `hwe_membership_removed_by_admin` | acción | `( int $userId, 0, int $oldLevelId )`. |
| `hwe_membership_expired` | acción | `( int $userId, int $grantId, int $levelId )`. |
| `hwe_membership_expiring` | acción | `( int $userId, int $grantId, int $levelId, int $days )`. |
| `hwe_membership_frozen` / `_unfrozen` | acción | `( int $userId, int $actorId )`. |
| `hwe_membership_level_changed` | acción | `( int $userId, int $oldLevelId, int $newLevelId )` (0 = sin nivel). |
| `hwe_membership_referral_gift_limit_reached` | acción | `( int $referrerId, int $grantId, int $levelId )`; el `data` lleva el referido, el nivel regalado, el tope y los usados. |
| `hwe_membership_points_skipped` | acción | `( int $userId, int $grantId, int $levelId )`; el `data` lleva `points`, `reason` y la clave. |
| `hwe_memberships_benefit_types` | filtro | `( array<string,HWE_Memberships_Benefit> $types ): array`. Añade tipos de beneficio de instancia (ver abajo). |
| `hwe_memberships_product_requirement` | filtro | `( array $requirement, int $productId ): array`. Recibe `min_rank`, `level_*`, `opens_at` y `blocked`; **solo puede endurecer** (un rango menor, una fecha anterior o quitar la fecha se ignora y se registra). |
| `hwe_memberships_grant_origins` | filtro | `( string[] $origins ): string[]`. Añade orígenes a `order`, `admin`, `bulk` y `referral_gift`. |
| `hwe_memberships_participant_roles` | filtro | `( string[] $roles ): string[]`. Roles que participan (defecto `customer`, `subscriber`). |
| `hwe_memberships_store_api_cart_data` | filtro | `( array $data, array $context ): array`, con `$context` = `cart`, `user_id` y `state`. Aporta ramas a `extensions.hwe-memberships`; `level`, `blocked_items` y `purchase_notice` se recalculan después. |
| `hwe_memberships_order_box_rows` | filtro | `( array $rows, WC_Order $order ): array`. Añade filas a la caja del pedido. |
| `hwe_memberships_collected_on_completion` | filtro | `( string[] $methods ): string[]`. Medios que esperan a `completed` (defecto `cod`). |

Consume los contratos de `loyalty` (`hwe_loyalty_available`, `hwe_loyalty_credit`,
`hwe_loyalty_reverse_credit`, `hwe_loyalty_referrer_of`, `hwe_loyalty_entry_types`,
`hwe_loyalty_order_earnings`, `hwe_loyalty_attribution_confirmed`) y de `shipping`
(`hwe_shipping_free_reason`, constante `HWE_SHIPPING_FREE_REASON_FILTER`), más
`hwe_email_verified`, `hwe_control_center_schema`, `hwe_translatable_fields` y
`hwe_revalidation_tags`. **Membresías nunca llama a clases internas de otro módulo**: solo a lo
que su README publica como estable.

#### Tipos de beneficio de instancia

Una instancia amplía los beneficios sin tocar el núcleo: declara una clase que extienda
`HWE_Memberships_Benefit` (clave estable, campos por nivel, dependencia opcional, datos públicos y
hooks) y la registra con `hwe_memberships_benefit_types`. El panel, `wp hwe setup`, la API y el
historial la tratan como las de serie; lo único que **no** puedes hacer es pisar una clave del
núcleo o una reservada (`member_discount`, `early_access`) ni devolver un mapa que no sea
`clave → objeto`. Un tipo que no se puede instanciar, que no extiende la base o cuya clave no
coincide con la del mapa se descarta y se registra.

Ejemplo vivo y completo (campos `orders` y `note`, hook en el cobro del pedido, nota privada
idempotente): `examples/instance-minimal/backend/wp-content/mu-plugins/instance/70-example-membership-benefit.php`.
Recuerda que se carga como mu-plugin **antes** de los plugins: declara la clase dentro del callback
del filtro, cuando `HWE_Memberships_Benefit` ya existe.

El módulo `loyalty` consume además tres hooks del núcleo o de otros módulos, que también son
contrato:

- `hwe_email_verified( int $userId )` — lo dispara `hwe-auth` una vez al verificar el email. Una
  cuenta creada en wp-admin se considera confirmada sin pasar por él.
- `hwe_account_approved( int $userId )` y el filtro `hwe_account_is_pending( bool $pending, int
  $userId )` — contrato **provisional** con el futuro módulo de «login privado»; hoy el filtro
  devuelve `false` por defecto y el módulo de fidelización solo los consume.
- `hwe_site_review_published( int $reviewId, int $userId, int $orderId )` — lo dispara
  `hwe-site-reviews` cuando una reseña con autor pasa a publicada.

### Hooks estables del módulo `shipping`

Publicados también en `backend/wp-content/plugins/hwe-shipping/README.md` («Hooks estables»):

| Hook | Tipo | Firma / para qué |
|---|---|---|
| `hwe_shipping_free_rule` | filtro | `( array $rule ): array` con `['enabled' => bool, 'min_amount' => float, 'categories' => slug[], 'products' => slug[]]`. La entrega la Control Center → Tienda → «Envío gratis»; el plugin no guarda la regla. |
| `hwe_shipping_free_reason` | filtro | `( ?string $reason, array $package, array $context ): ?string`. Otro módulo pide el envío gratis por un motivo propio; `shipping` lo aplica con su lógica (solo opciones elegibles, nunca la reserva) y lo guarda en `_hwe_free_shipping_reason`. `$context` = `qualifies` (`bool`), `mode` (`fixed` \| `distance` \| `reserve` \| `none`) y `rate_ids` (`string[]`, ids elegibles del destino; vacío si no hay). Un valor que no sea texto no vacío se descarta y se registra. **La regla de la tienda manda**: si ya califica, el filtro no se consulta. La constante `HWE_SHIPPING_FREE_REASON_FILTER` avisa de que esta versión lo admite. |
| `hwe_shipping_distance_providers` | filtro | `( array<string,Provider> $providers ): array`. Añade o sustituye proveedores de distancia y geocodificación por id. Cada valor debe implementar `HWE\Shipping\Distance\Provider` (`id()`, `distance()`, `geocode()`, `reverse()`); los que no lo hagan se descartan y se registran. Los proveedores del núcleo son `google_maps`, `openrouteservice` y `straight_line`. |
| `hwe_shipping_rate_cost` | filtro | `( float $cost, array $context ): float`, con `$context` = `city`, `option_id`, `type` (`fixed` \| `distance` \| `reserve`) y `km` (`float` o `null`). Ajusta el coste de una tarifa antes del envío gratis y del cupón. Un valor no numérico, infinito o negativo **omite** la tarifa y se registra; no hay un coste por defecto. |

Ninguno de estos filtros recibe ni devuelve la clave de un proveedor: el proveedor solo recibe
puntos y devuelve resultados, y la clave la lee el propio proveedor de `Settings::mapsKey()`.

**La frontera también te protege a ti:** el núcleo (`src/lib/**`, `src/app/api/**`,
`src/proxy.ts`, `src/components/ui/**`) no puede importar `@/components/**` (salvo `ui/`)
ni `@/instance/**` (salvo `@/instance/registry`); lo impone ESLint (`import type`
incluido). `InstanceRegistry` son solo datos, nunca componentes.

**Vocabulario prohibido en el núcleo:** el gate `bash .github/scripts/check-anti-instance.sh`
falla si en cualquier archivo versionado (núcleo, starter, tests, scripts y documentación;
no `examples/` ni `docs/superpowers/`) aparece el vocabulario de una instancia conocida; la
lista está en el propio script. Cuando una instancia nueva empieza a trabajar sobre el
framework, su token se añade a ese patrón **antes** de escribir código (es la única aparición
permitida de su nombre, porque el script se excluye a sí mismo del escaneo) y
`CheckAntiInstanceTest` gana un caso con el token partido en dos cadenas para que el test no se
cace a sí mismo. Ojo al español corriente: algunos adjetivos y sustantivos comunes contienen
uno de esos tokens; el script dice cuál y en qué línea, y basta con usar un sinónimo. En tu
código de instancia puedes usar las palabras que quieras; en el framework, ninguna de esas.

**Ejemplo vivo:** `examples/instance-minimal/` replica las rutas de instancia con un campo,
un tag y una vista. El job «contrato» de CI lo superpone al repo y ejecuta `composer
test`, `npm run test`, `type-check` y `build`: si el contrato se rompe, ese job lo dice.

## 1. Decide la forma del plugin

| Necesitas | Forma |
|---|---|
| Hooks/filtros con un solo concern | mu-plugin simple: `mu-plugins/mi-cosa.php` en la raíz |
| Varias clases | loader `mu-plugins/mi-paquete.php` + carpeta `mu-plugins/mi-paquete/` |
| Configuración editable por el administrador | sección nueva en el **Schema del Control Center** (ver §2) |
| Datos de contenido con medioteca/editor | CPT propio expuesto por WPGraphQL (última opción; evalúa antes si un campo de config basta) |

Regla: si el dato es "configuración de la instancia" (textos, flags, importes, URLs), va al
Control Center — así hereda gratis la UI de admin, `wp hwe setup`, la API pública y la
revalidación ISR del frontend.

## 2. Añadir campos al Control Center

1. **`Schema.php`** (`backend/wp-content/mu-plugins/hwe-control-center/Schema.php`, con los
   grupos del núcleo por tema en `SchemaGroups/`) es la fuente única de verdad. Añade tu grupo/campos con los tipos disponibles (`text`, `textarea`, `color`,
   `url`, `email`, `select`, `boolean`, `secret`). Marca `'public' => true` SOLO si el frontend
   público debe verlo; los `secret` se cifran (AES-256-GCM) y jamás salen por la API.
2. La UI de admin (`wp-admin → HWE Config`), el comando `wp hwe setup` y el endpoint público
   `/wp-json/hwe/v1/config` se generan solos desde el Schema (walkers). No toques
   `AdminPage.php`/`RestApi.php` salvo que necesites un tipo de campo nuevo.
3. Actualiza `backend/scripts/instance.config.example.json` con los campos nuevos (los grupos
   deben coincidir con el Schema).
4. Al guardar en admin, el Control Center ya notifica al frontend (`Revalidation.php` → POST
   firmado a `/api/revalidate` → invalida el tag `site-config`). No hay nada que hacer.

### ¿Y si el dato es una lista?

El Schema no tiene (a propósito) un tipo "repeater": una lista corta se modela como `textarea`
con **una entrada por línea y campos separados por `|`**, más un parser puro en el frontend.
Ejemplo vivo: FAQ (`geo.faq` → `lib/seo/faq.ts`). Si tu lista necesita medioteca, orden drag-and-drop o decenas de
entradas, es señal de que toca un CPT, no un campo de config.

## 3. Leer la config desde PHP (lógica de negocio)

Patrón de `WooCurrencySync` (ejemplo canónico):

```php
$value = class_exists('\\HWE\\ControlCenter\\Storage')
    ? \HWE\ControlCenter\Storage::get(['mi_grupo', 'mi_campo'], $default)
    : $default;
```

Siempre con fallback: el plugin no debe fatal-ear si el Control Center no está.

## 4. Exponer el dato al frontend (contrato)

Para campos `public => true` no hay trabajo backend extra: ya salen en
`/wp-json/hwe/v1/config`. En el frontend del framework:

1. Añade el campo a `frontend/src/lib/config/types.ts` (interface `SiteConfig`).
2. Añade su default a `frontend/src/lib/config/defaults.ts` (DEBE coincidir con el `'default'`
   del Schema — es el fallback cuando WP no responde).
3. Si el dato necesita parseo (líneas, `|`…), crea un helper puro en `frontend/src/lib/…` con
   test unitario colocado junto al helper (patrón: `lib/seo/faq.ts` y su test).
4. Si la funcionalidad es opcional, gatea la UI con el flag (patrón
   `config.ecommerce.*_enabled`).

Para datos que van por GraphQL (no por config): documenta la query en
`docs/FRONTEND_CONNECT.md` Parte A y añade los tipos a `frontend/src/types/` (o a
`frontend/src/lib/<dominio>/` si son del núcleo, como `lib/navigation/types.ts`).

**Frontera núcleo/instancia:** el código de `lib/`, `app/api/`, `proxy.ts` y `components/ui/`
no puede importar de `components/**` (salvo `ui/`) ni de `@/instance/**` (salvo
`@/instance/registry`) — hay una regla de lint que lo impide. Si tu helper necesita un
tipo que hoy vive en un componente, define el tipo en `lib/` (estructuralmente compatible) e
invierte la dependencia, como hace `BannerSlide` respecto a `HeroSlide`.

## 5. Checklist antes de dar por terminado el plugin

- [ ] `php -l` limpio en todos los `.php` nuevos.
- [ ] Campos reflejados en: `Schema.php` ↔ `instance.config.example.json` ↔ `types.ts` ↔ `defaults.ts`.
- [ ] Ningún secreto con `public => true` ni con prefijo `NEXT_PUBLIC_`.
- [ ] Contrato documentado en `docs/FRONTEND_CONNECT.md` (Parte A).
- [ ] Test unitario del parseo/lógica frontend (si aplica).
- [ ] `npx next build` en verde (si tocaste frontend).
- [ ] Entrada en `CHANGELOG.md` (`[Sin publicar]`).
- [ ] Si el mu-plugin es nuevo: fila en la tabla de `backend/wp-content/mu-plugins/README.md`.
- [ ] Cabecera `Version:` del plugin: es la versión **del componente**, no la del framework.
      Un componente nuevo arranca en `1.0.0` y sube cuando cambia su comportamiento; por eso
      conviven `1.0.0`, `2.0.0` y `2.1.0` en un mismo release. La del framework está en
      `FRAMEWORK_VERSION`, `CHANGELOG.md` y `frontend/package.json`.
