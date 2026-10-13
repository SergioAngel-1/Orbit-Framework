# Documento de identidad del cliente — manual del equipo

Si tu tienda lo necesita para facturar, el cliente da su **documento de identidad** al comprar o
al registrarse, y acepta los **términos y condiciones**. Aquí ves y gestionas esos datos.

## Qué se pide

- **Documento:** solo si el país de la tienda tiene tipos de documento (por ejemplo, cédula,
  pasaporte o NIT en Colombia). Si no, solo se pide aceptar los términos.
- **Términos:** una casilla con enlaces a los términos y la política de privacidad.

## Dónde ver los datos

- **En un pedido** (WooCommerce → Pedidos): bajo la dirección de facturación, el documento y
  cuándo aceptó los términos.
- **En un cliente** (Usuarios → editar): en «Dirección de facturación», tipo y número de
  documento.
- **En los correos:** el correo que recibe la tienda lleva el documento completo; el que recibe el
  cliente lo lleva **enmascarado** (`***050`) para que no viaje entero a su buzón.

## Reglas

- **El cliente no puede cambiar su documento** una vez guardado. Si se equivocó, corrígelo tú en
  su perfil (Usuarios → editar → «Número de documento»).
- **No puede haber dos cuentas con el mismo documento.** Si intentas guardar uno que ya existe,
  WordPress lo rechaza y avisa.
- **Quitar un documento:** vacía los dos campos del perfil y guarda.
- Un cliente que compra como **invitado** puede repetir su documento en varios pedidos: un
  pedido no es una cuenta.

## Si no aparece el campo de documento en la tienda

1. El módulo debe estar activo: **Plugins → HWE Customer Identity**.
2. El país de la tienda (Control Center → Comercio) debe tener tipos de documento. Si tu país no
   aparece, pide a quien mantiene la instancia que añada su paquete.
