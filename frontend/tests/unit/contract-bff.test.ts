import { existsSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { buildBffOpenApi } from "../../../tools/contract/bff-openapi.mjs";

// ============================================================================
//  `contract/bff.openapi.json`: el inventario del BFF que lee una máquina. Se
//  genera desde los `route.ts` reales (tools/contract/bff-openapi.mjs); si una
//  ruta, un método o una guarda cambia y el fichero no, este test falla.
//  Regenerar: HWE_UPDATE_CONTRACT=1 npx vitest run tests/unit/contract-bff.test.ts
// ============================================================================

const ROOT = path.resolve(__dirname, "../../..");
const SNAPSHOT = path.join(ROOT, "contract/bff.openapi.json");

type Operation = Record<string, unknown>;
type Paths = Record<string, Record<string, Operation>>;

describe("contract/bff.openapi.json", () => {
  const generated = JSON.stringify(buildBffOpenApi(), null, 2) + "\n";
  if (process.env.HWE_UPDATE_CONTRACT === "1") writeFileSync(SNAPSHOT, generated);
  const paths = (JSON.parse(generated) as { paths: Paths }).paths;

  it("coincide con las rutas del código", () => {
    expect(existsSync(SNAPSHOT)).toBe(true);
    expect(readFileSync(SNAPSHOT, "utf8")).toBe(generated);
  });

  it("no incluye las rutas de la instancia (app/api/instance)", () => {
    expect(Object.keys(paths).some((p) => p.startsWith("/api/instance/"))).toBe(false);
  });

  it("toda escritura del navegador lleva CSRF salvo las dos de consentimiento", () => {
    const withoutCsrf = Object.entries(paths).flatMap(([url, item]) =>
      Object.entries(item)
        .filter(([, op]) => op["x-hwe-csrf"] === false)
        .map(([method]) => `${method} ${url}`),
    );
    // El banner de cookies escribe antes de que exista un token: lo acota el Origin.
    expect(withoutCsrf.sort()).toEqual([
      "post /api/consent",
      "post /api/consent-stats",
    ]);
  });

  it("los webhooks se autentican por firma", () => {
    expect(paths["/api/payments/webhook/{provider}"].post["x-hwe-auth"]).toBe(
      "signature",
    );
    expect(paths["/api/revalidate"].post["x-hwe-auth"]).toBe("signature");
  });

  it("anota módulo, flag y sesión", () => {
    expect(paths["/api/loyalty/transfer"].post["x-hwe-module"]).toEqual(["loyalty"]);
    expect(paths["/api/store/wishlist"].post).toMatchObject({
      "x-hwe-flag": ["wishlist_enabled"],
      "x-hwe-session": "required",
      "x-hwe-csrf": true,
    });
  });
});
