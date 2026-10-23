import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logReadFailure } from "@/lib/observability/read-failure";
import { logger } from "@/lib/observability/logger";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import {
  attributeReferral,
  getLoyaltyReferrals,
  getLoyaltySummary,
  getLoyaltyTransactions,
  getReferralLink,
  LoyaltyReadError,
  transferPoints,
  validateReferralCode,
} from "./server";

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

const readInit = { cache: "no-store" as const };

const brandConfig = {
  brand: { ...CONFIG_DEFAULTS.brand, url: "https://shop.example/" },
};

const SUMMARY = {
  participant: true,
  balance: 320,
  earned: 500,
  used: 180,
  expiring: { points: 50, date: "2026-11-20T00:00:00Z" },
};

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
  vi.mocked(logReadFailure).mockReset();
  vi.mocked(logger.error).mockReset();
});

describe("getLoyaltySummary", () => {
  it("lee el resumen con el `user_id` del servidor y sin caché", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json(SUMMARY));

    await expect(getLoyaltySummary(7)).resolves.toEqual(SUMMARY);
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/loyalty/summary?user_id=7",
      readInit,
    );
  });

  it("un 404 de módulo apagado se distingue de un fallo", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ code: "rest_no_route" }, 404));

    await expect(getLoyaltySummary(7)).rejects.toMatchObject({
      name: "LoyaltyReadError",
      code: "not_found",
      status: 404,
    });
  });

  it("un fallo de WordPress registra `logReadFailure` y lanza un error tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ error: "boom" }, 503));

    const failure = await getLoyaltySummary(7).catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(LoyaltyReadError);
    expect(failure).toMatchObject({ code: "unavailable", status: 503 });
    expect(logReadFailure).toHaveBeenCalledWith(
      "loyalty.summary_failed",
      "HTTP 503",
      expect.objectContaining({ level: "error", context: { userId: 7 } }),
    );
  });

  it("una respuesta fuera de contrato también es un error tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ participant: "sí" }));

    await expect(getLoyaltySummary(7)).rejects.toMatchObject({
      code: "invalid_response",
    });
    expect(logReadFailure).toHaveBeenCalledWith(
      "loyalty.summary_failed",
      "respuesta fuera de contrato",
      expect.objectContaining({ level: "error" }),
    );
  });

  it("un fallo de red es `unavailable` y nunca un saldo inventado", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("ECONNREFUSED"));

    const failure = await getLoyaltySummary(7).catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(LoyaltyReadError);
    expect(failure).toMatchObject({ code: "unavailable" });
    expect(logReadFailure).toHaveBeenCalled();
  });

  it("un cuerpo que no es JSON también es un error tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(new Response("no-es-json"));

    await expect(getLoyaltySummary(7)).rejects.toMatchObject({
      code: "invalid_response",
    });
    expect(logReadFailure).toHaveBeenCalledWith(
      "loyalty.summary_failed",
      expect.any(Error),
      expect.objectContaining({ level: "error" }),
    );
  });

  it("rechaza un id que no puede venir de una sesión", async () => {
    await expect(getLoyaltySummary(0)).rejects.toMatchObject({
      code: "invalid_request",
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });
});

describe("getLoyaltyTransactions", () => {
  it("pagina y normaliza los parámetros", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ items: [], total: 0, pages: 0 }));

    await getLoyaltyTransactions(9);

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/loyalty/transactions?user_id=9&page=1&per_page=20",
      readInit,
    );
  });

  it("recorta `per_page` y la página mínima", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ items: [], total: 0, pages: 0 }));

    await getLoyaltyTransactions(9, { page: 0, perPage: 500 });

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/loyalty/transactions?user_id=9&page=1&per_page=100",
      readInit,
    );
  });

  it("devuelve los movimientos y tolera `data` vacía como lista de PHP", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({
        items: [
          {
            id: 3,
            type: "cashback",
            points: 12,
            data: [],
            created_at: "2026-10-22T10:00:00Z",
            expires_at: null,
          },
          {
            id: 2,
            type: "transfer_out",
            points: -100,
            data: { counterpart_name: "Ana", fee: 5 },
            created_at: "2026-10-21T10:00:00Z",
            expires_at: "2027-01-01T00:00:00Z",
          },
        ],
        total: 2,
        pages: 1,
      }),
    );

    await expect(getLoyaltyTransactions(9, { page: 1, perPage: 50 })).resolves.toEqual({
      items: [
        {
          id: 3,
          type: "cashback",
          points: 12,
          data: {},
          created_at: "2026-10-22T10:00:00Z",
          expires_at: null,
        },
        {
          id: 2,
          type: "transfer_out",
          points: -100,
          data: { counterpart_name: "Ana", fee: 5 },
          created_at: "2026-10-21T10:00:00Z",
          expires_at: "2027-01-01T00:00:00Z",
        },
      ],
      total: 2,
      pages: 1,
    });
  });

  it("una página malformada no se sirve como vacía: lanza error tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ items: [{ id: "3" }] }));

    await expect(getLoyaltyTransactions(9)).rejects.toMatchObject({
      code: "invalid_response",
    });
    expect(logReadFailure).toHaveBeenCalledWith(
      "loyalty.transactions_failed",
      "respuesta fuera de contrato",
      expect.anything(),
    );
  });
});

