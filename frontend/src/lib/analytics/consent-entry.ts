import crypto from "node:crypto";
import type { ConsentRecordRequest } from "./consent-record";
import { trackingServiceNames, type TrackingServices } from "./services";

// ============================================================================
//  Fila del registro de consentimientos tal como la recibe WordPress
//  (`POST /wp-json/hwe/v1/consent/record`). Pura: la arma
//  `consent-ledger.ts` con lo que el servidor sabe de la petición.
//
//  Lo que da valor de prueba lo pone el SERVIDOR, nunca el cuerpo: usuario
//  (sesión), IP y user-agent (cabeceras) y texto enseñado (catálogo de
//  mensajes del mismo build). La hora y la versión de la política las pone
//  WordPress al insertar. Los servicios son los que NOMBRÓ el banner (los
//  declara el navegador, acotados a los nombres conocidos): el texto tiene
//  que ser el que se vio, no el que se vería con la configuración de ahora.
// ============================================================================

export interface ConsentLedgerEntry {
  receipt: string;
  user_id: number | null;
  /** `first` | `change`; WordPress lo convierte en `revoke` si retira una aceptación. */
  action: ConsentRecordRequest["action"];
  decision: ConsentRecordRequest["decision"];
  /** Categorías CONCEDIDAS (con «Solo necesarias», ninguna). */
  analytics: boolean;
  advertising: boolean;
  /** Servicios que ese banner pedía (los que nombraba al decidir). */
  services: string[];
  text_hash: string;
  text: string;
  locale: string;
  path: string | null;
  variant: ConsentRecordRequest["variant"];
  ip: string | null;
  user_agent: string | null;
  client_at: string | null;
  /** Generación del consentimiento con la que se decidió; null si no se sabe. */
  generation: number | null;
}

export function consentTextHash(text: string): string {
  return crypto.createHash("sha256").update(text, "utf8").digest("hex");
}

export function buildConsentLedgerEntry(input: {
  receipt: string;
  userId: string | null;
  request: ConsentRecordRequest;
  services: TrackingServices;
  text: string;
  ip: string | null;
  userAgent: string | null;
}): ConsentLedgerEntry {
  const { request, services } = input;
  const accepted = request.decision === "accepted";
  const userId = input.userId ? Number(input.userId) : NaN;
  return {
    receipt: input.receipt,
    user_id: Number.isInteger(userId) && userId > 0 ? userId : null,
    action: request.action,
    decision: request.decision,
    analytics: accepted && services.analytics,
    advertising: accepted && services.advertising,
    services: trackingServiceNames(services),
    text_hash: consentTextHash(input.text),
    text: input.text,
    locale: request.locale,
    path: request.path,
    variant: request.variant,
    ip: input.ip,
    user_agent: input.userAgent,
    client_at: request.clientAt,
    generation: request.generation,
  };
}
