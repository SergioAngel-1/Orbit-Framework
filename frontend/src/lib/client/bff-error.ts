"use client";

// ============================================================================
//  Error de una llamada al BFF, con lo que la vista necesita para pintarlo.
//
//  El BFF responde los errores como `{ error, details?, code? }` (422 con
//  `details` por campo: `docs/FRONTEND_CONNECT.md` §A.2); lo demás que traiga
//  el cuerpo queda en `body`. Los clientes de
//  `lib/client/*` lanzan esto en vez de devolver la `Response`, así una vista
//  no repite el parseo y puede colocar cada mensaje bajo su campo.
// ============================================================================

export class BffError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** Errores por campo (`{ identity: ["…"] }`); vacío si la ruta no los da. */
    readonly details: Record<string, string[]> = {},
    /** Código estable de la ruta, si lo publica (p. ej. el de WooCommerce). */
    readonly code?: string,
    /**
     * Cuerpo JSON completo de la respuesta, con los campos propios de la ruta
     * (`reason`, el estado de un pedido…); `{}` si no era JSON.
     */
    readonly body: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = "BffError";
  }

  /** Primer error de un campo, o `null`. */
  field(name: string): string | null {
    return this.details[name]?.[0] ?? null;
  }
}

/**
 * Lee el cuerpo JSON de una respuesta del BFF; si no es 2xx lanza `BffError`
 * con el mensaje de la ruta o `fallback`.
 */
export async function readBff<T>(res: Response, fallback: string): Promise<T> {
  const parsed: unknown = await res.json().catch(() => ({}));
  const data =
    parsed && typeof parsed === "object" && !Array.isArray(parsed)
      ? (parsed as Record<string, unknown>)
      : {};
  if (!res.ok) {
    const details =
      data.details && typeof data.details === "object"
        ? (data.details as Record<string, string[]>)
        : {};
    throw new BffError(
      typeof data.error === "string" && data.error ? data.error : fallback,
      res.status,
      details,
      typeof data.code === "string" ? data.code : undefined,
      data,
    );
  }
  return data as T;
}
