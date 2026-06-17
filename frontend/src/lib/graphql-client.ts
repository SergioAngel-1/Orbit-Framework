// ============================================================================
//  Cliente GraphQL ligero basado en `fetch` nativo.
//
//  ¿Por qué fetch y no Apollo Client?
//   - El App Router de Next.js extiende `fetch` con su capa de caché/ISR
//     (`next: { revalidate }`), lo que permite Incremental Static Regeneration
//     sin librerías adicionales ni "use client".
//   - Funciona de forma nativa en React Server Components.
//   - Cero peso extra en el bundle del cliente.
//
//  Resolución de URL:
//   - En el SERVIDOR (SSR/ISR/RSC) usamos la red interna de Docker
//     (`WORDPRESS_INTERNAL_API_URL` -> http://wordpress:80/graphql).
//   - En el NAVEGADOR usamos la URL pública (`NEXT_PUBLIC_WORDPRESS_API_URL`).
// ============================================================================

const SERVER_ENDPOINT =
  process.env.WORDPRESS_INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_WORDPRESS_API_URL ??
  "http://localhost:8080/graphql";

const CLIENT_ENDPOINT =
  process.env.NEXT_PUBLIC_WORDPRESS_API_URL ?? "http://localhost:8080/graphql";

/** Devuelve el endpoint adecuado según el entorno de ejecución. */
function getEndpoint(): string {
  return typeof window === "undefined" ? SERVER_ENDPOINT : CLIENT_ENDPOINT;
}

export interface GraphQLRequestOptions {
  /** Variables de la query/mutation. */
  variables?: Record<string, unknown>;
  /**
   * Segundos de revalidación para ISR. `undefined` deja el comportamiento
   * por defecto de Next; `0` fuerza datos siempre frescos (sin caché).
   */
  revalidate?: number;
  /** Token JWT opcional para peticiones autenticadas (WPGraphQL JWT Auth). */
  authToken?: string;
  /** Etiquetas de caché para revalidación on-demand (revalidateTag). */
  tags?: string[];
}

interface GraphQLErrorShape {
  message: string;
  locations?: { line: number; column: number }[];
  path?: (string | number)[];
}

interface GraphQLResponse<T> {
  data?: T;
  errors?: GraphQLErrorShape[];
}

/**
 * Ejecuta una operación GraphQL contra WordPress (WPGraphQL).
 *
 * @throws Error si la respuesta HTTP no es OK o si GraphQL devuelve `errors`.
 */
export async function fetchGraphQL<TData>(
  query: string,
  options: GraphQLRequestOptions = {},
): Promise<TData> {
  const { variables, revalidate, authToken, tags } = options;

  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
  };

  if (authToken) {
    headers.Authorization = `Bearer ${authToken}`;
  }

  const response = await fetch(getEndpoint(), {
    method: "POST",
    headers,
    body: JSON.stringify({ query, variables }),
    // Integración con la caché de Next.js (ISR / revalidación on-demand).
    next: {
      ...(typeof revalidate === "number" ? { revalidate } : {}),
      ...(tags && tags.length > 0 ? { tags } : {}),
    },
  });

  if (!response.ok) {
    const text = await response.text().catch(() => "");
    throw new Error(
      `Error HTTP de GraphQL: ${response.status} ${response.statusText}. ${text}`,
    );
  }

  const json = (await response.json()) as GraphQLResponse<TData>;

  if (json.errors && json.errors.length > 0) {
    const message = json.errors.map((e) => e.message).join(" | ");
    throw new Error(`Error de GraphQL: ${message}`);
  }

  if (!json.data) {
    throw new Error("La respuesta de GraphQL no contiene datos.");
  }

  return json.data;
}
