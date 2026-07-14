import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { customerUpdateSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * GET /api/store/customer
 * Devuelve los datos del cliente autenticado. El id se toma SIEMPRE de la
 * sesión (nunca de la entrada del usuario) -> un usuario solo accede a lo suyo.
 */
export async function GET() {
  try {
    const session = await requireSession();
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
    logger.info(
      { event: "customer.get", userId: session.userId },
      "Datos de cliente consultados",
    );
    return NextResponse.json(customer);
  } catch (error) {
    logger.error(
      {
        event: "customer.get.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al obtener datos del cliente",
    );
    return handleApiError(error);
  }
}

/**
 * PUT /api/store/customer
 * Actualiza los datos del cliente autenticado (campos permitidos por el schema).
 */
export async function PUT(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "customer", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = customerUpdateSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "customer.update.validation" },
      "Datos inválidos al actualizar cliente",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const session = await requireSession();
    const customer = await wcFetch<WooCustomer>(
      `/customers/${Number(session.userId)}`,
      { method: "PUT", body: parsed.data },
    );
    logger.info(
      { event: "customer.update.success", userId: session.userId },
      "Cliente actualizado",
    );
    return NextResponse.json(customer);
  } catch (error) {
    logger.error(
      {
        event: "customer.update.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al actualizar cliente",
    );
    return handleApiError(error);
  }
}
