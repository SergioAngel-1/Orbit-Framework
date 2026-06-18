"use client";

// ============================================================================
//  Obtención del token CSRF en el cliente.
//  Lee la cookie `hwe_csrf` (no httpOnly); si no existe, la solicita a
//  /api/csrf. El token se reenvía en la cabecera `X-CSRF-Token`.
// ============================================================================

const CSRF_COOKIE = "hwe_csrf";

function readCookie(name: string): string | null {
  const match = document.cookie.match(
    new RegExp("(?:^|; )" + name.replace(/([.$?*|{}()[\]\\/+^])/g, "\\$1") + "=([^;]*)"),
  );
  return match ? decodeURIComponent(match[1]) : null;
}

export async function getCsrfToken(): Promise<string> {
  const fromCookie = readCookie(CSRF_COOKIE);
  if (fromCookie) return fromCookie;

  const res = await fetch("/api/csrf", { credentials: "same-origin" });
  if (!res.ok) throw new Error("No se pudo obtener el token CSRF.");
  const json = (await res.json()) as { csrfToken: string };
  return json.csrfToken;
}

/** Envuelve fetch añadiendo CSRF + JSON para peticiones de escritura. */
export async function csrfFetch(
  input: string,
  init: { method: string; body?: unknown; headers?: Record<string, string> },
): Promise<Response> {
  const token = await getCsrfToken();
  return fetch(input, {
    method: init.method,
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": token,
      ...(init.headers ?? {}),
    },
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  });
}