describe("getLoyaltyReferrals", () => {
  const page = {
    items: [
      {
        name: "Ana",
        date: "2026-10-01T10:00:00Z",
        status: "confirmed",
        points_generated: 30,
      },
    ],
    total: 1,
    pages: 1,
    totals: { level1: 1, level2: 0, pending: 0, earned: 30 },
  };

  it.each([1, 2] as const)("lee el nivel %s paginado", async (level) => {
    vi.mocked(wpFetch).mockResolvedValue(json(page));

    await expect(getLoyaltyReferrals(9, { level })).resolves.toEqual(page);
    expect(wpFetch).toHaveBeenCalledWith(
      `/wp-json/hwe/v1/loyalty/referrals?user_id=9&level=${level}&page=1&per_page=20`,
      readInit,
    );
  });

  it("rechaza un nivel fuera de contrato sin llamar a WordPress", async () => {
    await expect(
      getLoyaltyReferrals(9, { level: 3 as unknown as 1 }),
    ).rejects.toMatchObject({ code: "invalid_request" });
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("un fallo registra el evento de la red", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));

    await expect(getLoyaltyReferrals(9, { level: 1 })).rejects.toMatchObject({
      code: "unavailable",
    });
    expect(logReadFailure).toHaveBeenCalledWith(
      "loyalty.referrals_failed",
      expect.any(Error),
      expect.objectContaining({ context: { userId: 9 } }),
    );
  });
});

describe("getReferralLink", () => {
  it("compone la URL con `config.brand.url`, no con WordPress", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ code: "ABC12345" }));

    await expect(getReferralLink(7, brandConfig)).resolves.toEqual({
      code: "ABC12345",
      url: "https://shop.example/?ref=ABC12345",
    });
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/loyalty/referral-code?user_id=7",
      readInit,
    );
  });

  it("sin código no inventa un enlace", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ code: "" }));

    await expect(getReferralLink(7, brandConfig)).resolves.toEqual({
      code: "",
      url: null,
    });
  });
});

describe("validateReferralCode", () => {
  it("devuelve solo la validez", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ valid: true }));

    await expect(validateReferralCode(" ABC123 ")).resolves.toEqual({ valid: true });
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/loyalty/validate-code?code=ABC123",
      readInit,
    );
  });

  it("un código vacío no llega a WordPress", async () => {
    await expect(validateReferralCode("  ")).resolves.toEqual({ valid: false });
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("un fallo lanza error tipado en vez de decir que el código no vale", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({}, 500));

    await expect(validateReferralCode("ABC")).rejects.toMatchObject({
      code: "unavailable",
    });
    expect(logReadFailure).toHaveBeenCalledWith(
      "loyalty.validate_code_failed",
      "HTTP 500",
      expect.anything(),
    );
  });
});

