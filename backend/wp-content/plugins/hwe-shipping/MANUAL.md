# Envío — manual del equipo

Aquí decides cuánto cuesta enviar a cada ciudad, si una ciudad se cobra por distancia real, las
opciones de entrega (por ejemplo, «Estándar» y «Express»), los extras opcionales y a qué compras se
les regala el envío.

## Tarifas por ciudad

**Envío → Tarifas de envío → Añadir nueva.**

- **El título es la ciudad** (por ejemplo, «Madrid»). Da igual si escribes mayúsculas, tildes o
  espacios dobles: se reconoce igual. No puede haber dos tarifas para la misma ciudad.
- **Tipo de tarifa:** elige una sola vez.
  - **Fija:** cada opción tiene un importe, en la moneda de la tienda.
  - **Por distancia:** cada opción tiene un precio por kilómetro, con un mínimo y un máximo. Con 0 en
    el máximo, no hay límite.
- **Opciones de entrega:** añade tantas como quieras (al menos una). Cada una tiene un nombre que ve
  el cliente y la casilla **«Aplica envío gratis»** (ver «Envío gratis»). Un nombre que cambies no
  cambia la opción: lo que ya eligió un cliente en su carrito sigue valiendo.

Una ciudad **sin tarifa** paga el **envío de reserva** (ver abajo). Las tarifas solo valen para
destinos del país de la tienda.

## Ciudades por distancia

Solo para el tipo «Por distancia».

- **Dirección de referencia:** el punto de salida de tus envíos a esa ciudad. Escribe la dirección y
  pulsa **«Validar»**: el sistema busca sus coordenadas y las muestra. Puedes corregir la latitud y la
  longitud a mano. Se guardan; no se vuelven a buscar en cada pedido.
- **Radio de cobertura (km):** hasta dónde llegas desde la referencia. Tiene que ser mayor que 0. Un
  cliente fuera del radio no puede comprar con envío a esa ciudad: no cae al envío de reserva.
- **Pin del cliente:** el cliente marca en el mapa dónde quiere recibir el pedido. Sin pin no hay
  tarifa para una ciudad por distancia y el pedido no se puede crear. La dirección escrita no basta.
  Si el cliente cambia la calle, tiene que volver a marcar el pin.

Si una ciudad no se publica, la pantalla de edición te dice el motivo (por ejemplo, «el radio debe ser
mayor que 0» o «ya hay una tarifa para esta ciudad»). Corrígelo y vuelve a guardar.

## Distancia: proveedor y ajustes

El cálculo por distancia usa un **proveedor de mapas** o, si no hay uno configurado, la **línea recta**.

1. **Control Center → Integraciones → Mapas.** Elige el proveedor (Google Maps o OpenRouteService) y
   guarda su clave de API. La clave se guarda cifrada y no sale del servidor. Para Google, el proyecto
   necesita las APIs de Rutas y de Geocodificación habilitadas.
2. **Control Center → Envío → Distancia.**
   - **Método de cálculo:** «Mapas» usa el proveedor de arriba; «Línea recta» no llama a ningún
     proveedor y calcula la distancia en línea recta con un incremento.
   - **Modo de viaje:** conduciendo o caminando.
   - **Incremento sobre la línea recta:** un porcentaje (0–200). Se usa también cuando el proveedor falla.
   - **Caché de distancias (horas):** cuánto se guarda una distancia calculada.
   - **«Probar conexión»:** hace una llamada real al proveedor con una dirección de prueba que escribes
     tú. Te dice si la clave es válida, si la API está habilitada, si hay cuota agotada o si hay un
     problema de red, con una pista para arreglarlo.

**Si el proveedor falla** (clave rechazada, cuota, servicio caído o una respuesta sin ruta), el envío
**no** se regala ni se cobra un importe inventado: se calcula en línea recta y el pedido queda marcado
como «respaldo». Se registra el motivo.

Wp-admin te avisa si el método es «Mapas» pero falta el proveedor o la clave, o si la clave guardada no
se puede leer.

## Envío de reserva (ciudades sin tarifa)

**Control Center → Envío.** Es lo que paga una ciudad que no tiene tarifa propia.

- **Enviar también a las ciudades sin configurar:** si lo apagas, esas ciudades no tienen envío y solo
  vendes a las ciudades que tienes configuradas.
