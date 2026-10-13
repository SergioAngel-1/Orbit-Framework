/**
 * Atributo que excluye un `<form>` de la captura de formularios de HubSpot
 * («Formularios no HubSpot»). Con esa opción activa en el portal —lo está en
 * producción: el loader del portal carga `collectedforms.js`—, el script se
 * engancha al `submit` de CUALQUIER formulario del sitio y manda lo escrito
 * al CRM como contacto. Eso va más allá de la «analítica» que nombra el
 * banner, así que los formularios con credenciales o datos personales
 * sensibles (acceso, registro, cuenta, checkout con documento y dirección,
 * reseñas) lo llevan; el de contacto y el de la newsletter no, porque dejar
 * el contacto es justo su propósito.
 *
 * `collectedforms.js` lo comprueba así (leído en su código el 2026-09-28):
 * `hasAttribute("hs-do-not-collect") || hasAttribute("data-hs-do-not-collect")`.
 * La documentación pública de HubSpot no lo menciona: si un día deja de
 * funcionar, la alternativa es apagar la opción en el portal.
 */
export const HUBSPOT_DO_NOT_COLLECT = { "data-hs-do-not-collect": "true" } as const;
