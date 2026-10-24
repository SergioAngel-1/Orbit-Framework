import "server-only";
import { NextResponse } from "next/server";
import { logger } from "@/lib/observability/logger";
import { MembershipsReadError } from "./types";

// ============================================================================
//  Respuestas HTTP comunes de las rutas BFF de membresías.
//
//  Un fallo tipado de los lectores se traduce a un código coherente (404, 403,
//  400, 503 o 502) y los 5xx dejan un evento estructurado. Un error inesperado
//  es un 500 genérico. Ninguna lectura devuelve un estado inventado.
// ============================================================================

/** Ninguna respuesta del módulo se puede cachear en un proxy compartido. */
export const NO_STORE = { "Cache-Control": "no-store" } as const;

/** 401 común de las rutas de sesión. */
export function membershipUnauthorized(): NextResponse {
  return NextResponse.json(
    { error: "No autenticado." },
    { status: 401, headers: NO_STORE },
  );
}

/**
 * Traduce un error de los lectores a la respuesta HTTP del BFF.
 *
 * @param error   Error capturado (tipado o no).
 * @param event   Sufijo del evento (`product`, `card`…).
 * @param message Mensaje humano del log de un error inesperado.
 */
export function membershipFailure(
  error: unknown,
  event: string,
  message: string,
): NextResponse {
  if (error instanceof MembershipsReadError) {
    const status =
      error.code === "not_found"
        ? 404
        : error.code === "forbidden"
          ? 403
          : error.code === "invalid_request"
            ? 400
            : error.code === "unavailable"
              ? 503
              : 502;

    if (status >= 500) {
      logger.error(
        {
          event: `memberships.${event}_unavailable`,
          code: error.code,
          status: error.status,
        },
        "No se pudo consultar el módulo de membresías",
      );
    }

    return NextResponse.json(
      { error: error.message, code: error.reason ?? error.code },
      { status, headers: NO_STORE },
    );
  }

  logger.error(
    {
      event: `memberships.${event}_failed`,
      err: error instanceof Error ? error.message : String(error),
    },
    message,
  );
  return NextResponse.json(
    { error: "Error interno." },
    { status: 500, headers: NO_STORE },
  );
}
