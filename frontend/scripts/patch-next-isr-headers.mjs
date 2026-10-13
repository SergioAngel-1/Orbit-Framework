#!/usr/bin/env node
// ============================================================================
//  Next 16.4 emite DOS veces las cabeceras que el render fijó, cuando una
//  página ISR se renderiza en frío (`x-nextjs-cache: MISS`).
//
//  El handler de las páginas de App Router (`build/templates/app-page-runtime`)
//  sirve siempre desde la entrada de caché. En un MISS la entrada acaba de nacer
//  de un render, y ese render ya dejó sus cabeceras en `res` con `setHeader` —el
//  `Location` de un `permanentRedirect`, sobre todo—. Después, el handler recorre
//  `cachedData.headers` (las mismas, copiadas a la entrada) y las vuelve a añadir
//  con `appendHeader`: dos `Location` idénticos. Con HIT o STALE no hubo render,
//  `res` está limpio y sale uno.
//
//  REPRODUCIDO en Next 16.4.0 (el que fija este repo): una página ISR con
//  `generateStaticParams() → []` y `permanentRedirect`, tres peticiones en frío:
//  tres MISS con `location` doble, y un HIT con uno. Curl y los navegadores
//  toleran dos valores idénticos; un cliente que fusiona cabeceras (Python, Node,
//  las herramientas de SEO) lee `/blog/x, /blog/x`, lo sigue y obtiene un 404. Y
//  como las claves de página llevan el BUILD_ID, cada despliegue deja en frío
//  todas las URLs: la primera visita de cada una (también la de un rastreador)
//  cae justo en el caso defectuoso. Afecta a cualquier redirección que se
//  resuelva dentro de una página ISR (una ficha o una entrada que redirige a su
//  slug canónico, una URL heredada).
//
//  Este script hace que el bucle salte los valores que `res` YA lleva, cabecera
//  por cabecera. Lo que no estaba sigue añadiéndose (los `Link` de precarga que
//  solo vienen de la entrada, un `Set-Cookie` nuevo) y no cambia nada con
//  HIT/STALE. No toca las cabeceras multivalor legítimas: solo descarta un valor
//  idéntico a uno ya presente.
//
//  Se arregla aquí y no en Caddy porque un proxy no puede deshacer de forma
//  fiable un valor duplicado que el origen ya emitió, y esto corrige además
//  cualquier otra redirección ISR.
//
//  Va en `postinstall` (sin `patch-package`; CJS y ESM): el standalone de la
//  imagen de producción hereda el archivo parcheado porque se construye
//  (`next build`) DESPUÉS de la instalación. El Dockerfile copia `scripts/` ANTES
//  de `npm ci` por este motivo.
//
//  NO es tolerante con una versión de Next que no sea la que se comprobó: si
//  `next` cambia, esto FALLA la instalación, con el motivo. Hay que volver a
//  reproducir el problema con la versión nueva y, si ya no ocurre, retirar el
//  script de `postinstall` y su test; si sigue, actualizar `SUPPORTED_NEXT` (y
//  `ORIGINAL_LOOP` si el código cambió).
// ============================================================================
import { readFileSync, writeFileSync, existsSync } from "node:fs";
import { pathToFileURL } from "node:url";

/** Versión de Next contra la que se reprodujo y comprobó el parche. */
export const SUPPORTED_NEXT = "16.4.0";

export const TARGETS = [
  "node_modules/next/dist/build/templates/app-page-runtime.js",
  "node_modules/next/dist/esm/build/templates/app-page-runtime.js",
];

/** Marca que deja el parche: hace el script idempotente. */
export const MARKER = "[hwe] patch-next-isr-headers";

/** El bucle original, idéntico en CJS y ESM (16.4.0). */
const ORIGINAL_LOOP = `                    for (let [key, value] of Object.entries(headers)){
                        if (typeof value === 'undefined') continue;
                        if (Array.isArray(value)) {
                            for (const v of value){
                                res.appendHeader(key, v);
                            }
                        } else if (typeof value === 'number') {
                            value = value.toString();
                            res.appendHeader(key, value);
                        } else {
                            res.appendHeader(key, value);
                        }
                    }
`;

/**
 * El bucle que lo sustituye. Es una cadena de código (no una función) porque
 * se inyecta tal cual en el archivo de Next; el test la ejecuta con un `res` y
 * unas `headers` de pega.
 */
export const PATCHED_LOOP = `                    // ${MARKER}: salta los valores que \`res\` ya lleva (ver frontend/scripts/patch-next-isr-headers.mjs)
                    for (const [key, value] of Object.entries(headers)){
                        if (typeof value === 'undefined') continue;
                        const present = [].concat(res.getHeader(key) ?? []).map(String);
                        for (const v of [].concat(value).map(String)){
                            if (present.includes(v)) continue;
                            present.push(v);
                            res.appendHeader(key, v);
                        }
                    }
`;

/**
 * Devuelve `{ status, source }`:
 *  - `patched`: se aplicó el parche.
 *  - `already`: ya estaba aplicado.
 *  - `unknown`: el código no es el esperado; `source` sale intacto.
 */
export function patchSource(source) {
  if (source.includes(MARKER)) return { status: "already", source };
  if (!source.includes(ORIGINAL_LOOP)) return { status: "unknown", source };
  return {
    status: "patched",
    source: source.replace(ORIGINAL_LOOP, PATCHED_LOOP),
  };
}

/** Versión de Next instalada, o null si no hay (script corrido fuera de `frontend/`). */
function installedNextVersion() {
  try {
    return JSON.parse(readFileSync("node_modules/next/package.json", "utf8")).version;
  } catch {
    return null;
  }
}

function main() {
  const version = installedNextVersion();
  // Instalación sin Next, o script corrido fuera de `frontend/`.
  if (version === null) return 0;

  if (version !== SUPPORTED_NEXT) {
    console.error(
      `next/app-page: el parche de cabeceras ISR se comprobó con Next ${SUPPORTED_NEXT} y hay ${version}.\n` +
        "  Reproduce el problema con esta versión (una página ISR con permanentRedirect\n" +
        "  y generateStaticParams vacío: ¿sale `location` dos veces en un MISS?).\n" +
        "  Si ya no ocurre, retira scripts/patch-next-isr-headers.mjs de `postinstall` y\n" +
        "  su test. Si ocurre, actualiza SUPPORTED_NEXT (y ORIGINAL_LOOP si cambió el código).",
    );
    return 1;
  }

  let unknown = 0;
  for (const target of TARGETS) {
    if (!existsSync(target)) continue;
    const { status, source } = patchSource(readFileSync(target, "utf8"));
    if (status === "patched") {
      writeFileSync(target, source, "utf8");
      console.log(
        `next/app-page: cabeceras de la entrada de caché sin duplicar en ${target}.`,
      );
    } else if (status === "unknown") {
      unknown += 1;
    }
  }
  if (unknown > 0) {
    console.error(
      "next/app-page: no se reconoció el volcado de `cachedData.headers` en Next " +
        `${version}.\n  El código cambió dentro de la misma versión: actualiza ORIGINAL_LOOP.`,
    );
    return 1;
  }
  return 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  process.exit(main());
}
