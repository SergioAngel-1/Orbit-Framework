// ============================================================================
//  Decisión de cookies en el navegador (`localStorage["cookie-consent"]`).
//
//  Hasta el registro de consentimientos era una cadena suelta
//  (`"accepted"`/`"declined"`); ahora es JSON con el COMPROBANTE que devolvió
//  el servidor y una cola de decisiones aún no registradas. La cola existe
//  porque la prueba no puede perderse por un fallo de red: lo que no llegó se
//  reenvía en la siguiente carga (`consent-sync.ts`).
//
//  GENERACIÓN (`v`): una decisión solo vale para la generación con la que se
//  tomó. Subir `CONSENT_GENERATION` invalida TODAS las guardadas y el banner
//  vuelve a preguntar a todo el mundo; es la herramienta para cuando lo que
//  se pide cambia de verdad (un servicio nuevo, otra finalidad): un «Aceptar»
//  dado a un banner que no lo mencionaba no lo cubre. También reinicia la
//  medición anónima (`consent-stats.ts`, `consent-report.ts`), que así mide
//  cada generación por separado.
//
//  Historial: 2 = el formato JSON con comprobante (framework v2.0). La v1 del
//  framework guardaba la cadena suelta «accepted»/«declined» (generación 1,
//  implícita), que se descarta y se repregunta. Una instancia sube el número
//  cuando lo que se pide cambia de verdad (una finalidad nueva); un servicio
//  nuevo configurado en el panel NO hace falta: lo cubre el ALCANCE, abajo.
//
//  ALCANCE (`services`): un «Aceptar» cubre los servicios que el banner
//  NOMBRABA al darlo, y nada más. Si Control Center → Integraciones añade uno
//  (un ID de TikTok o de GTM pegado en el panel, sin deploy de por medio), la
//  decisión deja de cubrir lo que se carga y cuenta como «sin respuesta»: el
//  banner vuelve a preguntar y el servicio nuevo no se carga hasta entonces.
//  Sin el alcance, esto dependería de acordarse de subir la generación en el
//  deploy que lo activaba. Una decisión guardada antes de existir el campo
//  (`services: null`) cubre lo configurado
//  y lo adopta como suyo al cargar (`adoptLegacyScope`). Quitar un servicio
//  no repregunta: lo aceptado sigue cubriendo lo que queda.
//
//  Funciones puras sobre un `Storage` inyectado; el acceso real (que en Safari
//  con almacenamiento bloqueado LANZA) lo envuelve quien las llama.
// ============================================================================

import type { ConsentClientAction, ConsentDecision } from "./consent-record";
import type { ConsentReportVariant } from "./consent-report";
import { isTrackingServiceName, type TrackingServiceName } from "./services";

export type EffectiveConsent = ConsentDecision | "unknown";

export const CONSENT_KEY = "cookie-consent";
/** Generación vigente del consentimiento (ver cabecera). */
export const CONSENT_GENERATION = 2;
/** Tope de la cola: más cambios sin conexión que esto no son de una persona. */
export const MAX_PENDING = 10;

export interface PendingConsentRecord {
  decision: ConsentDecision;
  action: ConsentClientAction;
  path: string;
  variant: ConsentReportVariant;
  locale: string;
  /** ISO. También identifica el registro en la cola al confirmarlo. */
  clientAt: string;
  /**
   * Servicios que NOMBRABA el banner al decidir: el servidor rehace con ellos
   * el texto que se enseñó. Ausente en lo encolado antes de existir el campo.
   */
  services?: TrackingServiceName[];
  /**
   * Generación con la que se tomó (`CONSENT_GENERATION`). Va al registro, que
   * así distingue una decisión vigente de una que un reinicio ya retiró.
   * Ausente en lo encolado antes de existir el campo.
   */
  generation?: number;
}

export interface StoredConsent {
  /** Generación con la que se tomó la decisión (`CONSENT_GENERATION`). */
  v: typeof CONSENT_GENERATION;
  /**
   * Null: no hay decisión vigente, solo registros de una generación anterior
   * que aún no llegaron al servidor (ver `discardObsoleteConsent`).
   */
  status: ConsentDecision | null;
  /** Servicios que cubre la decisión (ver ALCANCE en la cabecera); null = anterior al campo. */
  services: TrackingServiceName[] | null;
  /** Comprobante del servidor; null hasta el primer registro confirmado. */
  receipt: string | null;
  pending: PendingConsentRecord[];
}

