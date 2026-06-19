import "server-only";
import { cookies } from "next/headers";
import { cache } from "react";
import { fetchGraphQL, type GraphQLRequestOptions } from "@/lib/graphql-client";
import { AUTH_COOKIE } from "./constants";
import { verifyAuthToken } from "./jwt";
import { isTokenRevoked } from "./revocation";
import type { Session } from "@/types/auth";

// ============================================================================
//  Acceso a la sesión desde Server Components y Route Handlers.
//  `cache()` memoiza el resultado durante el ciclo de vida del request, así no
//  re-verificamos el JWT varias veces en el mismo render.
// ============================================================================

/** Devuelve el JWT de acceso crudo desde la cookie, o null. */
export const getAuthToken = cache(async (): Promise<string | null> => {
  const store = await cookies();
  return store.get(AUTH_COOKIE)?.value ?? null;
});

/**
 * Devuelve la sesión verificada (firma + vigencia del JWT) o null.
 * No lanza: pensado para lógica de UI condicional.
 */
export const getSession = cache(async (): Promise<Session | null> => {
  const token = await getAuthToken();
  if (!token) {
    return null;
  }
  const payload = await verifyAuthToken(token);
  if (!payload) {
    return null;
  }
  // Revocación: un token en la blocklist (logout) se rechaza aunque su firma
  // siga siendo válida. Fail-open si Redis no responde.
  if (await isTokenRevoked(token)) {
    return null;
  }
  return { userId: payload.userId, token };
});

/**
 * Exige sesión. Lanza si no hay usuario autenticado: úsalo en Route Handlers /
 * páginas protegidas (combínalo con redirección a /login donde proceda).
 */
export async function requireSession(): Promise<Session> {
  const session = await getSession();
  if (!session) {
    throw new Error("UNAUTHENTICATED");
  }
  return session;
}

/**
 * Ejecuta una query GraphQL inyectando automáticamente el JWT de la cookie.
 * Atajo para que los RSC consulten datos del usuario autenticado.
 */
export async function fetchGraphQLAsViewer<TData>(
  query: string,
  options: Omit<GraphQLRequestOptions, "authToken"> = {},
): Promise<TData> {
  const token = await getAuthToken();
  return fetchGraphQL<TData>(query, { ...options, authToken: token ?? undefined });
}
