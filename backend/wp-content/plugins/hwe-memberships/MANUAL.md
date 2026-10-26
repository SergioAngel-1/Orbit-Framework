# Membresías — manual del equipo

El módulo de **membresías** agrupa a tus clientes en niveles con ventajas: un carné digital que se
puede verificar con el móvil, descuentos, envíos gratis, puntos, acceso anticipado y regalos por
referir a alguien. Se configura en **Membresías** (menú propio de wp-admin) y **HWE Config →
Membresías**, y se activa o desactiva como cualquier módulo del framework.

> **El módulo no trae vistas.** Entrega la configuración, las rutas internas y la lógica; la
> tienda pública la construye el frontend de cada instancia con el contrato documentado en
> `docs/FRONTEND_CONNECT.md`. Este manual es para el equipo que gestiona la tienda desde wp-admin.

## Para qué sirve

- **Niveles**: cada nivel agrupa ventajas y tiene un **rango** (mayor = más acceso). No hay
  niveles predefinidos: los creas tú y decides qué incluye cada uno.
- **Beneficios**: descuento de socio, envíos gratis por periodo, acceso anticipado, puntos extra,
  comisión por referidos, regalo de membresía y ventajas informativas.
- **Venta de membresías**: cada nivel puede tener uno o varios productos (mensual, anual,
  indefinido) y la membresía se activa **solo cuando el pedido se cobra**.
- **Catálogo exclusivo**: productos y categorías pueden exigir un rango mínimo y, si quieres, una
  fecha de apertura. Quien no cumple ve una ficha de escaparate, no el contenido completo.
- **Carné digital**: el socio tiene un número y un QR que la tienda puede verificar para
  comprobar que la membresía está vigente.
- **Renovaciones, mejoras y cola**: si el socio compra otro periodo o un nivel superior, la nueva
  concesión se encaja en la línea de tiempo; nunca se solapa ni se pierde.
- **Regalos por referido**: quien invita puede regalar una membresía al referido, con tope por
  periodo y, si quieres, exigiendo que confirme su correo.

## Cómo se activa

1. Activa el plugin **HWE Memberships** en Plugins de WordPress (o incluye `memberships` en la
   clave `modules` de `instance.config.json` y ejecuta `wp hwe setup`). Necesita WooCommerce.
2. Entra en **Membresías → Niveles** y crea tu primer nivel.
3. Vende la membresía: crea un producto, ábrelo y usa la pestaña **Membresía** para ligarlo al
   nivel y fijar la duración.
4. Configura las ventajas en **Membresías → Beneficios** y, si quieres el carné, actívalo en
   **HWE Config → Membresías**.
5. Comprueba que el frontend de tu tienda consume el contrato (niveles, estado del socio, carné,
   precios y carrito).

Si el módulo se apaga, la tienda deja de ofrecer membresías y sus rutas responden como si no
existieran, pero los datos guardados se conservan para cuando vuelva a encenderse.

## Niveles

En **Membresías → Niveles** (y en **Todos los niveles**) puedes crear y editar niveles:

| Campo | Qué significa |
|---|---|
| Nombre, descripción y slug | Lo que ve el cliente. Traducibles si la tienda tiene más idiomas. |
| **Rango** | Entero ≥ 1. Ordena los niveles y define el acceso: un producto que exige rango 2 lo ve quien tenga rango 2 o superior. Solo puede haber un nivel publicado con cada rango. |
| **Icono** y **color** | Opcionales, para que el frontend pinte la ficha del nivel. |
| **Se puede comprar** | Si está apagado, el nivel no se vende y sus productos quedan en borrador. |
| **Antigüedad mínima de la cuenta** | Días que debe tener la cuenta para comprar ese nivel. 0 = sin requisito. |
| **Beneficios** | Se configuran en la pantalla de beneficios, no en la caja del nivel. |

