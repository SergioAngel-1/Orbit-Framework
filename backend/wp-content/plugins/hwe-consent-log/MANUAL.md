# Consentimientos de cookies — manual del equipo

Este módulo guarda **la prueba** de lo que cada visitante eligió en el banner de cookies, por si
alguna vez hay que demostrarlo (una reclamación, una auditoría), y te dice cuánta gente acepta.

## Dónde verlo

**Ajustes → Consentimientos de cookies.** La pantalla tiene dos partes:

1. **Respuesta al banner** (arriba): de las visitas que vieron el banner, cuántas aceptaron todas,
   cuántas eligieron solo las necesarias y cuántas no respondieron. Son totales: no identifican a
   nadie ni sirven como prueba. Cambia el periodo (7, 14, 30 o 90 días) con los enlaces.
2. **Registro de decisiones** (debajo): una fila por decisión, con fecha, acción, qué aceptó, qué
   servicios pedía el banner, el texto exacto que vio y la versión de la política de cookies.

Para buscar a una persona usa su **email**, su **ID de usuario** o el **comprobante** (el código
de su dispositivo). «Exportar CSV» descarga lo que filtras.

## Qué significa cada acción

| Acción | Cuándo |
|---|---|
| Primera respuesta | La persona respondió al banner por primera vez. |
| Cambio | Cambió su decisión desde «Preferencias de cookies» del pie. |
| Retirada | Cambió de aceptar a solo necesarias. |
| Vinculado a la cuenta | Inició sesión o se registró y su dispositivo quedó ligado a su cuenta. |

Una decisión marcada «anterior: ya no vale» es de antes de un reinicio del consentimiento: el banner
volvió a preguntar y la que cuenta es la nueva.

## Datos personales

- El registro guarda la IP **truncada** (el último tramo se pone a cero), no la completa.
- Si una persona pide **borrar sus datos** (Herramientas → Borrar datos personales) o se elimina su
  cuenta, sus decisiones **no se borran**: se **desvinculan** de la cuenta y quedan como prueba
  anónima. Es lo que permite demostrar el consentimiento sin conservar a la persona.
- Cuánto tiempo se conserva: **HWE Control Center → Integraciones → «Conservar el registro de
  consentimientos (años)»**, 5 por defecto; 0 = no borrar nunca. Se cuenta desde la **última**
  decisión de cada dispositivo. Confírmalo con tu asesor legal.

## Si algo no aparece

1. El módulo debe estar activo: **Plugins → HWE Consent Log**.
2. La medición arriba necesita el frontend y Redis; si no responden, la pantalla lo dice y el
   registro de debajo sigue funcionando.
3. «El frontend rechazó la consulta»: `HWE_REVALIDATION_SECRET` no coincide entre WordPress y el
   frontend.
