import "server-only";

// ============================================================================
//  Guard de arranque: impide levantar el servidor en PRODUCCIÓN con secretos
//  por defecto o demasiado cortos. Convierte el error de despliegue más común
//  y costoso (dejar `changeme-*`) en un fallo temprano y barato.
//
//  Se invoca desde `instrumentation.ts` (register), solo en runtime Node y solo
//  cuando NODE_ENV === "production". En desarrollo no estorba.
// ============================================================================

/** Valores por defecto que NUNCA deben llegar a producción. */
const FORBIDDEN_DEFAULTS = new Set([
  "changeme-super-secret-jwt-key",
  "changeme-super-secret-csrf-key",
  "changeme-super-secret-webhook-key",
  "changeme-hwe-revalidation-secret",
  "noop-sandbox-secret",
  "insecure-dev-csrf-secret",
  "fallback-dev-secret-change-in-prod",
]);

/** Longitud mínima aceptable para un secreto en producción. */
const MIN_SECRET_LENGTH = 24;

interface SecretRule {
  name: string;
  /** Obligatorio (debe existir y ser válido). Si false, solo se valida si existe. */
  required: boolean;
  /** Exigir longitud mínima (secretos largos). */
  checkLength?: boolean;
}

const RULES: SecretRule[] = [
  { name: "GRAPHQL_JWT_AUTH_SECRET_KEY", required: true, checkLength: true },
  { name: "CSRF_SECRET", required: true, checkLength: true },
  { name: "WC_WEBHOOK_SECRET", required: true, checkLength: true },
  { name: "HWE_REVALIDATION_SECRET", required: true, checkLength: true },
  { name: "WC_CONSUMER_KEY", required: true },
  { name: "WC_CONSUMER_SECRET", required: true },
];

/**
 * Valida los secretos. Devuelve la lista de problemas (vacía = todo correcto).
 * No lanza: el llamador decide qué hacer (abortar en prod).
 */
export function findSecretProblems(): string[] {
  const problems: string[] = [];

  for (const rule of RULES) {
    const value = process.env[rule.name];

    if (!value) {
      if (rule.required) problems.push(`${rule.name} no está definido.`);
      continue;
    }
    if (FORBIDDEN_DEFAULTS.has(value)) {
      problems.push(`${rule.name} sigue con un valor por defecto inseguro.`);
      continue;
    }
    if (rule.checkLength && value.length < MIN_SECRET_LENGTH) {
      problems.push(
        `${rule.name} es demasiado corto (mínimo ${MIN_SECRET_LENGTH} caracteres).`,
      );
    }
  }

  return problems;
}

/**
 * En producción, aborta el arranque si hay secretos inseguros. En cualquier
 * otro entorno, solo avisa por consola (no bloquea el desarrollo).
 */
export function assertSecrets(): void {
  const problems = findSecretProblems();
  if (problems.length === 0) return;

  const isProd = process.env.NODE_ENV === "production";
  const header = isProd
    ? "🚫 Arranque abortado: secretos inseguros en producción"
    : "⚠️  Secretos inseguros detectados (permitido solo en desarrollo)";

  const message = `${header}\n` + problems.map((p) => `  - ${p}`).join("\n");

  if (isProd) {
    // Abortar: mejor caer al arrancar que servir con secretos por defecto.
    throw new Error(message);
  }
  // eslint-disable-next-line no-console
  console.warn(message);
}
