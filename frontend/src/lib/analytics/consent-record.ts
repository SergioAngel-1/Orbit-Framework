// ============================================================================
//  Registro de consentimientos de cookies: validación y minimización (puro).
//
//  Es la PRUEBA de cada decisión —qué eligió la persona y cuándo—, no la
//  medición anónima de `consent-stats.ts`, que sigue aparte y sin identificar
//  a nadie. El flujo completo y el porqué de cada campo, en
//  `consent-ledger.ts`; aquí solo lo que entra desde el navegador y cómo se
//  recorta antes de guardarlo.
// ============================================================================

import type { ConsentReportVariant } from "./consent-report";
import { isTrackingServiceName, type TrackingServiceName } from "./services";

/**
 * Cookie httpOnly con el COMPROBANTE del dispositivo (un UUID que emite el
 * servidor en la primera decisión). Es lo que liga las filas de una misma
 * persona anónima y lo que se vincula a la cuenta al iniciar sesión.
 */
export const CONSENT_RECEIPT_COOKIE = "hwe_consent";
/** Tope de los navegadores para una cookie (Chrome: 400 días). Se renueva en cada decisión. */
export const CONSENT_RECEIPT_MAX_AGE = 400 * 24 * 60 * 60;

export const CONSENT_DECISIONS = ["accepted", "declined"] as const;
export type ConsentDecision = (typeof CONSENT_DECISIONS)[number];

/**
 * Lo que declara el navegador: primera respuesta o cambio desde «Preferencias
 * de cookies». Que un cambio sea una RETIRADA (`revoke`) lo decide WordPress
 * con su propio registro, no el navegador.
 */
export const CONSENT_CLIENT_ACTIONS = ["first", "change"] as const;
export type ConsentClientAction = (typeof CONSENT_CLIENT_ACTIONS)[number];

const VARIANTS: readonly ConsentReportVariant[] = ["bar", "modal"];

export interface ConsentRecordRequest {
  decision: ConsentDecision;
  action: ConsentClientAction;
  /** Ruta donde se decidió, sin query ni fragmento. */
  path: string | null;
  variant: ConsentReportVariant;
  locale: string;
  /** Hora declarada por el navegador: informativa, la que vale es la del servidor. */
  clientAt: string | null;
  /**
   * Servicios que nombraba el banner al decidir. Es lo que se ENSEÑÓ, y puede
   * no ser lo configurado cuando llega (página en caché, reenvío de la cola
   * horas después). Null en lo encolado antes de existir el campo.
   */
  services: TrackingServiceName[] | null;
  /**
   * Generación del consentimiento con la que se decidió (`CONSENT_GENERATION`
   * del navegador). Null en lo encolado antes de existir el campo.
   */
  generation: number | null;
}

const RECEIPT_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** Solo UUID en minúsculas, que es lo que emite `crypto.randomUUID()`. */
export function isConsentReceipt(value: unknown): value is string {
  return typeof value === "string" && RECEIPT_RE.test(value);
}

function truncateIpv4(ip: string): string | null {
  const parts = ip.split(".");
  if (parts.length !== 4) return null;
  const octets = parts.map((p) => (/^\d{1,3}$/.test(p) ? Number(p) : NaN));
  if (octets.some((n) => !Number.isInteger(n) || n > 255)) return null;
  return `${octets[0]}.${octets[1]}.${octets[2]}.0`;
}

/** Los 8 grupos de una IPv6 (expandiendo `::`), o null si no lo es. */
function ipv6Groups(ip: string): string[] | null {
  const halves = ip.split("::");
  if (halves.length > 2) return null;
  const head = halves[0] ? halves[0].split(":") : [];
  const tail = halves.length === 2 && halves[1] ? halves[1].split(":") : [];
  const missing = 8 - head.length - tail.length;
  if (halves.length === 1 ? missing !== 0 : missing < 1) return null;
  const groups = [
    ...head,
    ...Array<string>(halves.length === 2 ? missing : 0).fill("0"),
    ...tail,
  ];
  if (!groups.every((g) => /^[0-9a-f]{1,4}$/i.test(g))) return null;
  return groups.map((g) => parseInt(g, 16).toString(16));
}

/**
 * IP recortada: /24 en IPv4 y /48 en IPv6, como hacen las plataformas de
 * consentimiento. Junto al comprobante, la hora y el user-agent basta para
 * la prueba, y conservar años la IP completa de cada visitante anónimo sería
 * un dato personal más que custodiar. Lo que no sea una IP válida, null.
 */
export function truncateIp(raw: string): string | null {
  const ip = raw.trim();
  if (!ip) return null;
  const mapped = /^::ffff:(\d+\.\d+\.\d+\.\d+)$/i.exec(ip);
  if (mapped?.[1]) return truncateIpv4(mapped[1]);
  if (ip.includes(".")) return truncateIpv4(ip);
  if (!ip.includes(":")) return null;
  const groups = ipv6Groups(ip);
  return groups ? `${groups.slice(0, 3).join(":")}::` : null;
}

/**
 * Solo la ruta del propio sitio, sin query ni fragmento: un `?email=` o un
 * token de un enlace no tienen por qué acabar en un registro que se conserva
 * años.
 */
export function sanitizeConsentPath(raw: unknown): string | null {
  if (typeof raw !== "string" || !raw.startsWith("/") || raw.startsWith("//")) {
    return null;
  }
  const path = raw.split(/[?#]/, 1)[0] ?? "";
  return path ? path.slice(0, 255) : null;
}

export function clampUserAgent(raw: string | null | undefined): string | null {
  const ua = (raw ?? "").trim();
  return ua ? ua.slice(0, 255) : null;
}

export function parseConsentRecordRequest(
  raw: unknown,
  locales: readonly string[],
): ConsentRecordRequest | null {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return null;
  const body = raw as Record<string, unknown>;
  const { decision, action, variant, locale } = body;
  if (!CONSENT_DECISIONS.includes(decision as ConsentDecision)) return null;
  if (!CONSENT_CLIENT_ACTIONS.includes(action as ConsentClientAction)) return null;
  if (!VARIANTS.includes(variant as ConsentReportVariant)) return null;
  if (typeof locale !== "string" || !locales.includes(locale)) return null;

  const clientAt =
    typeof body.clientAt === "string" && !Number.isNaN(Date.parse(body.clientAt))
      ? new Date(body.clientAt).toISOString()
      : null;

  // Una lista presente tiene que ser válida y no vacía: el banner no se pinta
  // sin nada que pedir. Sin repetidos.
  let services: TrackingServiceName[] | null = null;
  if (body.services !== undefined) {
    if (!Array.isArray(body.services) || !body.services.every(isTrackingServiceName)) {
      return null;
    }
    services = [...new Set(body.services)];
    if (services.length === 0) return null;
  }

  return {
    decision: decision as ConsentDecision,
    action: action as ConsentClientAction,
    path: sanitizeConsentPath(body.path),
    variant: variant as ConsentReportVariant,
    locale,
    clientAt,
    services,
    generation:
      Number.isInteger(body.generation) &&
      (body.generation as number) > 0 &&
      (body.generation as number) < 1000
        ? (body.generation as number)
        : null,
  };
}
