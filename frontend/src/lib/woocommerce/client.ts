import "server-only";
import { currentRequestId } from "@/lib/observability/request-context";
import { internalSecretHeaders } from "@/lib/security/internal-secret";

// ============================================================================
//  Cliente de la WooCommerce REST API (wc/v3) — SOLO servidor.
//
//  Autenticación Basic con consumer key/secret. Estas credenciales viven
//  EXCLUSIVAMENTE en el servidor (env sin NEXT_PUBLIC_) y nunca se exponen al
//  navegador: el núcleo del proxy inverso (requisito #2).
// ============================================================================

const WC_API_URL = process.env.WC_API_URL ?? "http://wordpress:80/wp-json/wc/v3";
const WC_CONSUMER_KEY = process.env.WC_CONSUMER_KEY ?? "";

/**
 * WooCommerce solo acepta Basic con consumer key/secret cuando `is_ssl()` es true. El BFF
 * habla con WordPress por HTTP en la red interna (`http://wordpress:80`) y el `wp-config`
 * del contenedor ya trata `X-Forwarded-Proto: https` como HTTPS: sin esa cabecera, TODA
 * llamada a wc/v3 (pedidos, cuenta, direcciones, reseñas y marcar pagado un pedido desde el
 * webhook de la pasarela) respondía 401 `woocommerce_rest_cannot_view`, mientras GraphQL y
 * la Store API (que no usan ck/cs) seguían funcionando.
 */
const WC_FORWARD_HTTPS = WC_API_URL.startsWith("http://");
const WC_CONSUMER_SECRET = process.env.WC_CONSUMER_SECRET ?? "";

/** Error normalizado de WooCommerce con su código HTTP. */
export class WooCommerceError extends Error {
  readonly status: number;
  readonly code?: string;
  /**
   * Datos estructurados del error, cuando el endpoint los envía (la Store API
   * de WooCommerce los publica en `data`: `days_left`, `level_slug`…). No se
   * reenvían al navegador tal cual: cada lector elige su lista blanca.
   */
  readonly data?: unknown;

  constructor(message: string, status: number, code?: string, data?: unknown) {
    super(message);
    this.name = "WooCommerceError";
    this.status = status;
    this.code = code;
    this.data = data;
  }
}

function authorizationHeader(): string {
  const token = Buffer.from(`${WC_CONSUMER_KEY}:${WC_CONSUMER_SECRET}`).toString(
    "base64",
  );
  return `Basic ${token}`;
}

export interface WcRequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  query?: Record<string, string | number | boolean | undefined>;
  body?: unknown;
  timeoutMs?: number;
  retries?: number;
}

/**
 * Ejecuta una petición autenticada contra la WooCommerce REST API (wc/v3).
 *
 * - Timeout por petición vía AbortController.
 * - Reintentos solo en GET ante errores de red / 5xx (operaciones idempotentes).
 * - Errores de WooCommerce normalizados a `WooCommerceError`.
 *
 * @throws WooCommerceError
 */
export async function wcFetch<T>(
  path: string,
  options: WcRequestOptions = {},
): Promise<T> {
  return (await wcRequest<T>(path, options)).data;
}

/** Una página de una colección de wc/v3, con sus totales. */
export interface WcPage<T> {
  items: T[];
  /** `X-WP-Total`: elementos de toda la colección con esos filtros. */
  total: number;
  /** `X-WP-TotalPages`. */
  totalPages: number;
}

/** Cabecera numérica de paginación; `null` si no viene o no es un entero. */
function paginationHeader(headers: Headers, name: string): number | null {
  const raw = headers.get(name);
  if (raw === null || !/^\d+$/.test(raw.trim())) return null;
  return Number(raw);
}

/**
 * Como `wcFetch`, para colecciones: conserva los totales que WooCommerce manda en las
 * cabeceras. Sin ellas (un proxy que las quita), los totales describen solo lo recibido.
 *
 * @throws WooCommerceError
 */
export async function wcFetchPage<T>(
  path: string,
  options: WcRequestOptions = {},
): Promise<WcPage<T>> {
  const { data, headers } = await wcRequest<T[] | null>(path, options);
  const items = data ?? [];
  return {
    items,
    total: paginationHeader(headers, "x-wp-total") ?? items.length,
    totalPages: paginationHeader(headers, "x-wp-totalpages") ?? (items.length ? 1 : 0),
  };
}

async function wcRequest<T>(
  path: string,
  options: WcRequestOptions,
): Promise<{ data: T; headers: Headers }> {
  if (!WC_CONSUMER_KEY || !WC_CONSUMER_SECRET) {
    throw new WooCommerceError(
      "Credenciales de WooCommerce no configuradas (WC_CONSUMER_KEY/SECRET).",
      500,
      "wc_no_credentials",
    );
  }

  const { method = "GET", query, body, timeoutMs = 10_000, retries = 2 } = options;

  const url = new URL(`${WC_API_URL}${path}`);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined) {
        url.searchParams.set(key, String(value));
      }
    }
  }

  let attempt = 0;
  let lastError: unknown;

  while (attempt <= retries) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const rid = currentRequestId();
      const response = await fetch(url, {
        method,
        headers: {
          Authorization: authorizationHeader(),
          "Content-Type": "application/json",
          Accept: "application/json",
          ...(WC_FORWARD_HTTPS ? { "X-Forwarded-Proto": "https" } : {}),
          ...(rid ? { "X-Request-Id": rid } : {}),
          ...internalSecretHeaders(),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: controller.signal,
        cache: "no-store",
      });
      clearTimeout(timer);

      const text = await response.text();
      const data = text ? JSON.parse(text) : null;

      if (!response.ok) {
        const wcError = data as { message?: string; code?: string } | null;
        throw new WooCommerceError(
          wcError?.message ?? `Error de WooCommerce (${response.status}).`,
          response.status,
          wcError?.code,
        );
      }

      return { data: data as T, headers: response.headers };
    } catch (error) {
      clearTimeout(timer);
      lastError = error;

      // Solo reintentamos GET ante red caída o 5xx. Los 4xx no se reintentan.
      const isClientError = error instanceof WooCommerceError && error.status < 500;
      const retriable = method === "GET" && !isClientError;

      if (!retriable || attempt === retries) {
        break;
      }
      attempt += 1;
      await new Promise((resolve) => setTimeout(resolve, 200 * attempt));
    }
  }

  if (lastError instanceof WooCommerceError) {
    throw lastError;
  }
  throw new WooCommerceError(
    "No se pudo contactar con WooCommerce.",
    502,
    "wc_network",
  );
}
