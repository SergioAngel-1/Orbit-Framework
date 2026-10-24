import type { MembershipRequiredLevel, MembershipRequirement } from "./types";

// ============================================================================
//  Lectura tolerante del requisito de membresía de la ficha de escaparate.
//
//  El mismo dato llega con dos formas: GraphQL usa `requiredLevel`/`opensAt` y
//  las rutas internas de WordPress `required_level`/`opens_at`. Con el módulo
//  apagado no llega nada. Todo se normaliza al mismo tipo y cualquier valor
//  inválido degrada a «sin requisito»: una ficha nunca se rompe por un requisito
//  mal formado (seguridad aparte: el bloqueo real lo decide WordPress).
// ============================================================================

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function textOf(value: unknown): string | null {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : null;
}

function integerOf(value: unknown): number | null {
  const parsed =
    typeof value === "number"
      ? value
      : typeof value === "string" && value.trim() !== ""
        ? Number(value)
        : Number.NaN;

  return Number.isInteger(parsed) ? parsed : null;
}

function levelOf(raw: unknown): MembershipRequiredLevel | null {
  if (!isRecord(raw)) return null;

  const slug = textOf(raw.slug);
  const rank = integerOf(raw.rank);
  const name = textOf(raw.name);
  if (slug === null || rank === null || rank < 1) return null;

  return { slug, rank, name: name ?? "" };
}

/**
 * Normaliza el requisito venga de GraphQL o de las rutas internas de WordPress.
 * `null` cuando el módulo no expone el campo (apagado o respuesta antigua).
 */
export function normalizeMembershipRequirement(
  raw: unknown,
): MembershipRequirement | null {
  if (!isRecord(raw)) return null;

  return {
    requiredLevel: levelOf(raw.requiredLevel ?? raw.required_level),
    // Las dos formas usan el mismo formato ISO 8601 UTC; una fecha que no sea
    // texto no puede ser una apertura programada y se descarta.
    opensAt: textOf(raw.opensAt ?? raw.opens_at),
    locked: raw.locked === true,
  };
}

/** ¿La ficha está bloqueada para la petición actual? Solo con marca explícita. */
export function isMembershipLocked(requirement: MembershipRequirement | null): boolean {
  return requirement?.locked === true;
}

/** Segundos → días, sin redondear a la baja (una cola de 30 días son 30). */
export function secondsToDays(seconds: number): number {
  if (!Number.isFinite(seconds) || seconds <= 0) return 0;
  return seconds / 86_400;
}
