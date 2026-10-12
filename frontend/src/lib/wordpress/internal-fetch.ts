import "server-only";
import {
  INTERNAL_SECRET_HEADER,
  internalSecretHeaders,
} from "@/lib/security/internal-secret";

// ============================================================================
//  Llamadas del BFF a WordPress por la red interna — SOLO servidor.
//
//  Con el mu-plugin `hwe-bff-only`, WordPress rechaza (404) GraphQL, la Store API
//  y `hwe/v1/auth|consent` sin el secreto interno. Para que NINGUNA llamada se
//  olvide de él, las rutas y librerías no construyen URLs de WordPress ni llaman a
//  `fetch` por su cuenta: usan `wpFetch`. Los clientes de GraphQL, wc/v3 y Store API
//  hacen lo mismo con `internalSecretHeaders()`. La guarda
//  `lib/security/wordpress-calls.test.ts` lo comprueba sobre todo el código.
// ============================================================================

const DEFAULT_GRAPHQL_URL = "http://wordpress:80/graphql";

/** ¿Hay un WordPress configurado de forma explícita? (Si no, se usa el servicio de Docker por defecto.) */
export function wpIsConfigured(): boolean {
  return Boolean(process.env.WORDPRESS_INTERNAL_API_URL);
}

/** Endpoint GraphQL de WordPress por la red interna (`WORDPRESS_INTERNAL_API_URL`). */
export function wpGraphqlUrl(): string {
  return (process.env.WORDPRESS_INTERNAL_API_URL || DEFAULT_GRAPHQL_URL).replace(
    /\/$/,
    "",
  );
}

/** Base de WordPress por la red interna: la URL de GraphQL sin `/graphql`. */
export function wpBaseUrl(): string {
  return wpGraphqlUrl().replace(/\/graphql$/, "");
}

export type WpFetchInit = RequestInit & {
  /** Opciones de la capa de caché de Next (ISR / revalidación por tag). */
  next?: { revalidate?: number | false; tags?: string[] };
};

/** Cabeceras como objeto plano, sin la del secreto (se vuelve a poner siempre la nuestra). */
function withoutInternalSecret(
  headers: HeadersInit | undefined,
): Record<string, string> {
  const out: Record<string, string> = {};
  if (!headers) return out;
  const entries =
    headers instanceof Headers
      ? Array.from(headers.entries())
      : Array.isArray(headers)
        ? headers
        : Object.entries(headers);
  for (const [name, value] of entries) {
    if (name.toLowerCase() !== INTERNAL_SECRET_HEADER.toLowerCase()) out[name] = value;
  }
  return out;
}

/**
 * `fetch` a una ruta de WordPress (`/wp-json/…`) con el secreto interno. El llamador no
 * puede sustituirlo: se descarta cualquier cabecera con ese nombre, con cualquier
 * capitalización, y se pone la propia.
 */
export function wpFetch(path: string, init: WpFetchInit = {}): Promise<Response> {
  return fetch(`${wpBaseUrl()}${path}`, {
    ...init,
    headers: { ...withoutInternalSecret(init.headers), ...internalSecretHeaders() },
  });
}