- **Nombre:** lo que ve el cliente en el carrito («Envío nacional», por ejemplo).
- **Coste:** el importe. Con 0, esas ciudades no pagan envío.

No hace falta tocar WooCommerce → Ajustes → Envío: se sincroniza al guardar.

Si **desactivas el módulo**, su zona de reserva se retira en ese mismo momento: sin el método
`hwe_city_rate` seguiría cubriendo el país base y, si estuviera primero, dejaría sin tarifas a las
zonas de después. Al reactivarlo se crea otra vez con el mismo nombre y coste, y tus demás zonas no
se tocan. Si habías añadido métodos propios a la zona del módulo, se van con ella: para conservarlos,
crea tu propia zona y ponles ahí el método «Tarifas por ciudad».

## Ciudades del modelo anterior

Si la tienda tenía tarifas de una versión anterior, esas ciudades **no tienen envío** hasta que las
reconfiguras. Wp-admin muestra un aviso con la lista. Abre cada ciudad, elige su tipo (fija o por
distancia), define sus opciones y guarda. No se convierte nada automáticamente.

## Métodos de envío (extras)

**Envío → Métodos de envío.** No sustituyen la tarifa: son **extras que el cliente puede marcar** en el
checkout (por ejemplo, «Seguro» o «Caja de regalo») y se suman al pedido como un cargo.

- **Activo:** si no está activo, no se ofrece.
- **Valor agregado:** el importe extra. Vacío o 0 = sin coste.

## Envío gratis

**Control Center → Tienda → Envío gratis.** Activa la regla y define:

- **Valor mínimo de compra** (0 = sin mínimo).
- **Categorías y productos a los que aplica** (sin ninguno, aplica a todo el catálogo).

Luego, en cada ciudad, marca **«Aplica envío gratis»** solo en las opciones que deban regalarse. Por
ejemplo, en una ciudad puedes regalar «Estándar» y cobrar «Express».

El envío de esas opciones sale gratis cuando la compra llega al mínimo. Las opciones sin marcar siguen
cobrándose. El envío de reserva **nunca** es gratis.

En el carrito el cliente ve cuánto le falta, y un «?» le explica a qué productos y ciudades aplica. En
el pedido, wp-admin muestra bajo «Envío» por qué no se cobró.

Un **cupón** con «Permitir envío gratuito» (Marketing → Cupones) también deja el envío a 0, sin necesidad
de la regla.

## Pedidos

En el pedido, bajo la línea de envío, wp-admin muestra los datos de la tarifa: la opción, el tipo, los
kilómetros y la duración estimada (en distancia), el proveedor, si hubo respaldo y por qué, el punto de
entrega y un enlace al mapa para quien reparte. La dirección de referencia no aparece en el pedido.

## Si algo no cuadra

- **El carrito no ofrece ningún envío:** comprueba que **Control Center → Envío → Zona de reserva** está
  activada y que el país de la tienda es el correcto (Control Center → Tienda). Si todas tus ciudades son
  por distancia, comprueba también que el cliente haya marcado su ubicación en el mapa.
- **Salen tarifas que no son las de tus ciudades:** en WooCommerce → Ajustes → Envío, otra zona tuya (o una
  zona sin regiones, que coincide con todo) está antes que la del módulo. Muévela detrás, quítale el país o
  añádele el método «Envío por ciudad».
- **Una ciudad no recibe su tarifa:** el título debe ser el nombre de la ciudad tal como la escribe el
  cliente (las mayúsculas y las tildes no importan). Si el cliente escribe otra variante («Madrid capital»),
  no coincide.
- **Una ciudad por distancia dice «fuera de cobertura»:** el cliente está más lejos del radio. Revisa la
  referencia y el radio.
- **Una ciudad por distancia pide ubicación:** el cliente no ha marcado el pin o ha cambiado la calle
  después de marcarlo.
- **Las distancias salen siempre «respaldo»:** revisa el método de cálculo, la clave y el proveedor en
  Integraciones → Mapas, y pulsa «Probar conexión».
- **El aviso de envío gratis no aparece:** el módulo debe estar activo (**Plugins → HWE Shipping**), la
  regla encendida y al menos una opción de una ciudad con la casilla marcada.
