// Atributos de la cookie `NEXT_LOCALE` que next-intl fija desde el proxy y, al cambiar de
// idioma, desde el navegador. Por defecto no lleva `Secure`: aunque no guarda nada sensible,
// una cookie sin `Secure` viaja también por HTTP y los escáneres de seguridad la marcan.
//
// Se activa en producción salvo que la URL pública declarada sea `http://` (pila de
// pruebas, `next start` en local), donde un navegador que no trate el host como seguro
// descartaría la cookie. Módulo puro: lo importa también el cliente (`routing.ts`).

export function localeCookieSecure(
  env: Record<string, string | undefined> = process.env,
): boolean {
  if (env.NODE_ENV !== "production") return false;
  return !/^http:\/\//i.test((env.NEXT_PUBLIC_SITE_URL ?? "").trim());
}