function isServiceList(value: unknown): value is TrackingServiceName[] {
  return Array.isArray(value) && value.every(isTrackingServiceName);
}

function isGeneration(value: unknown): value is number {
  return Number.isInteger(value) && (value as number) > 0 && (value as number) < 1000;
}

function isPendingRecord(value: unknown): value is PendingConsentRecord {
  if (!value || typeof value !== "object") return false;
  const r = value as Record<string, unknown>;
  return (
    (r.services === undefined || isServiceList(r.services)) &&
    (r.generation === undefined || isGeneration(r.generation)) &&
    (r.decision === "accepted" || r.decision === "declined") &&
    (r.action === "first" || r.action === "change") &&
    (r.variant === "bar" || r.variant === "modal") &&
    typeof r.path === "string" &&
    typeof r.locale === "string" &&
    typeof r.clientAt === "string"
  );
}

/**
 * Null si no hay nada de ESTA generación: nada guardado, otra generación o
 * basura. Con `status: null` solo hay registros antiguos por enviar.
 */
export function parseStoredConsent(raw: string | null): StoredConsent | null {
  if (raw === null) return null;
  try {
    const data: unknown = JSON.parse(raw);
    if (!data || typeof data !== "object") return null;
    const d = data as Record<string, unknown>;
    if (d.v !== CONSENT_GENERATION) return null;
    if (d.status !== "accepted" && d.status !== "declined" && d.status !== null) {
      return null;
    }
    return {
      v: CONSENT_GENERATION,
      status: d.status,
      services: isServiceList(d.services) ? d.services : null,
      receipt: typeof d.receipt === "string" ? d.receipt : null,
      pending: Array.isArray(d.pending) ? d.pending.filter(isPendingRecord) : [],
    };
  } catch {
    return null;
  }
}

export function readStoredConsent(storage: Storage): StoredConsent | null {
  return parseStoredConsent(storage.getItem(CONSENT_KEY));
}

/** ¿La decisión guardada cubre todos los servicios que hoy se cargarían? */
export function consentCovers(
  stored: StoredConsent,
  current: readonly TrackingServiceName[],
): boolean {
  if (stored.status !== "accepted" || stored.services === null) return true;
  const covered = stored.services;
  return current.every((name) => covered.includes(name));
}

/**
 * La decisión que VALE hoy: «sin respuesta» si no hay ninguna vigente o si
 * es un «Aceptar» que no nombraba algún servicio configurado ahora.
 */
export function effectiveConsent(
  stored: StoredConsent | null,
  current: readonly TrackingServiceName[],
): EffectiveConsent {
  if (!stored || stored.status === null) return "unknown";
  return consentCovers(stored, current) ? stored.status : "unknown";
}

/**
 * Una decisión anterior al campo `services` se queda con lo configurado hoy,
 * que es lo que su banner nombraba. `true` si la actualizó.
 */
export function adoptLegacyScope(
  storage: Storage,
  current: readonly TrackingServiceName[],
): boolean {
  const stored = readStoredConsent(storage);
  if (!stored || stored.status === null || stored.services !== null) return false;
  write(storage, { ...stored, services: [...current] });
  return true;
}

/** Registros de la cola de un almacén de otra generación, con la suya puesta. */
function obsoletePending(raw: string): {
  pending: PendingConsentRecord[];
  receipt: string | null;
} {
  try {
    const data: unknown = JSON.parse(raw);
    if (!data || typeof data !== "object") return { pending: [], receipt: null };
    const d = data as Record<string, unknown>;
    const generation = isGeneration(d.v) ? d.v : undefined;
    const pending = Array.isArray(d.pending)
      ? d.pending
          .filter(isPendingRecord)
          .map((p) => (p.generation || !generation ? p : { ...p, generation }))
      : [];
    return { pending, receipt: typeof d.receipt === "string" ? d.receipt : null };
  } catch {
    return { pending: [], receipt: null };
  }
}