Un nivel con socios activos o programados **no se puede borrar ni despublicar**: primero quita o
mueve a esos socios. El listado nativo de WordPress sigue disponible como submenú.

## Beneficios

En **Membresías → Beneficios** eliges un nivel y marcas las ventajas que incluye. En
**Comparativa** ves todos los niveles a la vez.

| Beneficio | Qué configuras | Qué hace |
|---|---|---|
| **Ventaja informativa** | Título y texto | Se muestra en la ficha del nivel; no cambia precios ni accesos. |
| **Acceso anticipado** | Nada | Permite comprar antes de la fecha de apertura de un producto. |
| **Descuento de socio** | Porcentaje (0–100) y alcance | Todo el catálogo o solo unas categorías. Las subcategorías cuentan. Nunca se aplica a la línea de membresía. |
| **Envíos gratis** | Número por periodo | Cada concesión (compra o renovación) trae su cupo; lo no usado no se acumula. |
| **Puntos extra al activar** | Puntos | Se abonan al cobrar la compra, aunque la membresía quede en cola y se active más tarde. Solo si **Puntos** está encendido en Fidelización. |
| **Comisión extra por referidos** | Porcentaje | Se suma a la comisión normal del referidor cuando se cobra el pedido. Solo con **Referidos** encendido. |
| **Membresía de regalo al referido** | Nivel, días y máximo por periodo | El referido recibe una membresía; el tope es del periodo del referidor. Solo con **Referidos** encendido. |

Si un beneficio depende de otro módulo y ese módulo (o su función) está apagado, el beneficio deja
de ofrecerse al cliente, pero **su configuración se conserva** y vuelve al encenderlo. Los
beneficios de fidelización no se aplican retroactivamente: si «Puntos» estaba apagado al cobrar,
no se abonan después.

## Productos de membresía

En la ficha de un producto, pestaña **Membresía**:

- **Nivel**: el nivel que se compra. Sin nivel, el producto no es de membresía.
- **Duración (días)**: `0` = indefinida.

Al guardarlo, el producto pasa a **simple y virtual** y se marca en el listado. Si el nivel no
está publicado o no es comprable, el producto queda en **borrador** para que no se venda mientras
la membresía no pueda entregarse. Puedes tener varios productos por nivel (por ejemplo, 30 días y
365 días).

Reglas de compra que verá el cliente:

- Hay que iniciar sesión, aunque la tienda permita comprar como invitado.
- Solo una línea de membresía por pedido, con cantidad 1.
- La cuenta debe cumplir la antigüedad mínima y no estar pendiente de aprobación.
- No se puede comprar un nivel que el socio ya tenga, o uno superior, de forma **indefinida**.

La membresía se activa al cobrarse el pedido. Un pedido cancelado o reembolsado del todo la
retira; un reembolso parcial no. Las notificaciones repetidas del mismo pedido no conceden dos
veces.

## Catálogo exclusivo y acceso anticipado

Cualquier producto puede exigir un rango mínimo y una fecha de apertura. En la ficha del producto:

- **Rango mínimo de membresía**: `0` = público. El requisito efectivo es el mayor entre el del
  producto y el de toda su cadena de categorías.
- **Apertura al público**: antes de esa fecha solo lo pueden comprar los niveles con **acceso
  anticipado**.

En **Productos → Categorías**, cada categoría tiene su propio rango mínimo; la herencia se
calcula al leer, así que cambiar una categoría padre no reescribe sus hijas. La pantalla avisa
cuando una categoría hereda un rango mayor.

Quien no cumple el requisito **sigue viendo** el producto en el catálogo, la búsqueda y el mapa
del sitio, pero solo con la ficha de escaparate: nombre, imagen principal, precio público,
categorías, el nivel que hace falta y una marca de «bloqueado». No ve descripción, galería,
atributos, variaciones, stock de variaciones, descargas ni campos internos. Si el requisito no se
puede evaluar, el producto se trata como bloqueado. El personal de la tienda no tiene atajos en la
tienda pública: en la vista pública es un cliente más.

