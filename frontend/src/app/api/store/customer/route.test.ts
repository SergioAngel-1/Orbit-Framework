import { beforeEach, describe, expect, it, vi } from "vitest";
import { wcFetch } from "@/lib/woocommerce/client";
import { getGeoPack } from "@/lib/geo";
import { CO_PACK } from "../../../../../tests/helpers/geo-pack";
import { getSiteConfig } from "@/lib/config";
import { attachIdentity } from "@/lib/customer/identity-api";
import { PUT } from "./route";

// El perfil del cliente (billing/shipping) pasa por el paquete de país igual que
// el checkout y la libreta: el departamento sale como código o la petición falla.

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({
  requireSession: vi.fn(async () => ({ token: "t", userId: "7" })),
}));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));
vi.mock("@/lib/geo", () => ({ getGeoPack: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/customer/identity-api", () => ({ attachIdentity: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function put(body: unknown) {
  return new Request("https://tienda.example/api/store/customer", {
    method: "PUT",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "customer-identity": true },
  } as never);
  vi.mocked(attachIdentity).mockResolvedValue({
    ok: true,
    identity: {
      documentType: "CC",
      documentNumber: "1012345678",
      termsAcceptedAt: null,
    },
  });
  vi.mocked(wcFetch).mockResolvedValue({ id: 7 } as never);
});

describe("PUT /api/store/customer", () => {
  it("normaliza el departamento de la facturación antes de escribir en WooCommerce", async () => {
    const res = put({
      first_name: "Ana",
      billing: { city: "Pasto", country: "CO", state: "Nariño" },
    });
    expect((await PUT(res)).status).toBe(200);

    const sent = vi.mocked(wcFetch).mock.calls[0][1] as {
      body: Record<string, unknown>;
    };
    expect(sent.body).toMatchObject({
      first_name: "Ana",
      billing: { city: "Pasto", state: "CO-NAR" },
    });
  });

  it("un departamento inválido es 422 por campo y no escribe", async () => {
    const res = await PUT(
      put({ shipping: { city: "Pasto", country: "CO", state: "Atlantis" } }),
    );

    expect(res.status).toBe(422);
    expect((await res.json()).details).toEqual({
      "shipping.state": ["Departamento no válido"],
    });
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("sin direcciones en el cuerpo no toca el paquete", async () => {
    const res = await PUT(put({ first_name: "Ana" }));

    expect(res.status).toBe(200);
    expect(vi.mocked(wcFetch).mock.calls[0][1]).toMatchObject({
      body: { first_name: "Ana" },
    });
  });
});

describe("PUT /api/store/customer — documento (módulo customer-identity)", () => {
  const DOC = { documentType: "CC", documentNumber: "1.012.345.678" };

  it("registra el documento normalizado y no lo reenvía a WooCommerce", async () => {
    const res = await PUT(put({ first_name: "Ana", identity: DOC }));

    expect(res.status).toBe(200);
    expect(attachIdentity).toHaveBeenCalledWith(7, {
      documentType: "CC",
      documentNumber: "1012345678",
    });
    expect(vi.mocked(wcFetch).mock.calls[0][1]).toMatchObject({
      body: { first_name: "Ana" },
    });
    expect(JSON.stringify(vi.mocked(wcFetch).mock.calls[0][1])).not.toContain(
      "identity",
    );
  });

  it("el documento va primero: si se rechaza, no se toca nada más del perfil", async () => {
    vi.mocked(attachIdentity).mockResolvedValue({
      ok: false,
      reason: "document_taken",
    });

    const res = await PUT(put({ first_name: "Ana", identity: DOC }));

    expect(res.status).toBe(409);
    expect((await res.json()).details.identity).toEqual([
      "Este documento ya está registrado.",
    ]);
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("uno que la cuenta ya no puede cambiar es un 409 distinto", async () => {
    vi.mocked(attachIdentity).mockResolvedValue({
      ok: false,
      reason: "document_locked",
    });

    const res = await PUT(put({ identity: DOC }));

    expect(res.status).toBe(409);
    expect((await res.json()).error).toMatch(/ya no se puede cambiar/);
  });

  it("si WordPress no responde es un 502 y no se guarda nada", async () => {
    vi.mocked(attachIdentity).mockResolvedValue({ ok: false, reason: "unavailable" });

    expect((await PUT(put({ identity: DOC }))).status).toBe(502);
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("un documento que no cuadra con el paquete, o con el módulo apagado, es un 422", async () => {
    const bad = await PUT(
      put({ identity: { documentType: "CC", documentNumber: "12" } }),
    );
    expect(bad.status).toBe(422);

    vi.mocked(getSiteConfig).mockResolvedValue({ modules: {} } as never);
    const off = await PUT(put({ identity: DOC }));
    expect(off.status).toBe(422);
    expect(attachIdentity).not.toHaveBeenCalled();
  });
});
