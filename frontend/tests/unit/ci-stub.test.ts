import { readFileSync } from "node:fs";
import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { afterEach, describe, expect, it } from "vitest";
import {
  buildSiteConfig,
  createStubServer,
} from "../../../.github/scripts/stub-graphql.mjs";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// ============================================================================
//  Endpoint GraphQL de pega del CI (.github/scripts/stub-graphql.mjs) y su
//  fixture de configuración (.github/scripts/site-config.ci.json).
// ============================================================================

const FIXTURE_URL = new URL(
  "../../../.github/scripts/site-config.ci.json",
  import.meta.url,
);
const fixture = JSON.parse(readFileSync(FIXTURE_URL, "utf8"));

let server: Server | undefined;

/** Arranca el stub en un puerto libre y devuelve su URL base. */
async function startStub(): Promise<string> {
  server = createStubServer(buildSiteConfig(fixture));
  await new Promise<void>((ok) => server!.listen(0, "127.0.0.1", ok));
  return `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
}

afterEach(async () => {
  if (server) await new Promise((ok) => server!.close(ok));
  server = undefined;
});

describe("fixture site-config.ci.json", () => {
  it("la paleta es la POR DEFECTO del framework (no diverge de CONFIG_DEFAULTS)", () => {
    // El CI sirve esta paleta al frontend: si cambia el valor por defecto, el
    // fixture debe cambiar con él, o axe mediría una paleta que ya no existe.
    expect(fixture.design.colors).toEqual(CONFIG_DEFAULTS.design.colors);
  });
});

describe("buildSiteConfig", () => {
  it("expone solo `design.colors`: nada de tipografía (traería Google Fonts) ni metadatos", () => {
    const config = buildSiteConfig({
      _comentario: "no debe salir",
      design: { colors: { brand: "#111111" }, typography: { font_url: "https://x" } },
    });
    expect(config).toEqual({ design: { colors: { brand: "#111111" } } });
  });
});

describe("createStubServer", () => {
  it("la ruta de config devuelve la paleta del fixture", async () => {
    const base = await startStub();

    const res = await fetch(`${base}/wp-json/hwe/v1/config`);

    expect(res.status).toBe(200);
    expect(res.headers.get("content-type")).toContain("application/json");
    expect(await res.json()).toEqual({ design: { colors: fixture.design.colors } });
  });

  it("la ruta de config ignora la query string", async () => {
    const base = await startStub();

    const res = await fetch(`${base}/wp-json/hwe/v1/config?_=1`);

    expect(await res.json()).toHaveProperty("design.colors.brand");
  });

  it("cualquier otra ruta responde {data:{}} (CMS vacío), también a un POST con cuerpo", async () => {
    const base = await startStub();

    const get = await fetch(`${base}/cualquier/ruta`);
    const post = await fetch(`${base}/graphql`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ query: "{ products { nodes { id } } }" }),
    });

    expect(await get.json()).toEqual({ data: {} });
    expect(post.status).toBe(200);
    expect(await post.json()).toEqual({ data: {} });
  });
});
