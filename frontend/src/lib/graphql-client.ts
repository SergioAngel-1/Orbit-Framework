import "server-only";
import { internalSecretHeaders } from "@/lib/security/internal-secret";
import { wpGraphqlUrl } from "@/lib/wordpress/internal-fetch";

// ============================================================================
//  Cliente GraphQL ligero basado en `fetch` nativo — SOLO servidor.
//
//  ¿Por qué fetch y no Apollo Client?
//   - El App Router de Next.js extiende `fetch` con su capa de caché/ISR
//     (`next: { revalidate }`), lo que permite Incremental Static Regeneration
//     sin librerías adicionales.
//   - Funciona de forma nativa en React Server Components.
//   - Cero peso extra en el bundle del cliente: aquí no hay "use client".
//
//  El navegador NUNCA habla con WordPress: todo pasa por el BFF (`/api/*`). Por
//  eso este cliente ya no tiene rama de navegador ni URL pública, y siempre
//  presenta el secreto interno: el mu-plugin `hwe-bff-only` de WordPress responde
//  404 a GraphQL sin él. El endpoint es `WORDPRESS_INTERNAL_API_URL` (red interna).
// ============================================================================

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

/** Error tipado del cliente GraphQL: permite distinguir red / HTTP / GraphQL sin parsear mensajes. */
export class GraphQLClientError extends Error {
  readonly kind: "network" | "http" | "graphql" | "empty";
  readonly status?: number;
  readonly errors?: GraphQLErrorShape[];

  constructor(
    kind: "network" | "http" | "graphql" | "empty",
    message: string,
    extras: { status?: number; errors?: GraphQLErrorShape[]; cause?: unknown } = {},
  ) {
    super(message, extras.cause !== undefined ? { cause: extras.cause } : undefined);
    this.name = "GraphQLClientError";
    this.kind = kind;
    this.status = extras.status;
    this.errors = extras.errors;
  }
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

  // Propaga el request-id (si hay contexto) para correlacionar este request con los
  // logs de WordPress.
  try {
    const { currentRequestId } = await import("@/lib/observability/request-context");
    const rid = currentRequestId();
    if (rid) headers["X-Request-Id"] = rid;
  } catch {
    /* sin contexto: no añadimos cabecera */
  }

  let response: Response;
  try {
    response = await fetch(wpGraphqlUrl(), {
      method: "POST",
      headers: { ...headers, ...internalSecretHeaders() },
      body: JSON.stringify({ query, variables }),
      // Integración con la caché de Next.js (ISR / revalidación on-demand).
      next: {
        ...(typeof revalidate === "number" ? { revalidate } : {}),
        ...(tags && tags.length > 0 ? { tags } : {}),
      },
    });
  } catch (cause) {
    throw new GraphQLClientError(
      "network",
      "No se pudo conectar con el endpoint GraphQL.",
      {
        cause,
      },
    );
  }

  if (!response.ok) {
    const text = await response.text().catch(() => "");
    throw new GraphQLClientError(
      "http",
      `Error HTTP de GraphQL: ${response.status} ${response.statusText}. ${text}`,
      { status: response.status },
    );
  }

  const json = (await response.json()) as GraphQLResponse<TData>;

  if (json.errors && json.errors.length > 0) {
    const message = json.errors.map((e) => e.message).join(" | ");
    throw new GraphQLClientError("graphql", `Error de GraphQL: ${message}`, {
      errors: json.errors,
    });
  }

  if (!json.data) {
    throw new GraphQLClientError("empty", "La respuesta de GraphQL no contiene datos.");
  }

  return json.data;
}
