import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { analyzeSignedRoute } from "../helpers/route-signature";

// ============================================================================
//  El analizador de `signed-routes.test.ts`: reconoce llamadas REALES a un
//  verificador (no texto citado en comentarios) y los envoltorios propios.
// ============================================================================

const FIXTURES = fileURLToPath(
  new URL("../fixtures/route-signature/", import.meta.url),
);
const ROUTE = join(FIXTURES, "route.ts");

const verifiesBeforeActing = (source: string) => {
  const { verifierAt, sideEffectAt } = analyzeSignedRoute(ROUTE, source);
  return verifierAt >= 0 && (sideEffectAt < 0 || verifierAt < sideEffectAt);
};

describe("analyzeSignedRoute", () => {
  it("un verificador citado solo en un comentario o una cadena no cuenta", () => {
    const source = `
      // TODO: llamar a verifyHmacSignature(raw, sig, secret) antes de actuar.
      import { revalidateTag } from "next/cache";
      const help = "usa verifyHmacSignature(raw, sig) aquí";
      export async function POST() {
        revalidateTag("x", "max");
        return new Response(null);
      }`;
    expect(analyzeSignedRoute(ROUTE, source).verifierAt).toBe(-1);
    expect(verifiesBeforeActing(source)).toBe(false);
  });

  it("un efecto citado en un comentario no adelanta al verificador", () => {
    const source = `
      // Antes esta ruta llamaba a revalidateTag("x") sin verificar.
      import { revalidateTag } from "next/cache";
      import { verifyHmacSignature } from "@/lib/security/webhook";
      export async function POST(request: Request) {
        if (!verifyHmacSignature(await request.text(), "", "", {})) return new Response(null);
        revalidateTag("x", "max");
        return new Response(null);
      }`;
    expect(verifiesBeforeActing(source)).toBe(true);
  });

  it("actuar antes de verificar se detecta", () => {
    const source = `
      import { revalidateTag } from "next/cache";
      import { verifyHmacSignature } from "@/lib/security/webhook";
      export async function POST(request: Request) {
        revalidateTag("x", "max");
        verifyHmacSignature(await request.text(), "", "", {});
        return new Response(null);
      }`;
    expect(verifiesBeforeActing(source)).toBe(false);
  });

  it("un envoltorio local cuenta donde la ruta lo llama, no donde se define", () => {
    const wrapped = (handler: string) => `
      import { revalidateTag } from "next/cache";
      import { verifyHmacSignature } from "@/lib/security/webhook";
      function verifyPartner(raw: string): boolean {
        return verifyHmacSignature(raw, "", "", {});
      }
      export async function POST(request: Request) { ${handler} }`;
    expect(
      verifiesBeforeActing(
        wrapped(
          `if (!verifyPartner(await request.text())) return; revalidateTag("x", "max");`,
        ),
      ),
    ).toBe(true);
    expect(
      verifiesBeforeActing(
        wrapped(`revalidateTag("x", "max"); verifyPartner(await request.text());`),
      ),
    ).toBe(false);
  });

  it("un envoltorio importado cuenta si su definición llama a un verificador", () => {
    const route = (name: string) => `
      import { revalidateTag } from "next/cache";
      import { ${name} as check } from "./wrapper";
      export async function POST(request: Request) {
        if (!check(await request.text(), null)) return new Response(null, { status: 401 });
        revalidateTag("x", "max");
        return new Response(null);
      }`;
    expect(verifiesBeforeActing(route("verifyPartnerSignature"))).toBe(true);
    expect(verifiesBeforeActing(route("verifyNothing"))).toBe(false);
  });

  it("una función local con nombre de verificador del núcleo que no verifica no cuenta", () => {
    const source = `
      import { revalidateTag } from "next/cache";
      function verifyHweSignature(): boolean { return true; }
      export async function POST() {
        if (!verifyHweSignature()) return;
        revalidateTag("x", "max");
      }`;
    expect(verifiesBeforeActing(source)).toBe(false);
  });

  it("provider.verifyWebhook(…) cuenta", () => {
    const source = `
      export async function POST(request: Request) {
        const ok = await provider.verifyWebhook(request);
        if (ok) await markEventOnce("x");
      }`;
    expect(verifiesBeforeActing(source)).toBe(true);
  });
});
