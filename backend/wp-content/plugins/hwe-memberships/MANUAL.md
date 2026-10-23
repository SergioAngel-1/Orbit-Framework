# Membresías — manual del equipo

El módulo de **membresías** agrupa a tus clientes en niveles con beneficios: un carné digital que
se puede verificar con el móvil, ventajas por nivel, renovaciones y regalos por referir a alguien.
Se configura en **HWE Config → Membresías** y se activa o desactiva como cualquier módulo del
framework (Plugins de WordPress).

> **El módulo está en construcción.** Hoy existen el arranque y los ajustes; las pantallas de
> niveles, concesiones y beneficios, el carné y las rutas del frontend llegan en entregas
> posteriores. Este manual se completa con ellas.

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

## Cómo se activa

1. Activa el plugin **HWE Memberships** en Plugins de WordPress (o incluye `memberships` en la
   clave `modules` de `instance.config.json` y ejecuta `wp hwe setup`).
2. Entra en **HWE Config → Membresías** y ajusta lo que necesites.
3. Cuando exista la UI, el carné aparecerá en la cuenta del cliente solo si el carné está
   encendido y el módulo activo.

Si el módulo se apaga, la tienda deja de ofrecer membresías y sus rutas responden como si no
existieran, pero los datos guardados se conservan para cuando vuelva a encenderse.
