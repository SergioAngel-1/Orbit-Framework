import { describe, expect, it, vi } from "vitest";
import {
  readStoredConsent,
  storeDecision,
  type PendingConsentRecord,
} from "./consent-storage";
import { syncPendingConsent } from "./consent-sync";

function memoryStorage(): Storage {
  const data = new Map<string, string>();
  return {
    get length() {
      return data.size;
    },
    clear: () => data.clear(),
    getItem: (k) => data.get(k) ?? null,
    key: (i) => [...data.keys()][i] ?? null,
    removeItem: (k) => void data.delete(k),
    setItem: (k, v) => void data.set(k, String(v)),
  };
}

const RECEIPT = "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c";
const record = (over: Partial<PendingConsentRecord> = {}): PendingConsentRecord => ({
  decision: "accepted",
  action: "first",
  path: "/",
  variant: "bar",
  locale: "es",
  clientAt: "2026-09-25T20:00:00.000Z",
  ...over,
});

const ok = () =>
  new Response(JSON.stringify({ receipt: RECEIPT }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });

describe("syncPendingConsent", () => {
  it("envía la cola EN ORDEN y guarda el comprobante", async () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    storeDecision(
      storage,
      record({
        decision: "declined",
        action: "change",
        clientAt: "2026-09-25T20:01:00.000Z",
      }),
    );
    const fetchImpl = vi.fn(async () => ok());
    const onChange = vi.fn();

    await syncPendingConsent(storage, fetchImpl, onChange);

    const bodies = fetchImpl.mock.calls.map(
      (call) =>
        JSON.parse((call as unknown as [string, RequestInit])[1].body as string)
          .decision,
    );
    expect(bodies).toEqual(["accepted", "declined"]);
    expect(readStoredConsent(storage)).toMatchObject({ receipt: RECEIPT, pending: [] });
    expect(onChange).toHaveBeenCalled();
  });

  it("si el servidor falla, se detiene y conserva lo pendiente para la próxima carga", async () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    storeDecision(
      storage,
      record({
        decision: "declined",
        action: "change",
        clientAt: "2026-09-25T20:01:00.000Z",
      }),
    );
    const fetchImpl = vi.fn(async () => new Response(null, { status: 503 }));

    await syncPendingConsent(storage, fetchImpl, () => {});

    expect(fetchImpl).toHaveBeenCalledOnce();
    expect(readStoredConsent(storage)?.pending).toHaveLength(2);
  });

  it("un rechazo permanente (4xx) se descarta y no bloquea lo siguiente", async () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    storeDecision(
      storage,
      record({
        decision: "declined",
        action: "change",
        clientAt: "2026-09-25T20:01:00.000Z",
      }),
    );
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(new Response(null, { status: 409 }))
      .mockResolvedValueOnce(ok());
    await syncPendingConsent(storage, fetchImpl, () => {});
    expect(fetchImpl).toHaveBeenCalledTimes(2);
    expect(readStoredConsent(storage)).toMatchObject({ receipt: RECEIPT, pending: [] });
  });

  it.each([429, 403])(
    "%i (rate-limit u Origin) no es permanente: se conserva para la próxima carga",
    async (status) => {
      const storage = memoryStorage();
      storeDecision(storage, record());
      const fetchImpl = vi.fn(async () => new Response(null, { status }));
      await syncPendingConsent(storage, fetchImpl, () => {});
      expect(readStoredConsent(storage)?.pending).toHaveLength(1);
    },
  );

  it("un error de red tampoco pierde nada", async () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    const fetchImpl = vi.fn(async () => {
      throw new TypeError("offline");
    });
    await syncPendingConsent(storage, fetchImpl, () => {});
    expect(readStoredConsent(storage)?.pending).toHaveLength(1);
  });

  it("una respuesta sin comprobante válido no confirma el registro", async () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    const fetchImpl = vi.fn(
      async () => new Response(JSON.stringify({}), { status: 200 }),
    );
    await syncPendingConsent(storage, fetchImpl, () => {});
    expect(readStoredConsent(storage)?.pending).toHaveLength(1);
  });

  it("dos llamadas simultáneas no envían el mismo registro dos veces", async () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    const fetchImpl = vi.fn(async () => ok());
    await Promise.all([
      syncPendingConsent(storage, fetchImpl, () => {}),
      syncPendingConsent(storage, fetchImpl, () => {}),
    ]);
    expect(fetchImpl).toHaveBeenCalledOnce();
  });

  it("sin nada pendiente no hace ninguna petición", async () => {
    const storage = memoryStorage();
    const fetchImpl = vi.fn(async () => ok());
    await syncPendingConsent(storage, fetchImpl, () => {});
    expect(fetchImpl).not.toHaveBeenCalled();
  });
});
