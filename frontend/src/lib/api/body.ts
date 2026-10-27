import "server-only";
import { NextResponse } from "next/server";

// ============================================================================
//  Tope de bytes para los cuerpos que lee el BFF.
//
//  El rate-limit cuenta PETICIONES, no bytes: sin este tope, un POST anónimo a
//  una ruta pública (los webhooks están exentos de Origin y CSRF) podía traer
//  cientos de MB y el handler los materializaba con `request.text()`/
//  `request.json()` antes de decidir nada —incluida la firma—, consumiendo
//  ~2x los bytes de red en memoria del proceso que sirve toda la tienda.
//
//  El tope se comprueba primero por `Content-Length` (sin leer nada) y después
//  contando los bytes reales mientras se lee, porque `Transfer-Encoding:
//  chunked` no declara longitud. Al pasarse se cancela el stream y se responde
//  413. `guardMutation` aplica el tope JSON a toda escritura; los webhooks, que
//  no pasan por él, usan `MAX_WEBHOOK_BODY_BYTES` con el mismo lector.
// ============================================================================

/** Tope por defecto de un cuerpo JSON del BFF (64 KiB). */
export const MAX_JSON_BODY_BYTES = 64 * 1024;

/**
 * Tope de los webhooks firmados (256 KiB). El payload de un pedido de
 * WooCommerce (líneas, direcciones, metas) es mayor que un JSON de formulario;
 * sigue estando muy por debajo de los 130 MB del límite de medios de Caddy.
 */
export const MAX_WEBHOOK_BODY_BYTES = 256 * 1024;

/** El cuerpo de la petición supera el tope permitido. */
export class BodyTooLarge extends Error {
  constructor(readonly maxBytes: number) {
    super(`El cuerpo de la petición supera el tope de ${maxBytes} bytes.`);
    this.name = "BodyTooLarge";
  }
}

/** Respuesta 413 uniforme para todos los handlers. */
export function bodyTooLarge(): NextResponse {
  return NextResponse.json(
    { error: "Cuerpo de la petición demasiado grande." },
    { status: 413 },
  );
}

/** Longitud declarada por el cliente, o null si no hay o no es un número válido. */
function declaredLength(request: Request): number | null {
  const raw = request.headers.get("content-length");
  if (raw === null) return null;
  const value = Number(raw);
  return Number.isFinite(value) && value >= 0 ? value : null;
}

/**
 * Rechaza por `Content-Length` declarado ANTES de tocar el stream.
 * Lanza `BodyTooLarge` si el cliente ya declara más bytes de los permitidos.
 */
export function assertBodyWithinLimit(request: Request, maxBytes: number): void {
  const declared = declaredLength(request);
  if (declared !== null && declared > maxBytes) {
    throw new BodyTooLarge(maxBytes);
  }
}

/**
 * Lee el cuerpo como texto con un tope duro de bytes. Corta en cuanto se pasa,
 * cancela el stream (no se sigue descargando) y lanza `BodyTooLarge`.
 */
export async function readTextBody(
  request: Request,
  maxBytes: number = MAX_JSON_BODY_BYTES,
): Promise<string> {
  assertBodyWithinLimit(request, maxBytes);

  const stream = request.body;
  if (!stream) return "";

  const reader = stream.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;

  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > maxBytes) {
        await reader.cancel().catch(() => undefined);
        throw new BodyTooLarge(maxBytes);
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }

  return Buffer.concat(chunks).toString("utf8");
}

/**
 * Equivalente a `request.json()` pero con tope de bytes. Un JSON inválido sigue
 * lanzando `SyntaxError` (los handlers mantienen su respuesta 400 de siempre).
 */
export async function readJsonBody<T = unknown>(
  request: Request,
  maxBytes: number = MAX_JSON_BODY_BYTES,
): Promise<T> {
  return JSON.parse(await readTextBody(request, maxBytes)) as T;
}
