import { robotsResponse } from "@/app/_robots/build";

// /robots.txt: ver `_robots/build.ts`.
//
// Dinámico a propósito: la guarda `HWE_NOINDEX` se lee EN EJECUCIÓN (la misma imagen
// sirve staging y producción), y un robots.txt estático la hornearía en el build. Es
// barato —la config va por la Data Cache— y el CDN lo sigue cacheando (`s-maxage`).
export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET(): Promise<Response> {
  return robotsResponse();
}
