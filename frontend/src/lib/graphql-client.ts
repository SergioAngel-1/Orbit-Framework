import "server-only";
import { logger } from "@/lib/observability/logger";
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

export type GraphQLErrorKind = "network" | "timeout" | "http" | "graphql" | "empty";

/** Error tipado del cliente GraphQL: permite distinguir red / HTTP / GraphQL sin parsear mensajes. */
export class GraphQLClientError extends Error {
  readonly kind: GraphQLErrorKind;
  readonly status?: number;
  readonly errors?: GraphQLErrorShape[];

  constructor(
    kind: GraphQLErrorKind,
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

/** Nombre de la operación (`query Product(...)` → `Product`), para el log. */
export function operationName(query: string): string {
  return /\b(?:query|mutation)\s+(\w+)/.exec(query)?.[1] ?? "anonymous";
}

/** Intentos totales antes de propagar el error (1 intento inicial + 2 reintentos). */
const MAX_ATTEMPTS = 3;

/**
 * Plazo POR INTENTO, en ms.
 *
 * Sin él, un WordPress que acepta la conexión pero no responde (saturado, con
 * una query bloqueada en MySQL) deja el render del RSC colgado para siempre:
 * WPGraphQL es la fuente de datos de TODA la web, así que unas pocas peticiones
 * así agotan el pool de Next sin que ningún healthcheck se entere. Con el plazo,
 * el fallo es acotado y además *reintentable* (ver `isRetryable`).
 *
 * Ajustable con `GRAPHQL_TIMEOUT_MS` para builds grandes o backends lentos. Una
 * petición abortada por plazo NO entra en el Data Cache de Next: si WordPress
 * tarda más que esto, no falla una página sino todas.
 */
const DEFAULT_TIMEOUT_MS = 10_000;

function requestTimeoutMs(): number {
  const raw = Number(process.env.GRAPHQL_TIMEOUT_MS);
  return Number.isFinite(raw) && raw > 0 ? raw : DEFAULT_TIMEOUT_MS;
}

/** ¿El fallo del `fetch` fue nuestro propio plazo (o una cancelación)? */
function isAbortError(error: unknown): boolean {
  return (
    typeof error === "object" &&
    error !== null &&
    "name" in error &&
    (error.name === "TimeoutError" || error.name === "AbortError")
  );
}

/** Backoff exponencial entre intentos: 250 ms, 500 ms. */
function backoffMs(attemptIndex: number): number {
  return 250 * 2 ** attemptIndex;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Solo tiene sentido reintentar fallos transitorios: caída de red, plazo vencido
 * o un 5xx (WordPress saturado). Un error GraphQL de validación o un 4xx son
 * deterministas — reintentarlos solo alarga el build sin cambiar el resultado.
 */
function isRetryable(error: GraphQLClientError): boolean {
  if (error.kind === "network" || error.kind === "timeout") return true;
  return error.kind === "http" && (error.status ?? 0) >= 500;
}

/**
 * Un único intento de petición + parseo. Lanza `GraphQLClientError` tipado.
 *
 * El `AbortSignal` se crea AQUÍ (uno por intento, no uno compartido en el
 * `init`): un signal ya disparado aborta instantáneamente todos los reintentos
 * posteriores, que es justo lo contrario de lo que se busca.
 */
async function attemptFetchGraphQL<TData>(
  endpoint: string,
  init: RequestInit,
): Promise<TData> {
  let response: Response;
  try {
    response = await fetch(endpoint, {
      ...init,
      signal: AbortSignal.timeout(requestTimeoutMs()),
    });
  } catch (cause) {
    if (isAbortError(cause)) {
      throw new GraphQLClientError(
        "timeout",
        `El endpoint GraphQL no respondió en ${requestTimeoutMs()} ms.`,
        { cause },
      );
    }
    throw new GraphQLClientError(
      "network",
      "No se pudo conectar con el endpoint GraphQL.",
      { cause },
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

  // El plazo también cubre la LECTURA del cuerpo (undici aborta el stream), así
  // que este parseo puede fallar por corte a mitad, no solo por JSON malformado.
  // Se clasifica igual que el fetch para que un corte sea reintentable.
  let json: GraphQLResponse<TData>;
  try {
    json = (await response.json()) as GraphQLResponse<TData>;
  } catch (cause) {
    if (isAbortError(cause)) {
      throw new GraphQLClientError(
        "timeout",
        `El endpoint GraphQL cortó la respuesta a los ${requestTimeoutMs()} ms.`,
        { cause },
      );
    }
    throw new GraphQLClientError(
      "empty",
      "La respuesta de GraphQL no es JSON válido.",
      {
        cause,
      },
    );
  }

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

/**
 * Ejecuta una operación GraphQL contra WordPress (WPGraphQL).
 *
 * Reintenta hasta {@link MAX_ATTEMPTS} veces (con backoff exponencial) los
 * fallos transitorios (red, plazo o HTTP 5xx): un WordPress momentáneamente
 * saturado durante el build no hornea páginas rotas. Los errores
 * deterministas (GraphQL, 4xx, respuesta vacía) se propagan al primer intento.
 *
 * @throws GraphQLClientError si, agotados los intentos, la petición sigue sin
 *   poder completarse.
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

  const init: RequestInit = {
    method: "POST",
    headers: { ...headers, ...internalSecretHeaders() },
    body: JSON.stringify({ query, variables }),
    // Integración con la caché de Next.js (ISR / revalidación on-demand).
    next: {
      ...(typeof revalidate === "number" ? { revalidate } : {}),
      ...(tags && tags.length > 0 ? { tags } : {}),
    },
  } as RequestInit;

  // Las mutations no son idempotentes de forma segura: un 5xx después de que
  // WordPress ya persistió la escritura no debe reintentarse (podría parecer un
  // fallo cuando en realidad ya se aplicó). Solo las lecturas se reintentan.
  const isMutation = /^\s*mutation\b/i.test(query);
  const maxAttempts = isMutation ? 1 : MAX_ATTEMPTS;
  const endpoint = wpGraphqlUrl();

  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      return await attemptFetchGraphQL<TData>(endpoint, init);
    } catch (error) {
      const isLastAttempt = attempt === maxAttempts - 1;
      if (
        !(error instanceof GraphQLClientError) ||
        !isRetryable(error) ||
        isLastAttempt
      ) {
        // Un solo registro por operación fallida, antes de propagar: muchos
        // lectores degradan a un valor por defecto y no deben hacerlo mudos.
        logger.warn(
          {
            event: "graphql.request_failed",
            operation: operationName(query),
            kind: error instanceof GraphQLClientError ? error.kind : "unexpected",
            status: error instanceof GraphQLClientError ? error.status : undefined,
            attempts: attempt + 1,
            err: error instanceof Error ? error.message : String(error),
          },
          "La lectura de WordPress (GraphQL) falló",
        );
        throw error;
      }
      await sleep(backoffMs(attempt));
    }
  }

  // Inalcanzable: el bucle siempre retorna o lanza en su última iteración.
  throw new GraphQLClientError("network", "fetchGraphQL: reintentos agotados.");
}
