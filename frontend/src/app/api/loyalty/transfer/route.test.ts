import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { transferPoints } from "@/lib/loyalty/server";
import {
  releaseIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
} from "@/lib/security/idempotency";
import { POST } from "./route";

// ============================================================================
//  POST /api/loyalty/transfer.
//
//  Escritura crítica: `guardMutation` estricto, `Idempotency-Key` válida y
//  acotada por usuario (dos usuarios con la misma clave no colisionan). Repetir
//  la clave devuelve el mismo resultado sin volver a llamar a WordPress.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/loyalty/server", () => ({ transferPoints: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/security/idempotency", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/security/idempotency")>()),
  reserveIdempotencyKey: vi.fn(),
  storeIdempotentResult: vi.fn(async () => undefined),
  releaseIdempotencyKey: vi.fn(async () => undefined),
}));

const state = vi.hoisted(() => ({
  site: {
    modules: { loyalty: true as boolean },
    loyalty: {
      points_enabled: true,
      referrals_enabled: true,
      transfers_enabled: true,
      point_value: "0.25",
      currency_name: "punto",
      currency_name_plural: "puntos",
      currency_icon: "",
    },
    brand: { url: "https://tienda.example" },
  },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const SUCCESS = {
  ok: true as const,
  sent: 100,
  received: 95,
  fee: 5,
  recipient_name: "Ana",
};

const KEY = "transfer-key-123";
const SCOPED = `loyalty-transfer:7:${KEY}`;

const request = (body: unknown, key: string | null = KEY) =>
  new Request("https://tienda.example/api/loyalty/transfer", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      ...(key === null ? {} : { "Idempotency-Key": key }),
    },
    body: JSON.stringify(body),
  });

const VALID = { code: "ABC12345", points: 100, note: "Gracias" };

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  state.site.loyalty.transfers_enabled = true;
  vi.mocked(guardMutation).mockResolvedValue(null);
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(reserveIdempotencyKey).mockResolvedValue({ status: "proceed" });
  vi.mocked(transferPoints).mockResolvedValue(SUCCESS);
});

describe("GET /api/loyalty/transfer · guardas", () => {
  it("pasa por guardMutation con rate-limit estricto", async () => {
    await POST(request(VALID));

    expect(guardMutation).toHaveBeenCalledWith(
      expect.any(Request),
      expect.objectContaining({
        rateLimit: expect.objectContaining({ strict: true }),
      }),
    );
  });

  it("si guardMutation bloquea, no se toca la sesión ni WordPress", async () => {
    vi.mocked(guardMutation).mockResolvedValue(
      new NextResponse(JSON.stringify({ error: "Demasiadas peticiones." }), {
        status: 429,
      }),
    );

    const res = await POST(request(VALID));

    expect(res.status).toBe(429);
    expect(getSession).not.toHaveBeenCalled();
    expect(transferPoints).not.toHaveBeenCalled();
  });

  it("con el módulo o las transferencias apagados responde 404 sin guardMutation", async () => {
    state.site.modules.loyalty = false;
    expect((await POST(request(VALID))).status).toBe(404);

    state.site.modules.loyalty = true;
    state.site.loyalty.points_enabled = false;
    expect((await POST(request(VALID))).status).toBe(404);

    state.site.loyalty.points_enabled = true;
    state.site.loyalty.transfers_enabled = false;
    expect((await POST(request(VALID))).status).toBe(404);

    expect(guardMutation).not.toHaveBeenCalled();
    expect(transferPoints).not.toHaveBeenCalled();
  });

  it("sin sesión responde 401 y no reserva la clave", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await POST(request(VALID));

    expect(res.status).toBe(401);
    expect(reserveIdempotencyKey).not.toHaveBeenCalled();
    expect(transferPoints).not.toHaveBeenCalled();
  });
});

