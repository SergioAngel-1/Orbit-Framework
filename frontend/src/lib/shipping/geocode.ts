import "server-only";
import { z } from "zod";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";
import type { Location } from "@/lib/validation/address";

// ============================================================================
//  Geocodificación para el mapa del checkout, desde el BFF.
//
//  La clave del proveedor vive solo en WordPress: el BFF llama a sus rutas internas
//  (`hwe-shipping/v1/geocode` y `reverse-geocode`) con `wpFetch`, que añade el secreto
//  interno. Aquí se reenvía solo lo que el contrato dice (lat, lng y la etiqueta). Cualquier
//  respuesta rara o un fallo de red es un error CERRADO: nunca un resultado vacío que parezca
//  correcto.
// ============================================================================

/** Límite de la búsqueda; WordPress lo aplica igual. */
export const GEOCODE_QUERY_MAX_LENGTH = 200;

/** Candidato de una búsqueda: solo lo que el mapa necesita. */
export interface GeocodeCandidate {
  lat: number;
  lng: number;
  label: string;
}

export type GeocodeOutcome<T> =
  { ok: true; value: T } | { ok: false; status: 422 | 503; code: string };

/** Códigos que WordPress puede devolver; cualquier otro se normaliza a `provider_unavailable`. */
const KNOWN_CODES = [
  "no_results",
  "invalid_query",
  "invalid_point",
  "geocoding_backoff",
  "provider_unavailable",
] as const;

const candidatesSchema = z.object({
  results: z
    .array(
      z.object({
        lat: z.number().finite(),
        lng: z.number().finite(),
        label: z.string(),
      }),
    )
    .max(5),
});

const labelSchema = z.object({ label: z.string() });

/** Busca una dirección (al enviar, nunca por tecla). */
export function geocodeAddress(
  query: string,
): Promise<GeocodeOutcome<GeocodeCandidate[]>> {
  return callWordPress(
    "/wp-json/hwe-shipping/v1/geocode",
    { query },
    candidatesSchema,
  ).then((outcome) =>
    outcome.ok
      ? {
          ok: true as const,
          value: outcome.value.results.map(({ lat, lng, label }) => ({
            lat,
            lng,
            label,
          })),
        }
      : outcome,
  );
}

/** Dirección legible de una coordenada, para confirmar el pin. */
export function reverseGeocodePoint(
  point: Location,
): Promise<GeocodeOutcome<{ label: string }>> {
  return callWordPress(
    "/wp-json/hwe-shipping/v1/reverse-geocode",
    { lat: point.lat, lng: point.lng },
    labelSchema,
  ).then((outcome) =>
    outcome.ok ? { ok: true as const, value: { label: outcome.value.label } } : outcome,
  );
}

async function callWordPress<T extends z.ZodTypeAny>(
  path: string,
  body: unknown,
  schema: T,
): Promise<GeocodeOutcome<z.infer<T>>> {
  let res: Response;
  try {
    res = await wpFetch(path, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body),
      cache: "no-store",
    });
  } catch (error) {
    logger.error(
      {
        event: "shipping.geocode.unreachable",
        err: error instanceof Error ? error.message : error,
      },
      "WordPress no respondió a la geocodificación del envío",
    );
    return { ok: false, status: 503, code: "provider_unavailable" };
  }

  const data: unknown = await res.json().catch(() => null);

  if (res.ok) {
    const parsed = schema.safeParse(data);
    if (parsed.success) return { ok: true, value: parsed.data };
    logger.error(
      { event: "shipping.geocode.invalid_response", path },
      "Respuesta de geocodificación con forma inesperada",
    );
    return { ok: false, status: 503, code: "provider_unavailable" };
  }

  const raw = (data as { code?: unknown } | null)?.code;
  const code = KNOWN_CODES.find((known) => known === raw) ?? "provider_unavailable";
  // 422 solo para lo que es culpa de la búsqueda (sin resultados, entrada inválida); lo demás
  // es un problema del servicio y va como 503.
  const inputProblem =
    code === "no_results" || code === "invalid_query" || code === "invalid_point";
  return { ok: false, status: inputProblem ? 422 : 503, code };
}
