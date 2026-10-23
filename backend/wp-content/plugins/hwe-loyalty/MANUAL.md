# Fidelización — manual del equipo

El módulo de fidelización premia a los clientes con **puntos** que pueden canjear en el checkout y
con **comisiones por referidos**. Se configura en **HWE Config → Fidelización** y se activa o
desactiva como cualquier módulo del framework (Plugins de WordPress).

> **El módulo no trae vistas.** Entrega el contrato (configuración, rutas del BFF y extensión del
> carrito); la UI pública (saldo, canje, enlace de referido, transferencias) la construye el
> frontend de cada instancia. El contrato completo está en `docs/FRONTEND_CONNECT.md`.

## Para qué sirve

- **Puntos**: el cliente gana unidades al comprar (devolución opcional), al registrarse, al
  reseñar o por su cumpleaños, y las usa para pagar parte del pedido.
- **Referidos**: cada cliente tiene un código propio. Quien se registra con él queda vinculado, y
  el referidor cobra una comisión (en puntos) sobre sus compras.

## Qué ve el cliente

- **Su saldo** y el valor aproximado en dinero, lo que ha ganado y gastado, y cuántas unidades le
  caducan pronto.
- **El canje en el carrito**: elige cuántas unidades usar; la web nunca decide el descuento, lo
  calcula la tienda y lo recorta a su saldo, al máximo por pedido y a lo que el carrito permite.
  Si no puede usarse, el carrito explica el motivo (sin sesión, cuenta no participante, programa
  apagado, por debajo del mínimo o carrito por debajo del mínimo a pagar en dinero).
- **El pago completo** solo si está encendido: puede dejar el pedido en cero, envío incluido. Sin
  ese ajuste, siempre queda por pagar en dinero el mínimo configurado.
- **Su enlace de referido** para compartir y el estado de su red (quién se registró con su código,
  pendiente o confirmado, y cuántas unidades le ha generado).
- **Transferencias**, si están encendidas: enviar unidades a otra persona por su código, con el
  mínimo, la comisión y el tope diario que hayas configurado. El recibo muestra el nombre visible
  del receptor, nunca su usuario ni su correo.
- **Su historial**: cada movimiento con una descripción en su idioma.

Si el saldo cambia mientras el cliente está comprando (usa otra pestaña, recibe una transferencia
o le caducan unidades), el pedido **no se crea** con un descuento sin respaldo: la tienda avisa y
el cliente vuelve al carrito, que ya muestra lo que su saldo cubre en ese momento. Tras una compra
con canje confirmado, la cantidad pedida se descarta para que la siguiente compra no gaste
unidades que nadie eligió.

## Ajustes que puedes tocar

Los más importantes primero:

1. **Programa de puntos**: el interruptor general. Si está apagado, no se gana, no se canjea y los
   referidos tampoco funcionan.
2. **Programa de referidos**: comisiones y puntos por alta. Necesita el programa de puntos.
3. **Nombre e icono de la unidad**: cómo se llama la unidad en la tienda («punto» y «puntos» por
   defecto) y su imagen. El módulo no escribe el nombre en ningún texto: sale de aquí.
4. **Valor de una unidad**: cuánto vale en la moneda de la tienda. Si lo dejas en cero o negativo,
   el canje se desactiva (nunca se regalan descuentos sin respaldo).
5. **Canje**: mínimo de unidades para canjear, tope por pedido y si se puede pagar el pedido
   completo (con envío). Con el pago completo apagado, siempre queda por pagar en dinero el
   **mínimo a pagar** configurado, aunque el envío sea gratis.
6. **Caducidad**: días que duran las unidades ganadas. Cero significa que no caducan.
7. **Eventos**: cuántas unidades se dan al confirmar la cuenta, al publicar una reseña y por
   cumpleaños. Cero desactiva el evento.
8. **Comisiones de referidos**: porcentaje en la primera compra del referido, en las siguientes y
   para el referidor del referidor (nivel 2). También cuántos días dura la comisión.
9. **Transferencias**: si los clientes pueden pasarse unidades entre ellos, con qué mínimo, qué
   comisión se queda la tienda y cuánto puede transferir cada uno al día.

## Valores por defecto

Todo lo que gasta dinero o amplía el programa nace **apagado**: devolución al comprador, pago
completo, transferencias y nivel 2. Los porcentajes y los importes por evento nacen en cero. Así
una tienda no empieza a repartir puntos sin haberlo decidido.

## El panel «Fidelización»

