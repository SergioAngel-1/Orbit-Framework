import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";
import { geocodeAddress, reverseGeocodePoint } from "./geocode";

// Geocodificación para el mapa del cliente: el BFF la pide a WordPress (que es quien tiene la
// clave del proveedor). Aquí se prueba lo que el BFF hace con la respuesta: solo reenvía lo que
// el contrato dice, y todo lo raro o caído sale como fallo cerrado, nunca como un resultado.

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

function wpResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("geocodeAddress", () => {
  it("pide la búsqueda a WordPress por la ruta interna, por POST y sin caché", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      wpResponse(200, { results: [{ lat: 4.65, lng: -74.08, label: "Calle 1" }] }),
    );

    await geocodeAddress("Calle 1 #2-3");

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe-shipping/v1/geocode",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ query: "Calle 1 #2-3" }),
        cache: "no-store",
      }),
    );
  });

  it("devuelve solo lat, lng y label de cada candidato (nada más viaja al navegador)", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      wpResponse(200, {
        results: [
          {
            lat: 4.65,
            lng: -74.08,
            label: "Calle 1",
            key: "sk-secreta",
            provider: "google",
          },
        ],
      }),
    );

    const outcome = await geocodeAddress("Calle 1");

    expect(outcome).toEqual({
      ok: true,
      value: [{ lat: 4.65, lng: -74.08, label: "Calle 1" }],
    });
  });

  it("una respuesta 200 con forma inesperada es un fallo cerrado, no un resultado vacío", async () => {
    vi.mocked(wpFetch).mockResolvedValue(wpResponse(200, { results: [{ lat: "x" }] }));

    expect(await geocodeAddress("Calle 1")).toEqual({
      ok: false,
      status: 503,
      code: "provider_unavailable",
    });
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "shipping.geocode.invalid_response" }),
      expect.any(String),
    );
  });

  it("si WordPress no responde (red caída), fallo 503 registrado", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("ECONNREFUSED"));

    expect(await geocodeAddress("Calle 1")).toEqual({
      ok: false,
      status: 503,
      code: "provider_unavailable",
    });
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "shipping.geocode.unreachable" }),
      expect.any(String),
    );
  });

  it("sin resultados (422 de WordPress) se devuelve el código, con su estado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(wpResponse(422, { code: "no_results" }));

    expect(await geocodeAddress("Nada")).toEqual({
      ok: false,
      status: 422,
      code: "no_results",
    });
  });

  it("el backoff de WordPress llega como 503 con su código", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      wpResponse(503, { code: "geocoding_backoff" }),
    );

    expect(await geocodeAddress("Calle 1")).toEqual({
      ok: false,
      status: 503,
      code: "geocoding_backoff",
    });
  });

  it("un código desconocido o un estado no esperado se normalizan, sin filtrar el mensaje", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      wpResponse(500, { code: "fatal", message: "API key abc123 rejected" }),
    );

    const outcome = await geocodeAddress("Calle 1");

    expect(outcome).toEqual({ ok: false, status: 503, code: "provider_unavailable" });
    expect(JSON.stringify(outcome)).not.toContain("abc123");
  });
});

describe("reverseGeocodePoint", () => {
  it("envía lat y lng a la ruta inversa y devuelve solo la etiqueta", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      wpResponse(200, { label: "Calle 1, Bogotá", provider: "nominatim" }),
    );

    const outcome = await reverseGeocodePoint({ lat: 4.65, lng: -74.08 });

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe-shipping/v1/reverse-geocode",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ lat: 4.65, lng: -74.08 }),
      }),
    );
    expect(outcome).toEqual({ ok: true, value: { label: "Calle 1, Bogotá" } });
  });

  it("una etiqueta ausente es un fallo, no una etiqueta vacía", async () => {
    vi.mocked(wpFetch).mockResolvedValue(wpResponse(200, {}));

    expect(await reverseGeocodePoint({ lat: 4.65, lng: -74.08 })).toEqual({
      ok: false,
      status: 503,
      code: "provider_unavailable",
    });
  });
});
