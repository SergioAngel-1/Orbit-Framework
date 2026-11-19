// ============================================================================
//  Caché del router del cliente para vistas dinámicas, por variable de entorno.
//
//  Con `experimental.staleTimes.dynamic` a 0 (el defecto de Next), volver con un
//  `<Link>` o `router.push` a una vista dinámica ya visitada —la cuenta, el
//  carrito, un listado con sesión— vuelve a pedir el render al servidor, y cada
//  render de servidor son lecturas a WordPress. Con N segundos, una vista visitada
//  hace menos de N se reutiliza tal cual en el navegador. Atrás/adelante ya la
//  reutilizan siempre; esto lo extiende a la navegación normal.
//
//  Por eso es OPT-IN: solo es seguro si cada escritura hecha desde el navegador
//  (fetch a `/api/*`) termina en `router.refresh()` —que vacía esa caché— o en una
//  recarga completa, o si los datos que cambia no los pinta el servidor. Las vistas
//  y componentes son de cada instancia: antes de activarla, la instancia revisa los
//  suyos (`docs/CONFIGURATION.md`). Sin la variable, la config queda como estaba.
//
//  Es una variable de BUILD: Next hornea esta config en el JS del cliente.
// ============================================================================

/** Techo: más de 5 minutos de una vista con sesión vieja no es un ajuste, es un error. */
export const MAX_DYNAMIC_STALE_SECONDS = 300;

/** El `static` de Next por defecto; se repite porque `staleTimes` se fija entero. */
const DEFAULT_STATIC_STALE_SECONDS = 300;

/**
 * `experimental.staleTimes` derivado de `NEXT_ROUTER_STALE_DYNAMIC` (segundos,
 * entero de 1 a 300), o `{}` si la variable no está o no vale (con aviso): en ese
 * caso Next sigue con sus defectos (dynamic 0, static 300).
 *
 * @param {Record<string, string | undefined>} env
 * @param {(message: string) => void} warn
 * @returns {{ staleTimes?: { dynamic: number, static: number } }}
 */
export function routerStaleTimes(env = {}, warn = console.warn) {
  const raw = env.NEXT_ROUTER_STALE_DYNAMIC;
  if (raw === undefined || raw === null || String(raw).trim() === "") return {};

  const seconds = Number(String(raw).trim());
  if (
    !Number.isInteger(seconds) ||
    seconds < 1 ||
    seconds > MAX_DYNAMIC_STALE_SECONDS
  ) {
    warn(
      `NEXT_ROUTER_STALE_DYNAMIC: se ignora «${raw}»; debe ser un entero de 1 a ` +
        `${MAX_DYNAMIC_STALE_SECONDS} (segundos). Sigue el defecto de Next (0).`,
    );
    return {};
  }
  return { staleTimes: { dynamic: seconds, static: DEFAULT_STATIC_STALE_SECONDS } };
}
