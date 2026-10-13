import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { customerUpdateSchema } from "@/lib/validation/store";
import { getGeoPack } from "@/lib/geo";
import { applyGeoPack } from "@/lib/geo/address";
import { getSiteConfig } from "@/lib/config";
import { identityPolicy } from "@/lib/customer/policy";
import { attachIdentity } from "@/lib/customer/identity-api";
import { checkDocument } from "@/lib/validation/identity";
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
 * Si trae `identity` (módulo `customer-identity`), registra el documento solo si
 * la cuenta aún no tiene uno: 409 si ya tiene otro o si es de otra cuenta.
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

  const [geoPack, config] = await Promise.all([getGeoPack(), getSiteConfig()]);
  const geo = applyGeoPack(geoPack, {
    billing: parsed.data.billing,
    shipping: parsed.data.shipping,
  });
  if (Object.keys(geo.details).length > 0) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: geo.details },
      { status: 422 },
    );
  }

  // Documento: debe existir el módulo y un tipo en el paquete de país.
  const { identity, ...profile } = parsed.data;
  if (identity) {
    const policy = identityPolicy(config, geoPack);
    const invalid =
      policy.documentTypes.length === 0
        ? "No se admite documento."
        : checkDocument(policy.documentTypes, identity);
    if (invalid) {
      return NextResponse.json(
        { error: invalid, details: { identity: [invalid] } },
        { status: 422 },
      );
    }
  }

  try {
    const session = await requireSession();
    const userId = Number(session.userId);

    // El documento va primero: es la regla más estricta (única entre cuentas, de
    // escritura única), y si la rechaza no se toca nada más del perfil.
    if (identity) {
      const saved = await attachIdentity(userId, identity);
      if (!saved.ok) {
        if (saved.reason === "document_taken" || saved.reason === "document_locked") {
          const message =
            saved.reason === "document_taken"
              ? "Este documento ya está registrado."
              : "El documento de la cuenta ya no se puede cambiar.";
          return NextResponse.json(
            { error: message, details: { identity: [message] } },
            { status: 409 },
          );
        }
        logger.error(
          { event: "customer.update.identity_failed", userId, reason: saved.reason },
          "No se pudo guardar el documento en WordPress",
        );
        return NextResponse.json(
          { error: "No se pudieron guardar los datos." },
          { status: 502 },
        );
      }
    }

    const customer = await wcFetch<WooCustomer>(`/customers/${userId}`, {
      method: "PUT",
      body: { ...profile, ...geo.addresses },
    });
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