/**
 * ¿Lo guardado es de una generación MÁS NUEVA que la de este bundle? Pasa
 * cuando el navegador monta una copia vieja del documento (su caché, una
 * pestaña restaurada) después de un deploy que subió la generación. Ese
 * bundle no entiende la decisión, pero no puede retirarla ni volver a
 * preguntar: hasta el 2026-10-01 la borraba, el banner reaparecía y, al
 * responderlo, guardaba una decisión de SU generación que la página
 * siguiente —ya con el bundle vigente— descartaba para preguntar otra vez.
 */
function isNewerGeneration(raw: string | null): boolean {
  if (raw === null) return false;
  try {
    const data: unknown = JSON.parse(raw);
    if (!data || typeof data !== "object") return false;
    const v = (data as Record<string, unknown>).v;
    return isGeneration(v) && v > CONSENT_GENERATION;
  } catch {
    return false;
  }
}

export function hasNewerConsent(storage: Storage): boolean {
  return isNewerGeneration(storage.getItem(CONSENT_KEY));
}

/**
 * Retira una decisión guardada que ya no vale (de una generación ANTERIOR o
 * ilegible). Una de una generación más nueva se deja como está
 * (`hasNewerConsent`). `true` si había una: quien llama borra entonces las cookies de
 * seguimiento que pudo dejar, porque ese «Aceptar» ya no las cubre.
 *
 * Lo que esa decisión aún no había registrado NO se pierde: una retirada que
 * no llegó al servidor antes del reinicio sigue siendo la prueba de algo que
 * la persona hizo. Se conserva en la cola, con su generación, bajo un almacén
 * sin decisión vigente (`status: null`), y se envía como cualquier otra.
 */
export function discardObsoleteConsent(storage: Storage): boolean {
  const raw = storage.getItem(CONSENT_KEY);
  if (raw === null || parseStoredConsent(raw) !== null) return false;
  if (isNewerGeneration(raw)) return false;
  const { pending, receipt } = obsoletePending(raw);
  if (pending.length === 0) {
    storage.removeItem(CONSENT_KEY);
  } else {
    write(storage, {
      v: CONSENT_GENERATION,
      status: null,
      services: null,
      receipt,
      pending: pending.slice(-MAX_PENDING),
    });
  }
  return true;
}

function write(storage: Storage, value: StoredConsent): void {
  // Sin decisión vigente ni nada por enviar no queda nada que guardar.
  if (value.status === null && value.pending.length === 0) {
    storage.removeItem(CONSENT_KEY);
    return;
  }
  storage.setItem(CONSENT_KEY, JSON.stringify(value));
}

/**
 * Guarda la decisión y, salvo `enqueue: false`, la encola para el registro. Sin
 * el módulo `consent-log` no hay registro al que enviarla: la decisión vale en
 * este navegador y nada se encola (la cola reenviaría sin fin a un 404).
 */
export function storeDecision(
  storage: Storage,
  record: PendingConsentRecord,
  options: { enqueue?: boolean } = {},
): void {
  const current = readStoredConsent(storage);
  const queue = current?.pending ?? [];
  write(storage, {
    v: CONSENT_GENERATION,
    status: record.decision,
    services: record.services ?? null,
    receipt: current?.receipt ?? null,
    pending: (options.enqueue === false ? queue : [...queue, record]).slice(
      -MAX_PENDING,
    ),
  });
}

function isSameRecord(a: PendingConsentRecord, b: PendingConsentRecord): boolean {
  return a.clientAt === b.clientAt && a.decision === b.decision;
}

/**
 * El servidor rechazó `record` de forma PERMANENTE (4xx): sale de la cola sin
 * comprobante. Sin esto se reenviaría en cada carga para siempre.
 */
export function dropPending(storage: Storage, record: PendingConsentRecord): void {
  const current = readStoredConsent(storage);
  if (!current) return;
  write(storage, {
    ...current,
    pending: current.pending.filter((p) => !isSameRecord(p, record)),
  });
}

/** El servidor registró `record`: sale de la cola y queda su comprobante. */
export function acknowledgePending(
  storage: Storage,
  record: PendingConsentRecord,
  receipt: string,
): void {
  const current = readStoredConsent(storage);
  if (!current) return;
  write(storage, {
    ...current,
    receipt,
    pending: current.pending.filter((p) => !isSameRecord(p, record)),
  });
}