## Socios

**Membresías → Socios** lista a los socios con filtros (activos, congelados, por caducar en 30
días, caducados), ordenación y paginación. Desde la fila puedes **congelar**, **descongelar** o
**quitar** la membresía.

En el **perfil de cada usuario** (también en Usuarios) tienes el detalle: nivel efectivo, estado,
fin, cola programada, cupo de envíos consumido y reservado, número de socio y últimos eventos.
Las acciones son explícitas y cada una deja constancia en el historial:

- **Conceder** un nivel, con días o indefinido.
- **Extender** la cobertura actual: crea otra concesión del mismo nivel que se encaja detrás.
- **Quitar** todas las concesiones vivas.
- **Congelar / descongelar**.

La nota que escribas viaja a la concesión y al historial. Un doble envío del mismo formulario no
concede dos veces.

## Operaciones en masa

**Membresías → En masa** permite asignar, reemplazar, quitar, congelar o descongelar para muchos
socios a la vez. El flujo es de dos pasos:

1. Eliges la selección (lista pegada de correos o identificadores, o un criterio) y ves una
   **vista previa** de a cuántos afecta y cuántos se omiten; todavía no se escribe nada.
2. Confirmas y se crea un lote. El progreso se actualiza solo; si un lote falla, se puede
   **reanudar** sin duplicar lo ya aplicado.

## Historial y resumen

**Membresías → Historial** muestra los eventos del socio (alta, renovación, mejora, cola,
caducidad, congelación, retirada, revocación, puntos omitidos, tope de regalos…). El **Resumen**
da las cifras del programa. Desde el listado de socios puedes exportar un CSV con la información
operativa; la descarga exige la capacidad de gestión (`hwe_memberships_manage`), no basta con
poder consultar el listado.

## Límites y comportamientos que conviene conocer

- **Un pedido cancelado y reabierto no reactiva la membresía.** Si un pedido ya activado se
  cancela y después vuelve a estar cobrado, la concesión no se reabre sola: hay que conceder el
  nivel a mano desde el perfil del socio.
- **La comisión extra de referidos no aplica la ventana de comisión de Fidelización** (ni
  comprueba la participación del referidor): se paga cuando llega el pedido. Si quieres limitarla
  en el tiempo, hazlo en el filtro `hwe_loyalty_order_earnings` o desactiva el beneficio.
- **La comisión extra solo cuenta con una atribución confirmada y sigue la vigente.** Si el
  equipo reasigna el referidor de un cliente en Fidelización, la comisión de sus próximos pedidos
  va al nuevo referidor; si se anonimiza o se desvincula la red, deja de pagarse. Los pedidos ya
  cobrados no se recalculan.
- **Las reseñas de un producto bloqueado son visibles.** La ficha de escaparate oculta la
  descripción, la galería, las variaciones y los atributos, pero no el texto de las reseñas.

## Carné digital

En **HWE Config → Membresías**:

1. **Carné de socio**: enciende el carné. Apagado, sus rutas no existen y el resto de ajustes del
   carné no se aplican.
2. **Ruta de verificación**: la dirección de tu web a la que apunta el QR (por defecto `/verify`).
   Escríbela empezando por `/`; si no, el sistema la normaliza.
3. **Prefijo del número de socio**: un texto corto que precede al número. Vacío = sin prefijo.

Ajustes que solo ve el equipo técnico en la misma pestaña:

- **Vigencia del token del carné** (horas): cuánto vale el enlace firmado. Por defecto, 24.
- **Días de aviso antes de caducar**: con cuánta antelación se avisa al socio. Por defecto, 7.
- **El regalo al referido exige correo verificado**: encendido por defecto.
- **Los planes generan puntos de fidelización**: apagado por defecto. Comprado un plan, su precio
  no cuenta para el cashback, las comisiones ni el bono por producto; los puntos de activación
  del nivel se abonan igual. Enciéndelo solo si quieres premiar también la compra del plan.

