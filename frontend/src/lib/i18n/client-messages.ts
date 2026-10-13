// ============================================================================
//  Mensajes que viajan al navegador.
//
//  `NextIntlClientProvider` serializa en el HTML de CADA página los mensajes que
//  se le pasan, y solo los Client Components los leen (`useTranslations`). Los
//  Server Components resuelven los suyos en el servidor con `getTranslations`, sin
//  pasar por el proveedor. Mandar el catálogo entero metía en cada página, p. ej.,
//  los textos legales completos, que ningún componente de cliente usa.
//
//  Esta lista es el contrato: los namespaces que algún Client Component lee.
//  `client-messages.test.ts` recorre el código y falla si un Client Component usa
//  un namespace que no esté aquí (el síntoma de olvidarlo sería un texto con la
//  clave cruda, solo en el navegador).
// ============================================================================

export const CLIENT_NAMESPACES = [
  "account",
  "addToCart",
  "addresses",
  "blog",
  "cart",
  "changePassword",
  "checkout",
  "common",
  "consent",
  "forgotPassword",
  "form",
  "login",
  "nav",
  "products",
  "register",
  "resetPassword",
  "site",
  "twoFactor",
  "verifyEmail",
  "wishlist",
] as const;

/** Solo los namespaces que usa algún Client Component. */
export function pickClientMessages(
  messages: Record<string, unknown>,
): Record<string, unknown> {
  const picked: Record<string, unknown> = {};
  for (const namespace of CLIENT_NAMESPACES) {
    if (namespace in messages) picked[namespace] = messages[namespace];
  }
  return picked;
}
