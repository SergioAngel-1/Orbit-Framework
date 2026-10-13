# HWE Customer Identity (módulo `customer-identity`)

Documento de identidad del cliente (tipo + número) y registro de la aceptación de términos, en la
cuenta y en el pedido. El documento es **único entre cuentas** y de **escritura única** desde el
sitio.

## De dónde salen los tipos de documento

Del **paquete de país** de la tienda (mu-plugin `hwe-geo`, `ecommerce.country`): cada tipo trae su
código, su etiqueta y un patrón. Un país cuyo paquete no tiene tipos de documento (el genérico)
**no pide documento**: el módulo solo registra la aceptación de términos. Para otro país, añade
un paquete con el filtro `hwe_geo_packs` (ver `docs/FRONTEND_CONNECT.md`, «Paquetes por país»).

## Dónde vive cada dato

| Dato | Dónde | Notas |
|---|---|---|
| Documento de la cuenta | user meta `billing_document_type`, `billing_document` | Sale en el `meta_data` del cliente de wc/v3. Se guarda normalizado (sin espacios, puntos ni guiones, en mayúsculas). |
| Aceptación de términos de la cuenta | user meta `hwe_terms_accepted_at` | ISO 8601. Se registra una vez y no se reescribe. |
| Datos del pedido | order meta `_hwe_document_type`, `_hwe_document_number`, `_hwe_terms_accepted_at` | También en los pedidos de invitado. |

## Reglas (las comprueba WordPress, no solo el BFF)

- **Escritura única:** `attach-identity` solo escribe el documento si la cuenta no tiene uno. Con
  otro distinto responde `409 document_locked`; repetir el mismo es idempotente. Corregirlo es
  cosa de un administrador, desde el perfil del usuario en wp-admin.
- **Único entre cuentas:** tipo + número no puede estar en otra cuenta (`409 document_taken`),
  tampoco al editarlo en wp-admin. Los pedidos de invitado no cuentan.
- **Formato:** el número se valida contra el patrón de su tipo (`400 invalid_document`).

## REST (solo BFF)

Cuelgan de `/wp-json/hwe/v1/auth/`, que `hwe-bff-only` ya cierra al público, y además exigen el
secreto interno (`X-HWE-Internal-Secret`).

| Ruta | Para qué |
|---|---|
| `POST /auth/document-available` | `{ document_type, document_number }` → `{ available }`. El registro lo consulta antes de crear la cuenta. |
| `POST /auth/attach-identity` | `{ user_id, document_type?, document_number?, terms_accepted? }` → la identidad guardada. |

## El pedido nace con su documento (Store API)

El documento y la aceptación llegan en `extensions.hwe-identity` del `POST /checkout` de la Store
API, **dentro** de la creación del pedido y no después: con efectivo el pedido pasa a
«Procesando» en esa misma petición y el correo de «Nuevo pedido» sale antes de que el BFF pueda
escribir nada. El BFF los vuelve a escribir por wc/v3 como salvaguarda.

## Dónde se ve

- **wp-admin:** campos en el perfil del usuario (facturación) y bajo la facturación del pedido.
- **Correos:** bajo la dirección de facturación. El de la **tienda** lleva el documento completo;
  los del **cliente**, enmascarado (`***050`), porque el correo queda en buzones de terceros.
  La aceptación de términos no sale en los correos.

## Contrato con el frontend

- Módulo `customer-identity` en `config.modules` (activo = plugin activo). Con el módulo apagado
  el checkout y el registro no piden documento ni términos, la cuenta no pinta el campo y
  `PUT /api/store/customer` rechaza un `identity` (422).
- Este módulo no tiene datos públicos cacheados, así que no declara tag ISR.

## Pruebas

`backend/tests/Unit/CustomerIdentity/CustomerIdentityTest.php` (PHPUnit + Brain Monkey): tipos del
paquete, unicidad, escritura única, términos una sola vez, extensión del checkout, guarda de
wp-admin y enmascarado en los correos.
