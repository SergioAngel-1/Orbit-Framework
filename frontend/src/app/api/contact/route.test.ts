import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { POST } from "./route";

// ============================================================================
//  POST /api/contact: reenvía a WordPress (`hwe/v1/contact`) con el secreto
//  interno y, con sesión, con la identidad de la cuenta, no la del cuerpo.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));

const VALID = {
  name: "Visitante",
  email: "visitante@example.com",
  requestType: "otro",
  description: "Hola, una consulta.",
  acceptedTerms: true,
};

const call = (body: unknown) =>
  POST(
    new Request("https://tienda.example/api/contact", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
  );

const fetchMock = vi.fn();

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal("fetch", fetchMock);
  process.env.HWE_REVALIDATION_SECRET = "secreto-interno-de-pruebas-0123456789";
  fetchMock.mockResolvedValue(Response.json({ ok: true }));
  vi.mocked(getSession).mockResolvedValue(null);
});

const sentBody = () => JSON.parse(String(fetchMock.mock.calls[0][1].body));

describe("POST /api/contact", () => {
  it("reenvía a WordPress con el secreto interno", async () => {
    const res = await call(VALID);

    expect(res.status).toBe(200);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/\/wp-json\/hwe\/v1\/contact$/);
    expect(init.headers["X-HWE-Internal-Secret"]).toBe(
      process.env.HWE_REVALIDATION_SECRET,
    );
    expect(sentBody()).toEqual({
      name: "Visitante",
      email: "visitante@example.com",
      phone: "",
      requestType: "otro",
      description: "Hola, una consulta.",
    });
  });

  it("con sesión usa el nombre, el email y el teléfono de la cuenta", async () => {
    vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" } as never);
    vi.mocked(wcFetch).mockResolvedValue({
      first_name: "Ana",
      last_name: "Pérez",
      email: "ana@example.com",
      billing: { phone: "600111222" },
    } as never);

    await call({ ...VALID, name: "Suplantado", email: "victima@example.com" });

    expect(sentBody()).toMatchObject({
      name: "Ana Pérez",
      email: "ana@example.com",
      phone: "600111222",
    });
  });

  it("valida el cuerpo y no llama a WordPress si es inválido", async () => {
    const res = await call({ ...VALID, acceptedTerms: false });

    expect(res.status).toBe(422);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("si WordPress falla responde 502, no 200", async () => {
    fetchMock.mockResolvedValue(new Response("x", { status: 502 }));

    expect((await call(VALID)).status).toBe(502);
  });
});
