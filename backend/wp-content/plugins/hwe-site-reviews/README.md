# HWE Site Reviews (módulo `site-reviews`)

Reseñas del **sitio**: la calificación (1–5 estrellas + texto) que un cliente da a un pedido.
No son las reseñas de producto de WooCommerce. Una por pedido, solo de un pedido **completado**,
solo de su dueño, sin moderación (la compra es el filtro).

## Qué hace

| Pieza | Detalle |
|---|---|
| CPT `hwe_site_review` | WooCommerce → «Reseñas del sitio». Lo que lee el equipo: pedido, cliente y nota. No se crea desde el panel. |
| Order meta `_hwe_site_review` | Espejo `{ id, rating, content, date }` en el pedido. Llega en el `meta_data` de wc/v3 con el pedido: la cuenta sabe si ya se calificó sin otra petición. |
| `POST /wp-json/hwe/v1/site-reviews` | Crea la reseña. Solo con el secreto interno (`X-HWE-Internal-Secret`). El BFF la llama desde `POST /api/store/orders/[id]/review`. |
| Papelera | Mandar una reseña a la papelera retira el espejo (el cliente puede volver a calificar); restaurarla lo repone. |
| Tag ISR `site-reviews` | Se registra en `hwe_revalidation_tags` y se revalida al publicar o cambiar de estado una reseña. |

## Reglas (las comprueba WordPress, no solo el BFF)

- El pedido es de ese usuario (uno ajeno responde igual que uno inexistente: 404).
- Solo estados que admiten reseña: `completed`. Debe coincidir con `canRateOrder`
  (`frontend/src/lib/account/order-review.ts`).
- Texto de 10 a 3000 caracteres; nota entera de 1 a 5.
- Un cerrojo por pedido (`add_option`) impide dos reseñas por un doble envío; uno huérfano
  caduca al minuto.

## Contrato con el frontend

- Módulo `site-reviews` en `config.modules` (activo = plugin activo). Con el módulo apagado,
  `POST /api/store/orders/[id]/review` responde 404 y la vista no pinta «Calificar pedido».
- Las reseñas de **producto** de ese pedido (opcionales, mismo texto) las crea el BFF por wc/v3
  después de esta; no son cosa del plugin.

## Pruebas

`backend/tests/Unit/SiteReviews/SiteReviewsTest.php` (PHPUnit + Brain Monkey).
