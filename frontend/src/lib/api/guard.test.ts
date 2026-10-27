import { describe, expect, it } from "vitest";
import { MAX_JSON_BODY_BYTES } from "./body";
import { guardMutation } from "./guard";

// ============================================================================
//  `guardMutation` es el punto central del tope de bytes para las escrituras
//  del BFF: cualquier POST/PUT/PATCH/DELETE con `Content-Length` por encima del
//  tope responde 413 antes de Origin, CSRF o rate-limit.
// ============================================================================

function post(contentLength?: number): Request {
  const request = new Request("https://tienda.example/api/auth/login", {
    method: "POST",
    body: "{}",
  });
  if (contentLength !== undefined) {
    request.headers.set("content-length", String(contentLength));
  }
  return request;
}

describe("guardMutation: tope de cuerpo", () => {
  it("rechaza con 413 un Content-Length por encima del tope, antes de CSRF", async () => {
    const res = await guardMutation(post(MAX_JSON_BODY_BYTES + 1), {
      rateLimit: { name: "prueba", limit: 5, windowSeconds: 60 },
    });

    expect(res?.status).toBe(413);
    await expect(res!.json()).resolves.toMatchObject({
      error: expect.stringContaining("demasiado grande"),
    });
  });

  it("deja pasar un cuerpo dentro del tope", async () => {
    const res = await guardMutation(post(MAX_JSON_BODY_BYTES), { csrf: false });

    expect(res).toBeNull();
  });

  it("respeta un tope explícito mayor para una ruta concreta", async () => {
    const res = await guardMutation(post(MAX_JSON_BODY_BYTES + 1), {
      csrf: false,
      maxBodyBytes: MAX_JSON_BODY_BYTES * 2,
    });

    expect(res).toBeNull();
  });
});
