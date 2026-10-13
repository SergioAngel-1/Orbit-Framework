/** Mensajes de WooGraphQL ante un producto que no existe. */
const UNKNOWN_PRODUCT = /No product ID was found|No product exists with/i;

/**
 * ¿Es el error de GraphQL con que WooGraphQL responde a `product(id, idType: SLUG)`
 * ante un producto inexistente? No devuelve `product: null`: lanza un `UserError`.
 * Solo reconoce ese mensaje: cualquier otro error (red, 5xx, GraphQL) NO es «no
 * existe», porque tratarlo así hornearía un 404 en la caché ISR por una caída
 * transitoria.
 */
export function isUnknownProductError(error: unknown): boolean {
  return (
    error instanceof Error &&
    (error as { kind?: unknown }).kind === "graphql" &&
    UNKNOWN_PRODUCT.test(error.message)
  );
}
