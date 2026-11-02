import "server-only";
import { NextResponse } from "next/server";
import { guardMutation, type GuardOptions } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireFlag, type EcommerceFlag } from "@/lib/api/flags";
import { requireModule } from "@/lib/api/modules";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import type { ModuleId, SiteConfig } from "@/lib/config/types";
import { logger } from "@/lib/observability/logger";
import { getRequestId } from "@/lib/observability/request-id";
import type { Session } from "@/types/auth";

// ============================================================================
//  Rutas BFF propias de una instancia: `app/api/instance/**`.
//
//  Esa carpeta es de la instancia (tools/boundaries.json) y cuelga de `/api`,
//  así que recibe gratis lo que el proxy da a toda la API: barrera de Origin en
//  escrituras, refresco del JWT, sin reescritura de idioma y `Disallow` en
//  robots.txt. Este envoltorio añade, en el orden de las rutas del núcleo:
//
//    módulo apagado → 404 · flag apagado → 404 · escritura → guardMutation
//    (Origin → CSRF → rate-limit) · sesión exigida → 401 · handler
//
//  Además: `Cache-Control: no-store` por defecto y cualquier excepción pasa por
//  `handleApiError` (5xx genérico, nunca el detalle) con su evento en el log.
//
//  Los webhooks de la instancia NO usan este envoltorio (no llevan CSRF): van en
//  `app/api/instance/webhooks/<nombre>/route.ts`, el proxy los exime de la
//  barrera de Origin y deben verificar su firma antes de actuar
//  (`verifyHmacSignature`; lo comprueba tests/unit/signed-routes.test.ts).
//
//    export const dynamic = "force-dynamic";
//    export const POST = instanceRoute(
//      { session: "required", guard: { rateLimit: { name: "returns", limit: 5, windowSeconds: 60 } } },
//      async ({ request, session }) => NextResponse.json(await createReturn(session, request)),
//    );
// ============================================================================

const UNSAFE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

type SessionMode = "required" | "optional";

export interface InstanceRouteOptions<S extends SessionMode | undefined> {
  /** Módulo del framework del que depende la ruta: apagado → 404. */
  module?: ModuleId;
  /** Interruptor `config.ecommerce.*_enabled` del que depende: apagado → 404. */
  flag?: EcommerceFlag;
  /** Opciones de `guardMutation` para las escrituras (CSRF siempre activo salvo `csrf: false`). */
  guard?: GuardOptions;
  /** `required`: sin sesión → 401. `optional`: se lee y puede ser `null`. Sin el campo, no se lee. */
  session?: S;
}

export interface InstanceRouteContext<S extends SessionMode | undefined, P> {
  request: Request;
  config: SiteConfig;
  session: S extends "required" ? Session : Session | null;
  params: P;
}

type RouteSegment<P> = { params: Promise<P> };

/**
 * Envuelve el handler de una ruta BFF de instancia con las guardas del núcleo.
 * Devuelve la función que se exporta como `GET`/`POST`/… desde `route.ts`.
 */
export function instanceRoute<
  S extends SessionMode | undefined = undefined,
  P = Record<string, string>,
>(
  options: InstanceRouteOptions<S>,
  handler: (context: InstanceRouteContext<S, P>) => Promise<Response>,
): (request: Request, segment?: RouteSegment<P>) => Promise<Response> {
  return async (request, segment) => {
    try {
      const config = await getSiteConfig();

      if (options.module) {
        const moduleOff = requireModule(config, options.module);
        if (moduleOff) return moduleOff;
      }
      if (options.flag) {
        const flagOff = requireFlag(config, options.flag);
        if (flagOff) return flagOff;
      }
      if (UNSAFE_METHODS.has(request.method)) {
        const blocked = await guardMutation(request, options.guard);
        if (blocked) return blocked;
      }

      const session = options.session ? await getSession() : null;
      if (options.session === "required" && !session) {
        return NextResponse.json(
          { error: "No autenticado." },
          { status: 401, headers: { "Cache-Control": "no-store" } },
        );
      }

      const params = (segment ? await segment.params : {}) as P;
      const response = await handler({
        request,
        config,
        session: session as InstanceRouteContext<S, P>["session"],
        params,
      });
      if (!response.headers.has("Cache-Control")) {
        response.headers.set("Cache-Control", "no-store");
      }
      return response;
    } catch (error) {
      const path = new URL(request.url).pathname;
      logger.error(
        {
          event: "instance_route.error",
          path,
          method: request.method,
          requestId: getRequestId(request.headers),
          error: error instanceof Error ? error.message : String(error),
        },
        "Ruta BFF de instancia: error no controlado",
      );
      const response = handleApiError(error);
      response.headers.set("Cache-Control", "no-store");
      return response;
    }
  };
}
