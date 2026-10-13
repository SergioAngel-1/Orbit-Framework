import "server-only";
import { getRedis } from "@/lib/redis/client";
import { CONSENT_GENERATION } from "./consent-storage";

// ============================================================================
//  Medición de la respuesta al banner de cookies: SOLO totales anónimos.
//
//  Qué se cuenta (lo envía `reportConsentEvent` desde el navegador, una vez
//  por evento y sesión): `shown` (el banner de primera visita se pintó),
//  `accepted` («Aceptar todas») y `declined` («Solo necesarias»). Quien lo
//  ignora no manda nada: es `shown - accepted - declined`, y lo calcula quien
//  lee el informe. Cambiar una decisión desde «Preferencias de cookies» no
//  cuenta: se mide la respuesta a la PRIMERA pregunta.
//
//  Nada identifica a nadie: ni IP, ni cookie, ni id de sesión llegan a Redis.
//  Solo un contador por (variante, evento) en un hash por HORA en UTC.
//
//  Por qué horas en UTC y no días: el día que importa es el del sitio, y el BFF no conoce su zona horaria —la tiene WordPress—. Guardar
//  por hora deja que quien lee agrupe en SU zona (`groupConsentBuckets`) sin
//  fijar aquí una zona de instancia. Con zonas de media hora (India) una hora
//  frontera cae entera en uno de los dos días: irrelevante para totales.
//
//  La variante (`bar` hoy; `modal` si se prueba) va en cada contador para que
//  dos semanas de banner y dos de modal se comparen sin mezclarse.
// ============================================================================

export const CONSENT_EVENTS = ["shown", "accepted", "declined"] as const;
export type ConsentEvent = (typeof CONSENT_EVENTS)[number];

export const CONSENT_VARIANTS = ["bar", "modal"] as const;
export type ConsentVariant = (typeof CONSENT_VARIANTS)[number];

export type ConsentCounts = Record<ConsentEvent, number>;

export interface ConsentStatsDay {
  /** Día en la zona pedida, `YYYY-MM-DD`. */
  date: string;
  variants: Partial<Record<ConsentVariant, ConsentCounts>>;
}

export interface ConsentStatsReport {
  days: ConsentStatsDay[];
  totals: Partial<Record<ConsentVariant, ConsentCounts>>;
}

// Por generación del consentimiento (`CONSENT_GENERATION`): un reinicio
// empieza la medición de cero y lo anterior deja de leerse (caduca solo con
// `BUCKET_TTL_SECONDS`).
const KEY_PREFIX = `hwe:consent-stats:g${CONSENT_GENERATION}:`;
/** Margen de sobra sobre el periodo máximo que se puede consultar. */
const BUCKET_TTL_SECONDS = 200 * 24 * 60 * 60;
export const MAX_REPORT_DAYS = 180;

export function parseConsentEvent(
  raw: unknown,
): { event: ConsentEvent; variant: ConsentVariant } | null {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return null;
  const { event, variant } = raw as Record<string, unknown>;
  if (!CONSENT_EVENTS.includes(event as ConsentEvent)) return null;
  if (!CONSENT_VARIANTS.includes(variant as ConsentVariant)) return null;
  return { event: event as ConsentEvent, variant: variant as ConsentVariant };
}

/** `hwe:consent-stats:2026-09-25T18` — la hora UTC que contiene `date`. */
export function hourBucketKey(date: Date): string {
  return KEY_PREFIX + date.toISOString().slice(0, 13);
}

/** Inicio de cada hora de los últimos `days` días, la actual incluida. */
export function bucketHoursBack(now: Date, days: number): Date[] {
  const current = new Date(now);
  current.setUTCMinutes(0, 0, 0);
  const count = days * 24;
  return Array.from(
    { length: count },
    (_, i) => new Date(current.getTime() - (count - 1 - i) * 3_600_000),
  );
}

export function resolveTimeZone(raw: string | null | undefined): string {
  if (!raw) return "UTC";
  try {
    new Intl.DateTimeFormat("en-CA", { timeZone: raw });
    return raw;
  } catch {
    return "UTC";
  }
}

