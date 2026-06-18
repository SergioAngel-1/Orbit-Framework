import "server-only";

// ============================================================================
//  Cliente de la WooCommerce REST API (wc/v3) — SOLO servidor.
//
//  Autenticación Basic con consumer key/secret. Estas credenciales viven
//  EXCLUSIVAMENTE en el servidor (env sin NEXT_PUBLIC_) y nunca se exponen al
//  navegador: el núcleo del proxy inverso (requisito #2).
// ============================================================================

const WC_API_URL = process.env.WC_API_URL ?? "http://wordpress:80/wp-json/wc/v3";
const WC_CONSUMER_KEY = process.env.WC_CONSUMER_KEY ?? "";
const WC_CONSUMER_SECRET = process.env.WC_CONSUMER_SECRET ?? "";

/** Error normalizado de WooCommerce con su código HTTP. */
export class WooCommerceError extends Error {
  readonly status: number;
  readonly code?: string;

  constructor(message: string, status: number, code?: string) {
    super(message);
    this.name = "WooCommerceError";
    this.status = status;
    this.code = code;
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
      const response = await fetch(url, {
        method,
        headers: {
          Authorization: authorizationHeader(),
          "Content-Type": "application/json",
          Accept: "application/json",
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

      return data as T;
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
