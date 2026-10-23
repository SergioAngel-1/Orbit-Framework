import { NextResponse } from "next/server";
import { z } from "zod";
import { getSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { transferPoints } from "@/lib/loyalty/server";
import type { LoyaltyTransferError, LoyaltyTransferResult } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";
import {
  isValidIdempotencyKey,
  releaseIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
} from "@/lib/security/idempotency";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/loyalty/transfer
//
//  Escritura crítica: `guardMutation` (Origin → CSRF → rate-limit estricto) y
//  cabecera `Idempotency-Key` obligatoria. La clave se acota por usuario antes
//  de guardarla en Redis (`loyalty-transfer:<userId>:<clave>`): sin eso, un
//  usuario podría recibir la respuesta cacheada de otro. A WordPress se le
//  reenvía la clave SIN acotar; allí el libro la cualifica por emisor.
// ============================================================================

const NO_STORE = { "Cache-Control": "no-store" };

/** Longitud máxima de la nota libre que guarda el libro (coincide con WordPress). */
const NOTE_MAX = 200;

/** Código estable de WordPress → estado HTTP del BFF. */
const TRANSFER_STATUS: Record<LoyaltyTransferError, number> = {
  disabled: 404,
  invalid_request: 400,
  conflict: 409,
  below_minimum: 422,
  insufficient: 422,
  invalid_recipient: 422,
  self: 422,
  daily_cap: 422,
  failed: 502,
};

const transferSchema = z.object({
  code: z.string().trim().min(1).max(64),
  points: z.number().int().positive(),
  note: z.string().trim().max(NOTE_MAX).optional(),
});

function resultResponse(result: LoyaltyTransferResult, status: number): NextResponse {
  return NextResponse.json(result, { status, headers: NO_STORE });
}

export async function POST(request: Request) {
  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "loyalty");
  if (moduleOff) return moduleOff;

  const featureOff = requireLoyalty(config, "transfers");
  if (featureOff) return featureOff;

  const blocked = await guardMutation(request, {
    rateLimit: { name: "loyalty-transfer", limit: 10, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  const session = await getSession();
  if (!session) {
    return NextResponse.json(
      { error: "No autenticado." },
      { status: 401, headers: NO_STORE },
    );
  }

  const key = request.headers.get("Idempotency-Key")?.trim() ?? null;
  if (!isValidIdempotencyKey(key)) {
    logger.warn(
      { event: "loyalty.transfer.invalid_idempotency_key", userId: session.userId },
      "Transferencia sin clave de idempotencia válida",
    );
    return NextResponse.json(
      { error: "Falta una clave de idempotencia válida." },
      { status: 400, headers: NO_STORE },
    );
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json(
      { error: "JSON inválido." },
      { status: 400, headers: NO_STORE },
    );
  }

  const parsed = transferSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "loyalty.transfer.validation_error", userId: session.userId },
      "Validación fallida en una transferencia",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422, headers: NO_STORE },
    );
  }

  const userId = Number(session.userId);

  // La librería guarda la clave tal cual: acotarla por usuario es lo que impide
  // que la respuesta de una transferencia de otro se sirva como replay.
  const scopedKey = `loyalty-transfer:${session.userId}:${key}`;
  const reservation = await reserveIdempotencyKey(scopedKey);

  if (reservation.status === "replay") {
    logger.info(
      { event: "loyalty.transfer_replay", userId: session.userId },
      "Transferencia repetida: se devuelve el resultado guardado",
    );
    return NextResponse.json(reservation.body, {
      status: reservation.statusCode,
      headers: NO_STORE,
    });
  }

  if (reservation.status === "conflict") {
    return resultResponse({ ok: false, error: "conflict" }, 409);
  }

  const result = await transferPoints(
    userId,
    {
      code: parsed.data.code,
      points: parsed.data.points,
      ...(parsed.data.note ? { note: parsed.data.note } : {}),
    },
    key,
  );

  const status = result.ok ? 200 : TRANSFER_STATUS[result.error];

  if (!result.ok) {
    if (result.error === "failed" || result.error === "disabled") {
      // Un fallo de transporte o un interruptor que cambió a mitad: no se cachea
      // para que el mismo intento pueda repetirse.
      await releaseIdempotencyKey(scopedKey);
      logger.error(
        {
          event: "loyalty.transfer_failed",
          userId: session.userId,
          error: result.error,
        },
        "La transferencia no se pudo completar",
      );
      return resultResponse(result, status);
    }

    await storeIdempotentResult(scopedKey, result, status);
    logger.warn(
      {
        event: "loyalty.transfer_rejected",
        userId: session.userId,
        error: result.error,
      },
      "Transferencia rechazada con un motivo de negocio",
    );
    return resultResponse(result, status);
  }

  await storeIdempotentResult(scopedKey, result, status);
  logger.info(
    {
      event: "loyalty.transfer",
      userId: session.userId,
      sent: result.sent,
      received: result.received,
      fee: result.fee,
    },
    "Transferencia completada",
  );
  return resultResponse(result, status);
}
