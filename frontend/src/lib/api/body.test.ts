import { describe, expect, it } from "vitest";
import {
  BodyTooLarge,
  MAX_JSON_BODY_BYTES,
  assertBodyWithinLimit,
  readJsonBody,
  readTextBody,
} from "./body";

// ============================================================================
//  Tope de bytes por cuerpo en el BFF.
//
//  Antes, cada Route Handler materializaba el cuerpo entero con
//  `request.text()`/`request.json()`: no había ningún tope por petición y un
//  POST anónimo de decenas de MB se buffereaba y se hasheaba antes de decidir
//  nada. Este lector compartido corta por `Content-Length` declarado o por
//  conteo real de bytes (cuerpos troceados sin longitud), con 413.
// ============================================================================

/** Stream que produce `chunk` hasta `totalBytes`, contando lo entregado. */
function countedStream(totalBytes: number, chunkSize = 4096) {
  const chunk = new Uint8Array(chunkSize).fill(65); // "A"
  let sent = 0;
  let cancelled = false;
  const stream = new ReadableStream<Uint8Array>({
    pull(controller) {
      if (sent + chunkSize > totalBytes) {
        controller.close();
        return;
      }
      sent += chunkSize;
      controller.enqueue(chunk);
    },
    cancel() {
      cancelled = true;
    },
  });
  return { stream, sent: () => sent, cancelled: () => cancelled };
}

function streamRequest(
  stream: ReadableStream<Uint8Array>,
  headers: Record<string, string> = {},
) {
  return new Request("https://tienda.example/api/prueba", {
    method: "POST",
    headers: { "content-type": "application/json", ...headers },
    body: stream,
    duplex: "half",
  } as RequestInit);
}

describe("readTextBody: tope por Content-Length declarado", () => {
  it("rechaza sin leer cuando la longitud declarada supera el tope", async () => {
    const { stream, sent } = countedStream(1024 * 1024);
    const request = streamRequest(stream, {
      "content-length": String(MAX_JSON_BODY_BYTES + 1),
    });

    await expect(readTextBody(request)).rejects.toBeInstanceOf(BodyTooLarge);
    // El stream puede haber entregado un bloque al construirse el Request; lo
    // que importa es que el lector NO lo ha consumido (ni tirado del resto).
    expect(request.bodyUsed).toBe(false);
    expect(sent()).toBeLessThanOrEqual(4096);
  });

  it("no rechaza una longitud declarada dentro del tope", async () => {
    const body = "hola";

    expect(
      await readTextBody(
        new Request("https://tienda.example/api/prueba", { method: "POST", body }),
      ),
    ).toBe(body);
  });
});

describe("readTextBody: conteo real de bytes (sin Content-Length)", () => {
  it("corta al pasar el tope, cancela el stream y no consume el resto", async () => {
    const { stream, sent, cancelled } = countedStream(1024 * 1024);
    const request = streamRequest(stream);

    await expect(readTextBody(request, 8 * 1024)).rejects.toBeInstanceOf(BodyTooLarge);
    expect(cancelled()).toBe(true);
    // 8 KiB de tope + un bloque de 4 KiB como máximo: nunca el MiB entero.
    expect(sent()).toBeLessThanOrEqual(12 * 1024);
  });

  it("devuelve el texto completo cuando cabe", async () => {
    const request = new Request("https://tienda.example/api/prueba", {
      method: "POST",
      body: "texto normal",
    });

    expect(await readTextBody(request, MAX_JSON_BODY_BYTES)).toBe("texto normal");
  });

  it("un cuerpo vacío es cadena vacía, no un error", async () => {
    const request = new Request("https://tienda.example/api/prueba", {
      method: "POST",
    });

    expect(await readTextBody(request)).toBe("");
  });
});

describe("readJsonBody", () => {
  it("parsea un JSON dentro del tope", async () => {
    const request = new Request("https://tienda.example/api/prueba", {
      method: "POST",
      body: JSON.stringify({ cart: "ok" }),
    });

    expect(await readJsonBody<{ cart: string }>(request)).toEqual({ cart: "ok" });
  });

  it("mantiene el comportamiento de request.json() con JSON inválido", async () => {
    const request = new Request("https://tienda.example/api/prueba", {
      method: "POST",
      body: "{no es json",
    });

    await expect(readJsonBody(request)).rejects.toBeInstanceOf(SyntaxError);
  });
});

describe("assertBodyWithinLimit", () => {
  it("no lanza si no hay cabecera", () => {
    expect(() =>
      assertBodyWithinLimit(
        new Request("https://tienda.example/api/prueba", { method: "POST" }),
        10,
      ),
    ).not.toThrow();
  });

  it("lanza BodyTooLarge con la longitud declarada por encima del tope", () => {
    const request = new Request("https://tienda.example/api/prueba", {
      method: "POST",
      body: "x",
    });
    request.headers.set("content-length", "2048");

    expect(() => assertBodyWithinLimit(request, 1024)).toThrow(BodyTooLarge);
  });
});
