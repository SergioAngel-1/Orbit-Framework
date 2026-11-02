import { NextResponse } from "next/server";
import { instanceRoute } from "@/lib/api/instance-route";

// Ruta BFF propia de la instancia de ejemplo: `GET /api/instance/example`.
// `instanceRoute` aplica las guardas del núcleo; aquí solo vive el negocio.
export const dynamic = "force-dynamic";

const DEFAULT_GREETING = "Hola";

export const GET = instanceRoute(
  { session: "optional" },
  async ({ config, session }) =>
    NextResponse.json({
      greeting: config.instance?.example?.greeting ?? DEFAULT_GREETING,
      signedIn: session !== null,
    }),
);
