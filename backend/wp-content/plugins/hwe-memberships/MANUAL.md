# Membresías — manual del equipo

El módulo de **membresías** agrupa a tus clientes en niveles con beneficios: un carné digital que
se puede verificar con el móvil, ventajas por nivel, renovaciones y regalos por referir a alguien.
Se configura en **HWE Config → Membresías** y se activa o desactiva como cualquier módulo del
framework (Plugins de WordPress).

> **El módulo está en construcción.** Ya existen los niveles, las concesiones, las compras, el
> carné, los beneficios y el descuento de miembro; las rutas internas y la UI pública del
> frontend llegan en entregas posteriores. Este manual se completa con ellas.

> **El módulo no trae vistas.** Entrega el contrato (configuración y, más adelante, rutas del BFF);
> la UI pública la construye el frontend de cada instancia.

## Para qué sirve

- **Niveles**: cada nivel agrupa beneficios (descuentos, envío, acceso a contenidos…). No hay
  niveles predefinidos: los crea el editor y decide qué incluye cada uno.
- **Carné digital**: el socio tiene un carné con su número y un QR que la tienda puede verificar
  para comprobar que la membresía está vigente.
- **Renovaciones y caducidad**: la membresía tiene fecha de fin; el sistema avisa antes de que
  caduque y puede renovarse.
- **Regalos por referido**: quien invita a un nuevo cliente puede regalarle una membresía, con la
  opción de exigir que el referido confirme su correo antes de concederla.
- **Beneficios por nivel**: además de las ventajas informativas, un nivel puede abonar puntos al
  comprar, pagar una comisión extra a quien refirió al socio, regalar una membresía al referido y
  aplicar un descuento de miembro en el carrito.

## Descuento de miembro

Cada nivel puede aplicar un porcentaje de descuento (0–100) a todo el catálogo o solo a las
categorías que elijas. Elegir una categoría incluye también sus subcategorías. El descuento se
calcula sobre el precio que la tienda cobraría en ese momento (con la oferta incluida), se aplica
en el carrito del socio y nunca a los productos de membresía. Los cupones se aplican después y los
impuestos se calculan sobre el precio ya rebajado.

Si el socio pierde el nivel, la membresía se congela o el beneficio se apaga, el siguiente
recálculo del carrito devuelve el precio normal: el descuento no se queda «pegado». En el pedido
queda registrado el precio original, el descuento y el nivel/porcentaje aplicados, para que el
equipo pueda revisarlo.

## Beneficios que usan la fidelización

Estos beneficios solo funcionan si el módulo **HWE Loyalty** está activo y la función
correspondiente encendida (**Puntos** o **Referidos**). Si se apaga, dejan de ofrecerse al
cliente y su configuración se conserva para cuando vuelva a encenderse.

- **Puntos por activación**: se abonan una sola vez por la compra de la membresía. Si Puntos está
  apagado en el momento del cobro, no se abonan ni se recuperan después; queda constancia en el
  historial del socio.
- **Comisión extra para el referidor**: un porcentaje adicional sobre la comisión normal de
  fidelización. Cuenta el nivel que el referidor tenga cuando se cobra el pedido.
- **Membresía de regalo para el referido**: siempre hay un tope de regalos por periodo (mínimo 1)
  y se concede una sola vez por cuenta referida. Si el referido ya tiene ese nivel o uno
  superior, la membresía regalada empieza cuando termina la que ya tiene. Alcanzado el tope, el
  referido se registra con normalidad y el referidor ve la constancia en su historial.

## Qué puedes configurar hoy

1. **Carné de socio**: el interruptor que lo enciende. Apagado, el resto de ajustes del carné no
   se aplican.
2. **Ruta de verificación**: la dirección de tu web a la que apunta el QR del carné (por defecto
   `/verify`). Escríbela empezando por `/`; si no, el sistema la normaliza.
3. **Prefijo del número de socio**: un texto corto que precede al número (por ejemplo, las
   iniciales de la tienda). Vacío = número sin prefijo.

Hay ajustes que solo ve el equipo técnico en la misma pestaña:

- **Vigencia del token del carné** (horas): cuánto vale el enlace firmado con el que se verifica
  un carné. Por defecto, 24 horas.
- **Días de aviso antes de caducar**: con cuánta antelación se avisa al socio. Por defecto, 7 días.
- **El regalo al referido exige correo verificado**: encendido por defecto; si se apaga, la
  membresía de regalo se concede al registrarse, sin esperar a la confirmación del correo.

## Catálogo exclusivo para socios

Un producto o una categoría pueden exigir un nivel mínimo y, opcionalmente, una fecha de
apertura. Quien no cumple el requisito no deja de ver el producto: aparece en el catálogo, la
búsqueda y el mapa del sitio con su nombre, su imagen principal, su precio público, sus
categorías y una marca de «bloqueado» que indica qué nivel hace falta (y desde qué fecha se abre
al público). Lo que no se muestra es el contenido de la ficha: descripción, galería, atributos,
variaciones ni el stock de las variaciones, descargas y campos internos.

Las categorías bloqueadas sí muestran su nombre, imagen y descripción: esa descripción es la que
invita a hacerse socio. Los productos que contiene siguen la regla anterior.

El personal de la tienda puede editar y previsualizar todo en wp-admin, pero en la tienda pública
es un cliente más: no se salta los requisitos.

## Cómo se activa

1. Activa el plugin **HWE Memberships** en Plugins de WordPress (o incluye `memberships` en la
   clave `modules` de `instance.config.json` y ejecuta `wp hwe setup`).
2. Entra en **HWE Config → Membresías** y ajusta lo que necesites.
3. Cuando exista la UI, el carné aparecerá en la cuenta del cliente solo si el carné está
   encendido y el módulo activo.

Si el módulo se apaga, la tienda deja de ofrecer membresías y sus rutas responden como si no
existieran, pero los datos guardados se conservan para cuando vuelva a encenderse.
