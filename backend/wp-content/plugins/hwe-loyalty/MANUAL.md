# Fidelización — manual del equipo

El módulo de fidelización premia a los clientes con **puntos** que pueden canjear en el checkout y
con **comisiones por referidos**. Se configura en **HWE Config → Fidelización** y se activa o
desactiva como cualquier módulo del framework (Plugins de WordPress).

> **En construcción.** Por ahora esta pantalla solo guarda los ajustes. El saldo, el historial, el
> canje, los referidos y las pantallas de administración todavía no están disponibles.

## Para qué sirve

- **Puntos**: el cliente gana unidades al comprar (devolución opcional), al registrarse, al
  reseñar o por su cumpleaños, y las usa para pagar parte del pedido.
- **Referidos**: cada cliente tiene un código propio. Quien se registra con él queda vinculado, y
  el referidor cobra una comisión (en puntos) sobre sus compras.

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

## Roles que participan

Por defecto participan las cuentas con rol **Cliente** y **Suscriptor**. El registro del framework
crea suscriptores, así que limitarlo solo a «Cliente» dejaría fuera a las cuentas nuevas. Es un
ajuste técnico: no se publica en la web. Un asesor técnico puede cambiarlo o ampliarlo con el
filtro `hwe_loyalty_participant_roles`.

## Si algo no cuadra

- La pestaña no aparece: el plugin **HWE Loyalty** no está activo.
- Cambias un ajuste y la web no lo refleja: la configuración se sirve cacheada unos minutos;
  espera a que caduque o pide que se revalide.
- WooCommerce no está activo: el módulo no arranca y deja un aviso en el panel. Sin pedidos no hay
  nada que fidelizar.