function localDayFormatter(timeZone: string): Intl.DateTimeFormat {
  // `en-CA` formatea como YYYY-MM-DD.
  return new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });
}

function emptyCounts(): ConsentCounts {
  return { shown: 0, accepted: 0, declined: 0 };
}

/** Pura: agrupa los hashes horarios en días de `timeZone`. */
export function groupConsentBuckets(
  buckets: ReadonlyArray<{ hour: Date; fields: Record<string, string> }>,
  timeZone: string,
): ConsentStatsReport {
  const dayOf = localDayFormatter(timeZone);
  const byDay = new Map<string, ConsentStatsDay>();
  const totals: ConsentStatsReport["totals"] = {};

  for (const { hour, fields } of buckets) {
    for (const [field, rawValue] of Object.entries(fields)) {
      const [variant, event] = field.split(":");
      const parsed = parseConsentEvent({ event, variant });
      const value = Number(rawValue);
      if (!parsed || !Number.isInteger(value) || value <= 0) continue;

      const date = dayOf.format(hour);
      let day = byDay.get(date);
      if (!day) {
        day = { date, variants: {} };
        byDay.set(date, day);
      }
      const dayCounts = (day.variants[parsed.variant] ??= emptyCounts());
      dayCounts[parsed.event] += value;
      const totalCounts = (totals[parsed.variant] ??= emptyCounts());
      totalCounts[parsed.event] += value;
    }
  }

  const days = [...byDay.values()].sort((a, b) => a.date.localeCompare(b.date));
  return { days, totals };
}

/** Suma 1 al contador. `false` si Redis no está: la medición no es crítica. */
export async function recordConsentEvent(
  event: ConsentEvent,
  variant: ConsentVariant,
  now: Date = new Date(),
): Promise<boolean> {
  const redis = getRedis();
  if (!redis) return false;
  const key = hourBucketKey(now);
  try {
    await redis
      .multi()
      .hincrby(key, `${variant}:${event}`, 1)
      .expire(key, BUCKET_TTL_SECONDS)
      .exec();
    return true;
  } catch {
    return false;
  }
}

/** `null` si Redis no está disponible (distinto de «sin datos»). */
export async function readConsentStats(
  days: number,
  timeZone: string,
  now: Date = new Date(),
): Promise<ConsentStatsReport | null> {
  const redis = getRedis();
  if (!redis) return null;
  // Un día más que lo pedido: el primer día local puede empezar antes que el
  // primer día UTC. El recorte exacto lo hace `trimToDays`.
  const hours = bucketHoursBack(now, days + 1);
  try {
    const pipeline = redis.pipeline();
    for (const hour of hours) pipeline.hgetall(hourBucketKey(hour));
    const results = (await pipeline.exec()) ?? [];
    const buckets = hours.map((hour, i) => {
      const [err, fields] = results[i] ?? [null, {}];
      return { hour, fields: err ? {} : ((fields as Record<string, string>) ?? {}) };
    });
    return trimToDays(groupConsentBuckets(buckets, timeZone), days, timeZone, now);
  } catch {
    return null;
  }
}

/**
 * Se queda con los `days` días de CALENDARIO locales que terminan hoy (no con
 * los últimos `days` que tengan datos) y recalcula los totales.
 */
export function trimToDays(
  report: ConsentStatsReport,
  days: number,
  timeZone: string,
  now: Date,
): ConsentStatsReport {
  const firstDay = localDayFormatter(timeZone).format(
    new Date(now.getTime() - (days - 1) * 86_400_000),
  );
  const kept = report.days.filter((day) => day.date >= firstDay);
  const totals: ConsentStatsReport["totals"] = {};
  for (const day of kept) {
    for (const [variant, counts] of Object.entries(day.variants) as [
      ConsentVariant,
      ConsentCounts,
    ][]) {
      const t = (totals[variant] ??= emptyCounts());
      for (const event of CONSENT_EVENTS) t[event] += counts[event];
    }
  }
  return { days: kept, totals };
}