El carné muestra el número de socio y un QR. Quien lo escanea llega a la ruta de verificación y ve
solo lo necesario para comprobar que la membresía está vigente. Un QR caducado, revocado o
inventado produce la misma respuesta: no se puede saber cuál de las tres cosas pasó. Al pedir un
carné nuevo, el anterior deja de valer; el carné se invalida al caducar, congelarse o retirarse
la membresía.

## Qué pasa al apagar el módulo

La tienda deja de mostrar niveles, ventajas y carnés; el catálogo vuelve a ser público (sin
recortes); el carrito deja de publicar la extensión de membresías; y las rutas del frontend
responden como si no existieran. **Nada se borra**: niveles, socios, concesiones, requisitos de
productos y categorías, carnés y configuración siguen ahí para cuando vuelvas a encenderlo. Si
apagas solo la fidelización o el envío, las ventajas que dependen de ellos desaparecen para el
cliente y su configuración se conserva.

Al desactivar el plugin, sus **productos de membresía publicados pasan a borrador** para que no
se puedan comprar mientras no haya módulo que entregue la membresía. Al volver a activarlo se
restauran solos si nadie los cambió a mano, y los pedidos que se cobraron durante el apagón se
concilian en la siguiente petición con el plugin ya arrancado (un evento único; la tarea diaria lo
retoma si queda pendiente). La membresía se concede una sola vez, con la clave de la línea del
pedido. Si tenías productos de membresía en borrador por otro motivo, no se tocan.

### Procedimiento de apagado seguro

Antes de apagar el plugin, o de apagar `loyalty` mientras membresías sigue activo, ten en cuenta
lo que **no** se puede compensar solo:

- **Apagar `loyalty`:** los puntos de activación de una membresía revocada quedan en una cola
  interna y se revierten solos cuando `loyalty` vuelve (también en la tarea diaria). Lo que no se
  compensa es lo que gestiona `loyalty`: los puntos canjeados en un pedido que después se cancela
  o reembolsa no se devuelven mientras esté apagado, y las ganancias no se revierten. Revisa esos
  pedidos a mano o espera a tener `loyalty` activo para cancelarlos.
- **Apagar membresías:** las cancelaciones y reembolsos ocurridos en el apagón no revocan la
  concesión ni liberan el cupo de envíos gratis. Al reactivar, la conciliación concede las
  compras cobradas que falten, pero **no** revierte las canceladas; si hubo reembolsos, revócalos
  desde **Membresías → Socios** después de encenderlo.
- **Cambiar de nivel o de beneficio:** las concesiones ya emitidas conservan su nivel y sus
  fechas. Los cambios de configuración se aplican a partir de la siguiente concesión o lectura.

Para un apagado sin pendientes: deja que terminen los pedidos en curso, cancela o reembolsa antes
de apagar, y al volver a encender revisa el historial del socio y la caja del pedido.

## Comprobaciones antes de abrir al público

1. Crea al menos un nivel publicado y comprable, con su rango.
2. Crea el producto de membresía y comprueba que queda como simple y virtual.
3. Haz una compra de prueba: la membresía debe activarse al cobrarse y aparecer en el perfil del
   socio.
4. Comprueba un producto bloqueado sin nivel (ficha de escaparate) y con el nivel (ficha completa).
5. Si hay envíos gratis, comprueba que el carrito permite pedirlos y que el cupo baja al cobrar.
6. Si el carné está encendido, verifica el QR y prueba un token caducado.
7. Revisa que las rutas del frontend responden 404 con el módulo apagado.

La verificación técnica en vivo está en `backend/scripts/verify-modules.sh`; el contrato de
frontend, en `docs/FRONTEND_CONNECT.md` (Parte A).
