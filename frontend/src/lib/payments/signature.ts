import "server-only";
import crypto from "node:crypto";

// ============================================================================
//  Helpers de firma compartidos por las pasarelas (integridad / webhooks).
//
//  Las pasarelas LATAM firman sus payloads con HMAC-SHA256 (Wompi events,
//  Bold) o concatenan campos y aplican SHA-256/MD5 (PayU). Centralizamos aquí
//  las primitivas para que cada provider solo describa SU fórmula de firma.
// ============================================================================

/** HMAC-SHA256 del payload con `secret`, codificado en hex o base64. */
export function hmacSha256(
  payload: string,
  secret: string,
  encoding: "hex" | "base64" = "hex",
): string {
  return crypto.createHmac("sha256", secret).update(payload, "utf8").digest(encoding);
}

/** SHA-256 de una cadena (algunas pasarelas firman concatenando campos). */
export function sha256(payload: string, encoding: "hex" | "base64" = "hex"): string {
  return crypto.createHash("sha256").update(payload, "utf8").digest(encoding);
}

/**
 * Comparación en tiempo constante de dos cadenas (evita timing attacks al
 * validar firmas). Devuelve false si difieren en longitud o contenido.
 */
export function safeEqual(a: string, b: string): boolean {
  try {
    const bufA = Buffer.from(a);
    const bufB = Buffer.from(b);
    if (bufA.length !== bufB.length) return false;
    return crypto.timingSafeEqual(bufA, bufB);
  } catch {
    return false;
  }
}
