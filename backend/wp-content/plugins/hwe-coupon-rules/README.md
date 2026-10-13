# HWE Coupon Rules (módulo `coupon-rules`)

Reglas de cupón que WooCommerce no trae, y el canal para anunciar los cupones públicos en el
frontend. Un cupón se edita en **Marketing → Cupones**, cuya pantalla se reorganiza por tema.

## Reglas

| Regla | Meta | Qué hace |
|---|---|---|
| Ventana de validez | `_hwe_coupon_starts` | Fecha de inicio (el core solo tiene caducidad). La caducidad nativa pasa a ser **inclusiva**: «caduca el 27» vale todo el 27. Afecta a **todos** los cupones del sitio. |
| Primera compra | `_hwe_first_order_only`, `_hwe_first_order_since` | Solo clientes sin pedidos pagados (por cuenta y por correo de facturación) desde la fecha de corte. Exige sesión. |
| Requiere sesión | `_hwe_coupon_requires_login` | Solo clientes con cuenta. |
| Alcance | (derivado) | Todo el catálogo, unos productos o unas categorías, nunca productos y categorías a la vez. |
| Visibilidad | `_hwe_coupon_public` | Un cupón público se anuncia en el frontend; uno privado solo funciona escribiendo el código. |

El rechazo de cada regla llega al cliente con su motivo real (no el genérico «Cupón no válido»).

## Endpoints internos (solo con `X-HWE-Internal-Secret`)

- `GET /wp-json/hwe/v1/coupons/public` → `{ items: [...] }`: cupones públicos vigentes o a punto
  de empezar (margen de 3 h por el ISR del frontend), con el alcance por **slug**. No se
  publican los agotados, los caducados ni los que dependen de quién compra (correos permitidos,
  marcas).
- `GET /wp-json/hwe/v1/coupons/public/used-up?user_id=N` → `{ codes: [...] }`: los públicos que
  ese cliente ya no puede usar (límite por usuario, primera compra hecha, pedido pendiente con
  el cupón). El BFF toma el `user_id` de la sesión verificada, nunca del navegador.

## Revalidación

Tag ISR `coupons` (registrado en `hwe_revalidation_tags`): al guardar, publicar, papelera o
restaurar un cupón, y al **cruzar** el límite total de usos (agotarse o liberarse). Las fechas no
tienen evento: las resuelve el navegador con la ventana del cupón.

## Pruebas

`backend/tests/Unit/CouponRules/CouponRulesTest.php` (PHPUnit + Brain Monkey).
