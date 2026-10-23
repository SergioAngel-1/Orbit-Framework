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

/**
 * Hosts de loopback, exentos de la exigencia de `https` en producción.
 *
 * `next start` fija `NODE_ENV=production`, así que cualquier arranque local o de
 * CI pasa por las reglas de producción aunque sirva por http en localhost. Sin
 * esta excepción el job e2e de CI no podía arrancar el servidor NUNCA: el guard
 * abortaba el arranque y `wait-on` agotaba su espera.
 *
 * No debilita nada: un origen de loopback no es alcanzable desde fuera de la
 * máquina, así que no puede ser el despliegue real que este guard protege. Un host
 * público por http (`http://tienda.example`) se sigue rechazando, y la comparación
 * es contra el HOSTNAME exacto, no un `includes`, para que
 * `http://localhost.tienda.example` —un dominio real— no se cuele.
 */
const LOOPBACK_HOSTNAMES = new Set(["localhost", "127.0.0.1", "[::1]", "::1"]);

interface SecretRule {
  name: string;
  /** Obligatorio (debe existir y ser válido). Si false, solo se valida si existe. */
  required: boolean;
  /** Exigir longitud mínima (secretos largos). */
  checkLength?: boolean;
  /** Exigir que el valor sea un ORIGEN exacto (esquema://host[:puerto]). */
  checkOrigin?: boolean;
}

const RULES: SecretRule[] = [
  { name: "GRAPHQL_JWT_AUTH_SECRET_KEY", required: true, checkLength: true },
  { name: "CSRF_SECRET", required: true, checkLength: true },
  { name: "WC_WEBHOOK_SECRET", required: true, checkLength: true },
  { name: "HWE_REVALIDATION_SECRET", required: true, checkLength: true },
  { name: "WC_CONSUMER_KEY", required: true },
  { name: "WC_CONSUMER_SECRET", required: true },
  // Sin ALLOWED_ORIGIN, `assertAllowedOrigin` devuelve true y la verificación de
  // Origin de `guardMutation` queda desactivada en silencio: es obligatoria.
  { name: "ALLOWED_ORIGIN", required: true, checkOrigin: true },
];

/**
 * Comprueba que `value` es un origen válido y devuelve el problema, o `null`.
 *
 * La comparación contra la cabecera `Origin` del navegador es IGUALDAD de cadenas,
 * así que el valor tiene que ser exactamente el origen que el navegador envía
 * (`https://tienda.example`, sin barra final, ruta, credenciales ni el puerto por
 * defecto): con cualquier otra forma TODA escritura respondería 403 sin ningún
 * aviso.
 */
function originProblem(name: string, value: string, isProd: boolean): string | null {
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    return `${name} debe ser un origen absoluto (p. ej. https://tienda.example).`;
  }

  if (isProd && !LOOPBACK_HOSTNAMES.has(url.hostname)) {
    if (url.protocol !== "https:") {
      return `${name} debe ser una URL https:// válida en producción.`;
    }
  } else if (url.protocol !== "http:" && url.protocol !== "https:") {
    return `${name} debe ser una URL http(s).`;
  }

  if (url.origin !== value) {
    return (
      `${name} debe ser el origen exacto (esquema://host[:puerto], sin ruta, barra final, ` +
      `credenciales, mayúsculas ni el puerto por defecto): el navegador envía «${url.origin}» ` +
      `y la comparación es literal, así que con «${value}» toda escritura recibiría 403.`
    );
  }
  return null;
}

/**
 * Valida los secretos. Devuelve la lista de problemas (vacía = todo correcto).
 * No lanza: el llamador decide qué hacer (abortar en prod).
 *
 * @param env Entorno a comprobar (para tests). Por defecto, `process.env`.
 */
export function findSecretProblems(
  env: Record<string, string | undefined> = process.env,
): string[] {
  const problems: string[] = [];
  const isProd = env.NODE_ENV === "production";

  for (const rule of RULES) {
    const value = env[rule.name];

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
    if (rule.checkOrigin) {
      const problem = originProblem(rule.name, value, isProd);
      if (problem) problems.push(problem);
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
  console.warn(message);
}
