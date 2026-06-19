import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { NextResponse } from "next/server";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireSession } from "@/lib/auth/session";
import { createPaymentSchema } from "@/lib/validation/payments";
import { getProvider, activeProviderId } from "@/lib/payments/registry";
import { getOrder, isOrderPaid, toMinorUnits } from "@/lib/payments/orders";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * POST /api/payments/create
 * Inicia el cobro de un pedido ya creado (estado pending) con la pasarela
 * activa. Devuelve `redirectUrl`/`widget` para que la UI lleve al usuario al
 * checkout alojado. El cobro NO se confirma aquí: lo hace el webhook firmado.
 *
 * Autorización: exige sesión y que el pedido pertenezca al usuario (anti-IDOR).
 * El checkout es agnóstico: no sabe qué pasarela hay detrás.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "payments", limit: 10, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  try {
    const session = await requireSession();

    let body: unknown;
    try {
      body = await request.json();
    } catch {
      logger.warn({ event: "payments.create.invalid_json" }, "JSON inválido en payments.create");
      return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
    }

    const parsed = createPaymentSchema.safeParse(body);
    if (!parsed.success) {
      logger.warn({ event: "payments.create.validation_error" }, "Validación fallida en payments.create");
      return NextResponse.json(
        { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
        { status: 422 },
      );
    }

    const { reference, returnUrl } = parsed.data;

    // El pedido debe existir y pertenecer al usuario autenticado.
    const order = await getOrder(reference);
    if (order.customer_id !== Number(session.userId)) {
      logger.warn({ event: "payments.create.idor_attempt", reference, userId: session.userId }, "Intento de IDOR en payments.create");
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    // Si ya está pagado, no reiniciamos un cobro: el flujo es idempotente.
    if (isOrderPaid(order)) {
      logger.info({ event: "payments.create.already_paid", reference }, "Pedido ya pagado, cobro omitido");
      return NextResponse.json({ mode: "none", alreadyPaid: true }, { status: 200 });
    }

    const provider = getProvider();
    const effectiveReturnUrl =
      returnUrl ||
      process.env.NEXT_PUBLIC_PAYMENT_RETURN_URL ||
      new URL("/checkout/return", request.url).toString();

    const result = await provider.createCheckout({
      reference,
      amountMinor: toMinorUnits(order.total),
      currency: order.currency,
      customer: {
        email: order.billing.email ?? "",
        fullName:
          `${order.billing.first_name ?? ""} ${order.billing.last_name ?? ""}`.trim(),
        phone: order.billing.phone,
      },
      returnUrl: effectiveReturnUrl,
      metadata: { order_id: reference, order_key: String(order.id) },
    });

    logger.info({ event: "payments.create.success", reference, provider: activeProviderId() }, "Checkout de pago creado");
    return NextResponse.json(
      { provider: activeProviderId(), ...result },
      { status: 200 },
    );
  } catch (error) {
    logger.error({ event: "payments.create.error", err: error instanceof Error ? error.message : error }, "Error en payments.create");
    return handleApiError(error);
  }
}
