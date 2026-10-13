# Envío — manual del equipo

Aquí gestionas cuánto cuesta enviar a cada ciudad, los extras opcionales de envío y a qué compras
se les regala el envío.

## Tarifas por ciudad

**Envío → Tarifas de envío → Añadir nueva.**

- **El título es la ciudad** (por ejemplo, «Madrid»). Da igual si escribes mayúsculas o tildes: se
  reconoce igual.
- **Tarifa fija:** el importe de ese envío, en la moneda de la tienda.
- **Aplica envío gratis:** marca la casilla si los pedidos a esa ciudad pueden tener envío gratis
  cuando cumplen la regla de envío gratis (ver abajo). Sin marcar, esa ciudad siempre paga.

Una ciudad **sin tarifa** paga el **envío de reserva** (ver abajo). Las tarifas solo valen para
destinos del país de la tienda.

## Envío de reserva (resto de ciudades)

**Control Center → Envío.** Es lo que paga una ciudad que no tiene tarifa propia.

- **Nombre:** lo que ve el cliente en el carrito («Envío nacional», por ejemplo).
- **Coste:** el importe. Con 0, esas ciudades no pagan envío.
- Se aplica al guardar. No hace falta tocar WooCommerce → Ajustes → Envío.

## Métodos de envío (extras)

**Envío → Métodos de envío.** No sustituyen la tarifa: son **extras que el cliente puede marcar**
en el checkout (por ejemplo, «Seguro» o «Caja de regalo») y se suman al pedido como un cargo.

- **Activo:** si no está activo, no se ofrece.
- **Valor agregado:** el importe extra. Vacío o 0 = sin coste.

## Envío gratis

**Control Center → Tienda → Envío gratis.** Activa la regla y define:

- **Valor mínimo de compra** (0 = sin mínimo).
- **Categorías y productos a los que aplica** (sin ninguno, aplica a todo el catálogo).

El envío sale gratis cuando la compra llega al mínimo **y** la ciudad tiene la casilla «Aplica
envío gratis». En el carrito el cliente ve cuánto le falta, y un «?» le explica a qué productos y
ciudades aplica. En el pedido, wp-admin muestra bajo «Envío» por qué no se cobró.

Un **cupón** con «Permitir envío gratuito» (Marketing → Cupones) también deja el envío a 0, sin
necesidad de la regla.

## Si algo no cuadra

- **El carrito no ofrece ningún envío:** comprueba que **Control Center → Envío → Zona de reserva**
  está activada y que el país de la tienda es el correcto (Control Center → Tienda).
- **Una ciudad no recibe su tarifa:** el título de la tarifa debe ser el nombre de la ciudad tal
  como la escribe el cliente (sin tildes ni mayúsculas importa poco). Si el cliente escribe otra
  variante («Madrid capital»), no coincide.
- **El aviso de envío gratis no aparece:** el módulo debe estar activo (**Plugins → HWE Shipping**),
  la regla encendida y al menos una ciudad con la casilla marcada.
