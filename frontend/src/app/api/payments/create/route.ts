import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { NextResponse } from "next/server";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireSession } from "@/lib/auth/session";
import { createPaymentSchema } from "@/lib/validation/payments";
import { getActiveProvider } from "@/lib/payments/registry";
import { getOrder, isOrderPaid, toMinorUnits } from "@/lib/payments/orders";
import { logger } from "@/lib/observability/logger";
import { getSiteConfig } from "@/lib/config";
import {
  ONLINE_PAYMENT_METHOD,
  enabledPaymentChoices,
} from "@/lib/payments/checkout-options";

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
      logger.warn(
        { event: "payments.create.invalid_json" },
        "JSON inválido en payments.create",
      );
      return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
    }

    const parsed = createPaymentSchema.safeParse(body);
    if (!parsed.success) {
      logger.warn(
        { event: "payments.create.validation_error" },
        "Validación fallida en payments.create",
      );
      return NextResponse.json(
        { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
        { status: 422 },
      );
    }

    const { reference, returnUrl } = parsed.data;

    // El pedido debe existir y pertenecer al usuario autenticado.
    const order = await getOrder(reference);
    if (order.customer_id !== Number(session.userId)) {
      logger.warn(
        { event: "payments.create.idor_attempt", reference, userId: session.userId },
        "Intento de IDOR en payments.create",
      );
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    // Solo un pedido de pago en línea se cobra en la pasarela: el efectivo se
    // paga al recibirlo y el de WhatsApp con el link que envía la tienda. El
    // checkout no llama aquí con esos medios; esto cierra la puerta a una
    // petición manual. Tampoco si el pago en línea está apagado en el panel.
    if (order.payment_method !== ONLINE_PAYMENT_METHOD) {
      logger.warn(
        {
          event: "payments.create.not_online",
          reference,
          paymentMethod: order.payment_method,
        },
        "Cobro en pasarela de un pedido que no es de pago en línea",
      );
      return NextResponse.json(
        { error: "Este pedido no se paga en línea." },
        { status: 409 },
      );
    }
    if (!enabledPaymentChoices(await getSiteConfig()).includes("online")) {
      return NextResponse.json(
        { error: "El pago en línea no está disponible." },
        { status: 409 },
      );
    }

    // Si ya está pagado, no reiniciamos un cobro: el flujo es idempotente.
    if (isOrderPaid(order)) {
      logger.info(
        { event: "payments.create.already_paid", reference },
        "Pedido ya pagado, cobro omitido",
      );
      return NextResponse.json({ mode: "none", alreadyPaid: true }, { status: 200 });
    }

    // Pasarela del Control Center (o `PAYMENT_PROVIDER`); 503 si no hay ninguna.
    const provider = await getActiveProvider();
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

    logger.info(
      { event: "payments.create.success", reference, provider: provider.id },
      "Checkout de pago creado",
    );
    return NextResponse.json({ provider: provider.id, ...result }, { status: 200 });
  } catch (error) {
    logger.error(
      {
        event: "payments.create.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error en payments.create",
    );
    return handleApiError(error);
  }
}
