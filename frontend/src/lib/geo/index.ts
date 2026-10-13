import "server-only";
import { cache } from "react";
import { GENERIC_PACK } from "./generic";
import { normalizeGeoPack } from "./normalize";
import type { GeoPack } from "./types";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { fetchWithRetry } from "@/lib/http/fetch-retry";
import { logReadFailure } from "@/lib/observability/read-failure";

export type { GeoPack, GeoDivision, GeoCity, GeoDocumentType } from "./types";
export { GENERIC_PACK } from "./generic";

const REVALIDATE_SECONDS = 300;

/**
 * Paquete del país de la tienda (`config.ecommerce.country`), servido por el
 * mu-plugin `hwe-geo`. Tag ISR `geo`.
 *
 * Cae al paquete genérico (texto libre) si WordPress no responde o contesta algo
 * que no cumple el contrato, y lo registra: el formulario sigue siendo usable,
 * solo pierde las ayudas del país.
 */
export const getGeoPack = cache(async (): Promise<GeoPack> => {
  try {
    const res = await fetchWithRetry(
      () =>
        wpFetch("/wp-json/hwe/v1/geo", {
          next: { revalidate: REVALIDATE_SECONDS, tags: ["geo"] },
        }),
      undefined,
      { label: "geo" },
    );
    if (!res.ok) {
      logReadFailure("geo.read_failed", `HTTP ${res.status}`, {
        message: "WordPress no sirvió el paquete de país: se usa el genérico",
      });
      return GENERIC_PACK;
    }
    const pack = normalizeGeoPack(await res.json());
    if (!pack) {
      logReadFailure("geo.read_failed", "respuesta fuera de contrato", {
        message: "El paquete de país no cumple el contrato: se usa el genérico",
      });
      return GENERIC_PACK;
    }
    return pack;
  } catch (error) {
    logReadFailure("geo.read_failed", error, {
      message: "No se pudo leer el paquete de país: se usa el genérico",
    });
    return GENERIC_PACK;
  }
});