describe("attributeReferral", () => {
  it("envía el `user_id` de la sesión y devuelve la atribución", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ result: "attributed" }));

    await expect(attributeReferral(7, "ABC123")).resolves.toEqual({ ok: true });
    expect(wpFetch).toHaveBeenCalledWith("/wp-json/hwe/v1/loyalty/attribute", {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ user_id: 7, code: "ABC123" }),
    });
  });

  it.each([
    "disabled",
    "invalid_code",
    "self",
    "not_participant",
    "cycle",
    "already_referred",
    "storage",
  ])("mapea el motivo estable `%s`", async (reason) => {
    vi.mocked(wpFetch).mockResolvedValue(json({ result: `ignored:${reason}` }));

    await expect(attributeReferral(7, "ABC123")).resolves.toEqual({
      ok: false,
      reason,
    });
  });

  it("un código vacío no se envía", async () => {
    await expect(attributeReferral(7, "   ")).resolves.toEqual({
      ok: false,
      reason: "invalid_code",
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("un fallo de transporte no rompe el registro: devuelve `unavailable` y lo registra", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));

    await expect(attributeReferral(7, "ABC")).resolves.toEqual({
      ok: false,
      reason: "unavailable",
    });
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "loyalty.attribute_failed" }),
      expect.any(String),
    );
  });

  it("una respuesta desconocida se trata como no disponible", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ result: "algo-raro" }));

    await expect(attributeReferral(7, "ABC")).resolves.toEqual({
      ok: false,
      reason: "unavailable",
    });
  });

  it("una respuesta fuera de contrato se registra y no rompe el registro", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ result: 42 }, 500));

    await expect(attributeReferral(7, "ABC")).resolves.toEqual({
      ok: false,
      reason: "unavailable",
    });
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "loyalty.attribute_failed",
        reason: "invalid_response",
      }),
      expect.any(String),
    );
  });
});

describe("transferPoints", () => {
  const input = { code: "ABC123", points: 100 };

  it("envía la clave de idempotencia y el `user_id` de la sesión", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({ ok: true, sent: 100, received: 95, fee: 5, recipient_name: "Ana" }),
    );

    await expect(
      transferPoints(7, { ...input, note: "Gracias" }, "idem-1"),
    ).resolves.toEqual({
      ok: true,
      sent: 100,
      received: 95,
      fee: 5,
      recipient_name: "Ana",
    });
    expect(wpFetch).toHaveBeenCalledWith("/wp-json/hwe/v1/loyalty/transfer", {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        user_id: 7,
        code: "ABC123",
        points: 100,
        idempotency_key: "idem-1",
        note: "Gracias",
      }),
    });
  });

  it("omite la nota si no viene", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({ ok: true, sent: 100, received: 100, fee: 0, recipient_name: "Ana" }),
    );

    await transferPoints(7, input, "idem-1");

    const init = vi.mocked(wpFetch).mock.calls[0][1] as RequestInit;
    expect(JSON.parse(String(init.body))).toEqual({
      user_id: 7,
      code: "ABC123",
      points: 100,
      idempotency_key: "idem-1",
    });
  });

  it.each([
    "disabled",
    "invalid_request",
    "conflict",
    "below_minimum",
    "insufficient",
    "invalid_recipient",
    "self",
    "daily_cap",
    "failed",
  ])("devuelve el código estable `%s` sin lanzar", async (error) => {
    vi.mocked(wpFetch).mockResolvedValue(json({ error }, 422));

    await expect(transferPoints(7, input, "idem-1")).resolves.toEqual({
      ok: false,
      error,
    });
  });

  it("un código desconocido se normaliza a `failed`", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ error: "otro" }, 500));

    await expect(transferPoints(7, input, "idem-1")).resolves.toEqual({
      ok: false,
      error: "failed",
    });
  });

  it("un fallo de transporte devuelve `failed` y lo registra sin PII", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));

    await expect(transferPoints(7, input, "idem-1")).resolves.toEqual({
      ok: false,
      error: "failed",
    });
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "loyalty.transfer_failed" }),
      expect.any(String),
    );
  });

  it("una respuesta de éxito malformada no se da por buena", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ ok: true, sent: "100" }));

    await expect(transferPoints(7, input, "idem-1")).resolves.toEqual({
      ok: false,
      error: "failed",
    });
  });

  it("una entrada inválida no llega a WordPress", async () => {
    await expect(
      transferPoints(7, { code: "", points: 100 }, "idem-1"),
    ).resolves.toEqual({ ok: false, error: "invalid_request" });
    await expect(
      transferPoints(7, { code: "ABC", points: 0 }, "idem-1"),
    ).resolves.toEqual({ ok: false, error: "invalid_request" });
    await expect(transferPoints(7, input, "  ")).resolves.toEqual({
      ok: false,
      error: "invalid_request",
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });
});
