import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  El secreto TOTP no puede salir del dominio.
//
//  El alta de 2FA construía el QR con `api.qrserver.com/…/create-qr-code/?data=
//  <otpauth://…?secret=…>`, enviando el secreto compartido a un tercero dentro
//  de la URL (queda en sus logs y en el historial del navegador): quien lo
//  obtenga puede generar códigos válidos para siempre, lo que anula el segundo
//  factor. Ahora el QR lo genera el BFF (`/api/auth/2fa/qr`).
//
//  Esta guarda impide que vuelva a colarse un generador de QR externo, tanto en
//  el código como en la CSP (que es lo que permitiría cargar su imagen).
// ============================================================================

const FRONTEND = fileURLToPath(new URL("../..", import.meta.url));

/** Hosts de servicios de QR de terceros conocidos. Ampliar si aparece otro. */
const QR_HOSTS =
  "qrserver\\.com|chart\\.googleapis\\.com/chart|quickchart\\.io|qr-code-generator\\.com|goqr\\.me";

/** Cualquier mención del host (basta en la CSP: un host ahí ya es una autorización). */
const THIRD_PARTY_QR = new RegExp(QR_HOSTS, "i");

/**
 * Un USO real en el código: una URL con esquema. Los comentarios que explican el
 * historial del problema o los tests que comprueban su ausencia nombran el host
 * sin URL y no cuentan.
 */
const THIRD_PARTY_QR_URL = new RegExp(`https?://[^\\s"'\`)]*(${QR_HOSTS})`, "i");

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) return sourceFiles(full);
    return /\.(tsx?|jsx?|mjs|json)$/.test(name) ? [full] : [];
  });
}

describe("el secreto TOTP no sale a un tercero", () => {
  it("la CSP de next.config.mjs no autoriza ningún generador de QR externo", () => {
    const config = readFileSync(join(FRONTEND, "next.config.mjs"), "utf8");

    expect(config).not.toMatch(THIRD_PARTY_QR);
  });

  it("ningún archivo de src/ llama a un generador de QR externo", () => {
    const srcDir = join(FRONTEND, "src");
    const offenders = sourceFiles(srcDir)
      .filter((file) => THIRD_PARTY_QR_URL.test(readFileSync(file, "utf8")))
      .map((file) => relative(FRONTEND, file));

    expect(offenders).toEqual([]);
  });

  it("el QR se sirve desde nuestro propio endpoint", () => {
    const component = readFileSync(
      join(FRONTEND, "src/components/account/two-factor-setup.tsx"),
      "utf8",
    );

    expect(component).toContain('"/api/auth/2fa/qr"');
  });
});