En el menú lateral de wp-admin aparece **Fidelización** cuando el plugin está activo. Lo ven las
cuentas con la capacidad de lectura (por defecto, administradores y responsables de tienda); para
ajustar saldos, códigos o referidores hace falta la capacidad de gestión. Las dos se conceden
solas al activar el plugin y se reparan si faltan.

- **Resumen**: cuántas unidades hay en circulación, cuántas se dieron, se canjearon y caducaron en
  los últimos 30, 90 o 365 días, y el estado de los referidos. Los «mejores referidores» son
  quienes más altas confirmadas acumulan.
- **Movimientos**: el historial completo. Puedes filtrar por persona (id, email o login), tipo de
  movimiento, fechas y número de pedido. **Exportar CSV** descarga exactamente lo filtrado.
- **Red**: busca a una persona por id, email o login y verás su referidor, cuántos referidos tiene
  en cada nivel y cuántas unidades ha generado su red. No muestra emails ni logins de los
  referidos: solo su nombre visible.
- **Códigos**: asigna un código a quien todavía no tiene, en tandas. Si la operación se corta,
  vuelve a pulsar: continúa donde quedó y no repite códigos ni usuarios.
- **Ajuste manual**: abona o carga unidades con una nota obligatoria. Un cargo nunca puede dejar
  el saldo en negativo: si la persona no tiene suficiente, se rechaza y no se mueve nada.

El **perfil de cada persona** (Usuarios → editar) muestra su saldo y sus últimos movimientos, y
permite cambiar su código o su referidor. El saldo **no se escribe a mano**: se usa el abono o el
cargo explícito de la misma sección. Cambiar el referidor no mueve puntos ya pagados y respeta las
reglas de la red (nadie puede referirse a sí mismo ni crear un ciclo).

En el **listado de usuarios** hay dos columnas nuevas, **Saldo** y **Referidor**, que se pueden
ordenar. En la **ficha de cada pedido** hay una caja con las unidades canjeadas, el descuento y las
ganancias que generó, con su estado.

El **CSV de movimientos** no incluye email ni login: lleva el id y el nombre visible, el tipo, los
puntos, la referencia, la nota del ajuste y las fechas. Así el archivo sirve para cuadrar sin
sacar la identidad completa del panel.

## Privacidad y datos personales

El programa guarda datos personales (el historial de puntos y la red de referidos), así que el
módulo responde a las herramientas de privacidad de WordPress: **Herramientas → Exportar datos
personales** y **Herramientas → Borrar datos personales**, en el grupo **Fidelización**.

- **Exportar**: entrega el saldo, el código de referido, el nombre visible de quien lo refirió,
  el estado y las fechas de esa relación y el historial de movimientos (fecha, tipo, puntos,
  caducidad, pedido y nota). Nunca incluye emails, logins ni identificadores de otras personas;
  de una transferencia solo aparece el nombre visible de la otra parte.
- **Borrar**: la cuenta puede seguir existiendo, así que se conservan los importes, los tipos y
  las fechas de los movimientos —son la contabilidad del programa— pero se borran las notas, los
  datos asociados y los vínculos de la red. El código de referido deja de funcionar y sus
  referidos quedan sin referidor. La herramienta lo explica en su respuesta y puede ejecutarse
  varias veces sin efecto adicional.
- **Eliminar una cuenta** desde Usuarios: se borran su código y su saldo, sus referidos quedan
  sin referidor y su historial se conserva anonimizado. Aunque WordPress ofrezca reasignar el
  contenido de la cuenta a otra persona, **la red y los puntos no se heredan**.

Consejo para el equipo: antes de borrar una cuenta, exporta sus datos si la persona los ha
pedido; después, el historial conservado ya no permite identificar sus movimientos.

## Roles que participan

Por defecto participan las cuentas con rol **Cliente** y **Suscriptor**. El registro del framework
crea suscriptores, así que limitarlo solo a «Cliente» dejaría fuera a las cuentas nuevas. Es un
ajuste técnico: no se publica en la web. Un asesor técnico puede cambiarlo o ampliarlo con el
filtro `hwe_loyalty_participant_roles`.

## Si algo no cuadra

- La pestaña no aparece: el plugin **HWE Loyalty** no está activo.
- El menú **Fidelización** no aparece o las acciones están bloqueadas: tu cuenta no tiene la
  capacidad de lectura o de gestión. Un administrador puede concederlas; no se dan a roles
  creados por la instancia.
- Cambias un ajuste y la web no lo refleja: la configuración se sirve cacheada unos minutos;
  espera a que caduque o pide que se revalide.
- WooCommerce no está activo: el módulo no arranca y deja un aviso en el panel. Sin pedidos no hay
  nada que fidelizar.
