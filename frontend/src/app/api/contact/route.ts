import { NextResponse } from "next/server";
import { z } from "zod";
import { guardMutation } from "@/lib/api/guard";
import { getSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";
import type { WooCustomer } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

const contactSchema = z.object({
  name: z.string().trim().min(1, "Nombre requerido").max(120),
  email: z.email("Email no válido").max(200),
  phone: z.string().trim().max(40).optional().default(""),
  requestType: z.enum([
    "peticion",
    "queja",
    "reclamo",
    "sugerencia",
    "felicitacion",
    "otro",
  ]),
  description: z.string().trim().min(1, "Descripción requerida").max(5000),
  acceptedTerms: z.literal(true, { error: "Debes aceptar la política de privacidad." }),
});

/**
 * POST /api/contact
 *
 * Valida el formulario y lo reenvía a WordPress (`hwe/v1/contact`, solo con el
 * secreto interno), que lo entrega por email al de Datos legales del Control
 * Center. Con sesión, el nombre, el email y el teléfono salen del perfil de la
 * cuenta y no del cuerpo: nadie puede escribir en nombre de otro cliente.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "contact", limit: 5, windowSeconds: 300 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = contactSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  let payload = parsed.data;
  const session = await getSession();
  if (session) {
    try {
      const customer = await wcFetch<WooCustomer>(
        `/customers/${Number(session.userId)}`,
      );
      const name =
        [customer.first_name, customer.last_name].filter(Boolean).join(" ").trim() ||
        payload.name;
      payload = {
        ...payload,
        name,
        email: customer.email || payload.email,
        phone: (customer.billing?.phone || payload.phone || "").trim(),
      };
    } catch (error) {
      logger.warn(
        {
          event: "contact.profile_override_failed",
          userId: session.userId,
          err: error instanceof Error ? error.message : String(error),
        },
        "No se pudo fijar la identidad desde el perfil",
      );
      return NextResponse.json(
        { error: "No se pudo validar tu perfil. Inténtalo de nuevo." },
        { status: 503 },
      );
    }
  }

  try {
    const res = await wpFetch("/wp-json/hwe/v1/contact", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        name: payload.name,
        email: payload.email,
        phone: payload.phone,
        requestType: payload.requestType,
        description: payload.description,
      }),
      cache: "no-store",
    });
    if (!res.ok) throw new Error(`WordPress respondió ${res.status}`);
    logger.info(
      {
        event: "contact.forwarded",
        requestType: payload.requestType,
        authenticated: Boolean(session),
      },
      "Formulario de contacto reenviado a WordPress",
    );
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error(
      {
        event: "contact.forward_error",
        err: error instanceof Error ? error.message : String(error),
      },
      "Fallo al reenviar el formulario de contacto",
    );
    return NextResponse.json(
      { error: "No se pudo enviar el mensaje. Inténtalo más tarde." },
      { status: 502 },
    );
  }
}
