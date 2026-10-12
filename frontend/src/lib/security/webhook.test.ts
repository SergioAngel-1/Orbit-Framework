import crypto from "node:crypto";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { verifyHmacSignature, verifyWooWebhook } from "./webhook";

// ============================================================================
//  Verificación de firmas HMAC-SHA256 de webhooks.
//
//  Un único verificador sirve a todos los consumidores (WooCommerce en base64,
//  HWE Control Center en hex con prefijo `sha256=`). La comparación es en tiempo
//  constante sobre la firma CODIFICADA tal cual llega: decodificarla con
//  `Buffer.from(sig, "hex" | "base64")` es permisivo (tolera basura al final,
//  relleno ausente o saltos de línea) y dejaba pasar varias cadenas distintas por
//  la misma firma.
// ============================================================================

const SECRET = "s3cr3t-de-prueba";
const BODY = '{"id":1}';
const sign = (body: string, secret: string, enc: "hex" | "base64") =>
  crypto.createHmac("sha256", secret).update(body).digest(enc);

describe("verifyHmacSignature · hex con prefijo (HWE)", () => {
  const opts = { encoding: "hex", prefix: "sha256=" } as const;
  const good = `sha256=${sign(BODY, SECRET, "hex")}`;

  it("acepta la firma correcta", () => {
    expect(verifyHmacSignature(BODY, good, SECRET, opts)).toBe(true);
  });

  it.each([
    ["firma de otro cuerpo", `sha256=${sign('{"id":2}', SECRET, "hex")}`],
    ["firma con otro secreto", `sha256=${sign(BODY, "otro", "hex")}`],
    ["firma truncada", "sha256=deadbeef"],
    ["sin el prefijo exigido", sign(BODY, SECRET, "hex")],
    ["con otro prefijo", `sha1=${sign(BODY, SECRET, "hex")}`],
    ["basura tras una firma válida", `${good}zz`],
    ["espacios tras una firma válida", `${good} `],
    ["en mayúsculas", good.toUpperCase().replace("SHA256=", "sha256=")],
    ["vacía", ""],
  ])("rechaza %s", (_caso, signature) => {
    expect(verifyHmacSignature(BODY, signature, SECRET, opts)).toBe(false);
  });
});

describe("verifyHmacSignature · base64 sin prefijo (WooCommerce)", () => {
  const opts = { encoding: "base64" } as const;
  const good = sign(BODY, SECRET, "base64");

  it("acepta la firma correcta", () => {
    expect(verifyHmacSignature(BODY, good, SECRET, opts)).toBe(true);
  });

  it.each([
    ["firma de otro cuerpo", sign('{"id":2}', SECRET, "base64")],
    ["firma con otro secreto", sign(BODY, "otro", "base64")],
    ["sin el relleno `=`", good.replace(/=+$/, "")],
    ["con un salto de línea intercalado", `${good.slice(0, 4)}\n${good.slice(4)}`],
    ["con basura al final", `${good}!!`],
  ])("rechaza %s", (_caso, signature) => {
    expect(verifyHmacSignature(BODY, signature, SECRET, opts)).toBe(false);
  });
});

describe("verifyHmacSignature · falla cerrado", () => {
  it("con un secreto vacío no valida ni la firma «correcta» de ese secreto", () => {
    const forged = sign(BODY, "", "base64");

    expect(verifyHmacSignature(BODY, forged, "", { encoding: "base64" })).toBe(false);
  });
});

describe("verifyWooWebhook", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.WC_WEBHOOK_SECRET;
    process.env.WC_WEBHOOK_SECRET = SECRET;
  });
  afterEach(() => {
    if (saved === undefined) delete process.env.WC_WEBHOOK_SECRET;
    else process.env.WC_WEBHOOK_SECRET = saved;
  });

  it("valida la firma base64 de WooCommerce con WC_WEBHOOK_SECRET", () => {
    expect(verifyWooWebhook(BODY, sign(BODY, SECRET, "base64"))).toBe(true);
    expect(verifyWooWebhook('{"id":2}', sign(BODY, SECRET, "base64"))).toBe(false);
  });

  it("sin cabecera de firma o sin secreto configurado, rechaza", () => {
    expect(verifyWooWebhook(BODY, null)).toBe(false);
    delete process.env.WC_WEBHOOK_SECRET;
    expect(verifyWooWebhook(BODY, sign(BODY, SECRET, "base64"))).toBe(false);
  });
});
