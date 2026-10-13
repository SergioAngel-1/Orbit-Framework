# Manual de operación · Integración externa

Guía para dar (y quitar) a un sistema externo —ERP, logística, facturación— acceso a los pedidos de la
tienda. No es para editores de contenido: la hace una persona con acceso al servidor.

---

## 1. Qué recibe el integrador

| | |
|---|---|
| Dirección | `https://<dominio>/graphql` (POST, `Content-Type: application/json`), **solo desde las IPs autorizadas** |
| Autenticación | HTTP Basic: el usuario de servicio + su contraseña de aplicación, sobre HTTPS |
| Lectura | `order(id:, idType: DATABASE_ID)` y `orders(first:, after:, where:)` con filtros de fecha y estado |
| Escritura | `updateOrder(input: { orderId, status })`: **solo** el estado |
| Cualquier otra operación | Error «Operación no permitida para esta integración» |
| `metaData` | En el pedido, solo transportadora, número de guía y enlace de seguimiento. En las líneas, el meta visible |

Transportadora y guía las escribe el equipo en la caja **«Envío: transportadora y guía»** de la pantalla
del pedido (WP-Admin).

Notas para quien escribe las consultas:
- Los importes salen formateados salvo que se pida `total(format: RAW)`.
- El nombre del comprador está en `billing { firstName lastName company }`; `customer { databaseId }` da la
  cuenta (nulo en un pedido de invitado).
- WooCommerce usa `checkout-draft` para los carritos a medias: filtra por estado.

---

## 2. Dar acceso

1. **Activa el módulo** (`modules: ["external-integration", …]` en `instance.config.json` y
   `wp hwe setup`, o activa el plugin en WP-Admin).
2. **Declara las IPs** del integrador en `.env` / `.env.prod`:

   ```
   HWE_INTEGRATION_ALLOWED_IPS=198.51.100.7 203.0.113.20
   ```

   IPs **exactas**, separadas por espacios o comas; sin rangos. Recrea el contenedor de WordPress para que
   lo lea.
3. **Crea el usuario de servicio** con el rol del módulo:

   ```
   docker compose --profile cli run --rm -T --entrypoint wp wpcli --path=/var/www/html \
     user create integracion integracion@tu-dominio.example --role=hwe_integration --user_pass="$(openssl rand -hex 16)"
   ```

4. **Genera su contraseña de aplicación** (se muestra **una sola vez**):

   ```
   docker compose --profile cli run --rm -T --entrypoint wp wpcli --path=/var/www/html \
     user application-password create integracion alta --porcelain </dev/null
   ```

   En producción usa los dos flags de compose (`-f docker-compose.prod.yml --env-file .env.prod`): sin
   ellos se usa el de desarrollo.

> **La credencial es una contraseña de aplicación de WordPress, NO una clave de la API REST de
> WooCommerce** (`ck_…`/`cs_…`). Una clave REST no sirve en GraphQL y, en cambio, abre `wc/v3` entero con
> los permisos de quien la crea, sin restricción de IP, sin lista de operaciones y sin filtro de meta. No
> crees una para un integrador.

La contraseña **no se envía por correo ni por mensajería**: se pega directamente en el sistema del
integrador. Si se pierde o se sospecha de ella:

```
… user application-password delete integracion --all
… user application-password create integracion alta --porcelain
```

---

## 3. Cambiar la IP o retirar el acceso

- **Cambiar la IP:** edita `HWE_INTEGRATION_ALLOWED_IPS` y recrea el contenedor de WordPress.
- **Retirar el acceso:** vacía `HWE_INTEGRATION_ALLOWED_IPS` y recrea el contenedor: `/graphql` vuelve a
  responder 404 a todos salvo al frontend. Opcionalmente, `wp user delete integracion --yes`.
- **Apagar el módulo** también cierra la puerta: sin él, una IP de la lista no abre nada.

---

## 4. Qué puede y qué no puede el usuario de servicio

- Puede **leer pedidos** y **cambiar su estado**. No puede crear ni editar nada más.
- Un pedido **sin pagar** (`pending`, `failed`) solo se puede **cancelar**: el cobro lo afirma la
  pasarela, no la integración.
- No puede iniciar sesión en WP-Admin ni usar la API REST de WooCommerce.
- No ve: la clave del pedido, la IP ni el navegador del comprador, las notas internas, el meta privado
  (documento de identidad, consentimiento…) ni las credenciales o el meta de los clientes.

Si el integrador necesita un dato más (por ejemplo, el documento del comprador), lo añade el desarrollo con
el filtro `hwe_integration_policy` (ver `README.md`); no se abre desde el panel.

---

## 5. Verificar

Con la pila local levantada:

```
sh backend/scripts/verify-integration.sh all
```

(o una sección: `tracking`, `gate`, `access`, `guard`). Crea un usuario y un pedido desechables y los borra
al terminar. En desarrollo simula la IP autorizada con `X-Forwarded-For`; **no puede comprobar** que el proxy
de producción vea la IP real del integrador ni que `Authorization` llegue a PHP a través del proxy: se
comprueba en el despliegue.

---

## 6. Cosas que no se ven en el código

- El estado `refunded` hace que WooCommerce cree un reembolso total en el pedido (sin devolver dinero en la
  pasarela).
- Un cambio de estado envía al comprador el correo de WooCommerce y dispara el webhook de pedido
  actualizado. En las pruebas, el pedido debe llevar un correo interno.
- El límite de peticiones de GraphQL es por IP y responde 429 con `Retry-After`: una carga inicial del
  histórico tiene que ir a menos de una página por segundo.