describe("POST /api/loyalty/transfer · idempotencia", () => {
  it("exige una Idempotency-Key válida", async () => {
    const missing = await POST(request(VALID, null));
    const short = await POST(request(VALID, "abc"));

    expect(missing.status).toBe(400);
    expect(short.status).toBe(400);
    expect(reserveIdempotencyKey).not.toHaveBeenCalled();
    expect(transferPoints).not.toHaveBeenCalled();
  });

  it("acota la clave por usuario: dos usuarios no colisionan", async () => {
    await POST(request(VALID));
    vi.mocked(getSession).mockResolvedValue({ userId: "8", token: "JWT" });
    await POST(request(VALID));

    expect(vi.mocked(reserveIdempotencyKey).mock.calls.map(([key]) => key)).toEqual([
      `loyalty-transfer:7:${KEY}`,
      `loyalty-transfer:8:${KEY}`,
    ]);
  });

  it("reenvía a WordPress la clave sin acotar", async () => {
    await POST(request(VALID));

    expect(transferPoints).toHaveBeenCalledWith(
      7,
      { code: "ABC12345", points: 100, note: "Gracias" },
      KEY,
    );
  });

  it("repetir la clave devuelve el resultado guardado sin llamar a WordPress", async () => {
    vi.mocked(reserveIdempotencyKey)
      .mockResolvedValueOnce({ status: "proceed" })
      .mockResolvedValueOnce({ status: "replay", body: SUCCESS, statusCode: 200 });

    const first = await POST(request(VALID));
    const second = await POST(request(VALID));

    expect(await first.json()).toEqual(SUCCESS);
    expect(second.status).toBe(200);
    expect(await second.json()).toEqual(SUCCESS);
    expect(transferPoints).toHaveBeenCalledTimes(1);
  });

  it("una petición en curso con la misma clave responde 409", async () => {
    vi.mocked(reserveIdempotencyKey).mockResolvedValue({ status: "conflict" });

    const res = await POST(request(VALID));

    expect(res.status).toBe(409);
    expect(await res.json()).toEqual({ ok: false, error: "conflict" });
    expect(transferPoints).not.toHaveBeenCalled();
  });

  it("guarda el resultado final con la clave acotada", async () => {
    const res = await POST(request(VALID));

    expect(res.status).toBe(200);
    expect(storeIdempotentResult).toHaveBeenCalledWith(SCOPED, SUCCESS, 200);
  });
});

describe("POST /api/loyalty/transfer · cuerpo y resultado", () => {
  it("valida el cuerpo con Zod y no reserva si es inválido", async () => {
    const cases = [
      { code: "", points: 100 },
      { code: "ABC12345", points: 0 },
      { code: "ABC12345", points: -5 },
      { code: "ABC12345", points: 1.5 },
      { code: "ABC12345", points: "100" },
      { code: "ABC12345", points: 100, note: "x".repeat(201) },
    ];

    for (const body of cases) {
      const res = await POST(request(body));
      expect(res.status).toBe(422);
    }

    expect(reserveIdempotencyKey).not.toHaveBeenCalled();
    expect(transferPoints).not.toHaveBeenCalled();
  });

  it("ignora un user_id del cuerpo: manda el de la sesión", async () => {
    await POST(request({ ...VALID, user_id: 999 }));

    expect(transferPoints).toHaveBeenCalledWith(7, expect.any(Object), KEY);
    expect(transferPoints).not.toHaveBeenCalledWith(999, expect.any(Object), KEY);
  });

  it("un error de negocio es 422 con el código estable y se guarda", async () => {
    vi.mocked(transferPoints).mockResolvedValue({
      ok: false,
      error: "insufficient",
    });

    const res = await POST(request(VALID));

    expect(res.status).toBe(422);
    expect(await res.json()).toEqual({ ok: false, error: "insufficient" });
    expect(storeIdempotentResult).toHaveBeenCalledWith(
      SCOPED,
      { ok: false, error: "insufficient" },
      422,
    );
  });

  it("un fallo de WordPress es 502 y libera la clave para reintentar", async () => {
    vi.mocked(transferPoints).mockResolvedValue({ ok: false, error: "failed" });

    const res = await POST(request(VALID));

    expect(res.status).toBe(502);
    expect(await res.json()).toEqual({ ok: false, error: "failed" });
    expect(releaseIdempotencyKey).toHaveBeenCalledWith(SCOPED);
    expect(storeIdempotentResult).not.toHaveBeenCalled();
  });

  it("una transferencia deshabilitada entre la guarda y WordPress es 404", async () => {
    vi.mocked(transferPoints).mockResolvedValue({ ok: false, error: "disabled" });

    const res = await POST(request(VALID));

    expect(res.status).toBe(404);
    expect(releaseIdempotencyKey).toHaveBeenCalledWith(SCOPED);
  });

  it("la respuesta lleva Cache-Control: no-store", async () => {
    const res = await POST(request(VALID));

    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });
});
