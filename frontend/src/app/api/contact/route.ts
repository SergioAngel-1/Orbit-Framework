import { NextResponse } from "next/server";
import { z } from "zod";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const contactSchema = z.object({
  name: z.string().min(1, "Nombre requerido").max(120),
  email: z.email("Email no válido").max(200),
  phone: z.string().max(40).optional().default(""),
  requestType: z.enum([
    "peticion",
    "queja",
    "reclamo",
    "sugerencia",
    "felicitacion",
    "otro",
  ]),
  description: z.string().min(1, "Descripción requerida").max(5000),
  acceptedTerms: z.literal(true, { error: "Debes aceptar la política de privacidad." }),
});

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

  // TODO: reenviar a WordPress o a un servicio de email real.
  logger.info(
    { event: "contact.submitted", requestType: parsed.data.requestType },
    "Formulario de contacto recibido",
  );

  return NextResponse.json({ ok: true }, { status: 200 });
}
