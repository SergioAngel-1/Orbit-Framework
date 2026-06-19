import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import {
  readCartToken,
  writeCartToken,
  clearCartToken,
} from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { checkoutSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
  releaseIdempotencyKey,
} from "@/lib/security/idempotency";
import type { StoreCheckoutResponse } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * POST /api/store/checkout
 * Crea el pedido a partir del carrito actual (Store API).
 *
 * Idempotencia: si el cliente envía la cabecera `Idempotency-Key`, reintentos
 * con la misma clave no crean pedidos duplicados (replay de la respuesta).
 *
 * NOTA (Fase 6): el cobro real se confirma vía webhook de la pasarela. Aquí se
 * crea el pedido; la integración de pago server-side llega en la Fase 6.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "checkout", limit: 10, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    logger.warn({ event: "checkout.invalid_json" }, "JSON inválido en checkout");
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = checkoutSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn({ event: "checkout.validation_error" }, "Validación fallida en checkout");
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // --- Idempotencia (opcional pero recomendada) ---
  const idemKey = request.headers.get("Idempotency-Key");
  const useIdempotency = isValidIdempotencyKey(idemKey);

  if (useIdempotency) {
    const state = await reserveIdempotencyKey(idemKey);
    if (state.status === "replay") {
      logger.info({ event: "checkout.idempotent_replay" }, "Replay de checkout idempotente");
      return NextResponse.json(state.body, { status: state.statusCode });
    }
    if (state.status === "conflict") {
      logger.warn({ event: "checkout.idempotent_conflict" }, "Conflicto de idempotencia en checkout");
      return NextResponse.json(
        { error: "Un pedido con esta clave ya se está procesando o se completó." },
        { status: 409 },
      );
    }
  }

  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCheckoutResponse>("/checkout", {
      method: "POST",
      body: parsed.data,
      cartToken: token,
    });

    // El carrito queda consumido: reiniciamos el token para empezar uno nuevo.
    if (cartToken) {
      await writeCartToken(cartToken);
    } else {
      await clearCartToken();
    }

    if (useIdempotency) {
      await storeIdempotentResult(idemKey, data, 201);
    }

    logger.info({ event: "checkout.success", order_id: data.order_id }, "Pedido creado en checkout");
    return NextResponse.json(data, { status: 201 });
  } catch (error) {
    // Libera la reserva para permitir un reintento legítimo.
    if (useIdempotency) {
      await releaseIdempotencyKey(idemKey);
    }
    logger.error({ event: "checkout.error", err: error instanceof Error ? error.message : error }, "Error en checkout");
    return handleApiError(error);
  }
}
