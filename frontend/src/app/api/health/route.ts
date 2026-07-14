import { NextResponse } from "next/server";
import { getRedis } from "@/lib/redis/client";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/health — sonda de salud del frontend (liveness + readiness).
//
//  Devuelve 200 si el proceso responde. El estado de las dependencias
//  (Redis, WordPress) se reporta como detalle SIN tumbar el healthcheck: el
//  frontend degrada con elegancia (rate-limit fail-open, catálogo cacheado).
//  Para readiness estricta, exigir `status: "ok"` aguas arriba.
// ============================================================================

async function checkRedis(): Promise<"ok" | "down" | "disabled"> {
  const redis = getRedis();
  if (!redis) return "disabled";
  try {
    const pong = await redis.ping();
    return pong === "PONG" ? "ok" : "down";
  } catch {
    return "down";
  }
}

async function checkWordPress(): Promise<"ok" | "down" | "unknown"> {
  const url =
    process.env.WORDPRESS_INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_WORDPRESS_API_URL;
  if (!url) return "unknown";
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 3000);
    const res = await fetch(url, {
      method: "OPTIONS",
      signal: controller.signal,
      cache: "no-store",
    });
    clearTimeout(timer);
    // Cualquier respuesta HTTP (incluido 4xx) indica que el host está vivo.
    return res ? "ok" : "down";
  } catch {
    return "down";
  }
}

export async function GET(request: Request) {
  const [redis, wordpress] = await Promise.all([checkRedis(), checkWordPress()]);

  const degraded = redis === "down" || wordpress === "down";

  // Modo READINESS estricto: `?ready=1` devuelve 503 si hay dependencias caídas,
  // para que un orquestador saque la instancia del balanceo. Por defecto (sin el
  // flag) devuelve 200 aunque esté `degraded` (el frontend degrada con elegancia).
  const strict = new URL(request.url).searchParams.get("ready") === "1";
  const httpStatus = strict && degraded ? 503 : 200;

  logger.info(
    {
      event: "health.check",
      status: degraded ? "degraded" : "ok",
      redis,
      wordpress,
      strict,
    },
    "Health check consultado",
  );

  return NextResponse.json(
    {
      status: degraded ? "degraded" : "ok",
      uptime: Math.round(process.uptime()),
      timestamp: new Date().toISOString(),
      version: process.env.npm_package_version ?? "unknown",
      dependencies: { redis, wordpress },
    },
    { status: httpStatus, headers: { "Cache-Control": "no-store" } },
  );
}
