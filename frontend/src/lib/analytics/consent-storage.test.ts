import { describe, expect, it } from "vitest";
import {
  CONSENT_GENERATION,
  CONSENT_KEY,
  MAX_PENDING,
  acknowledgePending,
  adoptLegacyScope,
  consentCovers,
  discardObsoleteConsent,
  effectiveConsent,
  hasNewerConsent,
  parseStoredConsent,
  readStoredConsent,
  storeDecision,
  type PendingConsentRecord,
  type StoredConsent,
} from "./consent-storage";

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

const record = (over: Partial<PendingConsentRecord> = {}): PendingConsentRecord => ({
  decision: "accepted",
  action: "first",
  path: "/",
  variant: "bar",
  locale: "es",
  clientAt: "2026-09-25T20:00:00.000Z",
  ...over,
});

describe("parseStoredConsent", () => {
  it("sin nada guardado, sin decisión", () => {
    expect(parseStoredConsent(null)).toBeNull();
  });

  it("las cadenas sueltas de antes del registro no valen, ni siquiera el rechazo", () => {
    expect(parseStoredConsent("declined")).toBeNull();
    expect(parseStoredConsent("accepted")).toBeNull();
  });

  it("una decisión de otra generación no vale: se vuelve a preguntar", () => {
    const old = {
      v: CONSENT_GENERATION - 1,
      status: "accepted",
      receipt: null,
      pending: [],
    };
    expect(parseStoredConsent(JSON.stringify(old))).toBeNull();
  });

  it("JSON corrupto o con otra forma: sin decisión", () => {
    expect(parseStoredConsent("{")).toBeNull();
    expect(
      parseStoredConsent(JSON.stringify({ v: CONSENT_GENERATION, status: "yes" })),
    ).toBeNull();
  });

  it("descarta de la cola lo que no es un registro válido", () => {
    const raw = JSON.stringify({
      v: CONSENT_GENERATION,
      status: "accepted",
      receipt: null,
      pending: [record(), { decision: "x" }],
    });
    expect(parseStoredConsent(raw)?.pending).toEqual([record()]);
  });
});

describe("effectiveConsent (alcance)", () => {
  const stored = (over: Partial<StoredConsent> = {}): StoredConsent => ({
    v: CONSENT_GENERATION,
    status: "accepted",
    services: ["ga4", "meta"],
    receipt: null,
    pending: [],
    ...over,
  });

  it("sin decisión vigente, sin respuesta", () => {
    expect(effectiveConsent(null, ["ga4"])).toBe("unknown");
  });

  it("un «Aceptar» cubre lo que nombraba, y también si se quitó algo", () => {
    expect(effectiveConsent(stored(), ["ga4", "meta"])).toBe("accepted");
    expect(effectiveConsent(stored(), ["meta"])).toBe("accepted");
  });

  it("un servicio añadido después deja el «Aceptar» sin valor: se repregunta", () => {
    expect(effectiveConsent(stored(), ["ga4", "meta", "hubspot"])).toBe("unknown");
    expect(consentCovers(stored(), ["tiktok"])).toBe(false);
  });

  it("un rechazo sigue valiendo: no se carga nada que repreguntar", () => {
    expect(effectiveConsent(stored({ status: "declined" }), ["hubspot"])).toBe(
      "declined",
    );
  });

  it("una decisión anterior al campo cubre lo configurado y lo adopta", () => {
    const storage = memoryStorage();
    storage.setItem(CONSENT_KEY, JSON.stringify(stored({ services: null })));
    expect(effectiveConsent(readStoredConsent(storage), ["ga4", "hubspot"])).toBe(
      "accepted",
    );
    expect(adoptLegacyScope(storage, ["ga4", "hubspot"])).toBe(true);
    expect(readStoredConsent(storage)?.services).toEqual(["ga4", "hubspot"]);
    expect(adoptLegacyScope(storage, ["ga4", "hubspot", "meta"])).toBe(false);
    expect(
      effectiveConsent(readStoredConsent(storage), ["ga4", "hubspot", "meta"]),
    ).toBe("unknown");
  });

  it("una lista guardada con nombres desconocidos se lee como anterior al campo", () => {
    expect(
      parseStoredConsent(JSON.stringify(stored({ services: ["x"] as never })))
        ?.services,
    ).toBeNull();
  });
});

describe("discardObsoleteConsent", () => {
  it("un bundle antiguo NO borra una decisión de una generación más nueva", () => {
    // La página la sirvió una copia vieja del documento (caché del navegador):
    // su bundle no entiende la decisión, pero tampoco es quién para retirarla.
    const storage = memoryStorage();
    const raw = JSON.stringify({
      v: CONSENT_GENERATION + 1,
      status: "accepted",
      services: ["ga4"],
      receipt: null,
      pending: [],
    });
    storage.setItem(CONSENT_KEY, raw);
    expect(hasNewerConsent(storage)).toBe(true);
    expect(discardObsoleteConsent(storage)).toBe(false);
    expect(storage.getItem(CONSENT_KEY)).toBe(raw);
    // No la entiende: sin rastreadores. Y el banner no pregunta (lo decide quien pinta).
    expect(effectiveConsent(readStoredConsent(storage), ["ga4"])).toBe("unknown");
  });

  it("hasNewerConsent es falso sin nada guardado, con la vigente o con una anterior", () => {
    const storage = memoryStorage();
    expect(hasNewerConsent(storage)).toBe(false);
    storeDecision(storage, record());
    expect(hasNewerConsent(storage)).toBe(false);
    storage.setItem(CONSENT_KEY, JSON.stringify({ v: CONSENT_GENERATION - 1 }));
    expect(hasNewerConsent(storage)).toBe(false);
    storage.setItem(CONSENT_KEY, "accepted");
    expect(hasNewerConsent(storage)).toBe(false);
  });

  it("retira una decisión que ya no vale y lo dice", () => {
    const storage = memoryStorage();
    storage.setItem(CONSENT_KEY, "accepted");
    expect(discardObsoleteConsent(storage)).toBe(true);
    expect(storage.getItem(CONSENT_KEY)).toBeNull();
  });

  it("lo que la decisión antigua no había registrado sigue en cola, con su generación", () => {
    const storage = memoryStorage();
    const receipt = "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c";
    storage.setItem(
      CONSENT_KEY,
      JSON.stringify({
        v: CONSENT_GENERATION - 1,
        status: "declined",
        receipt,
        pending: [record({ decision: "declined", action: "change" })],
      }),
    );
    expect(discardObsoleteConsent(storage)).toBe(true);
    const stored = readStoredConsent(storage)!;
    expect(stored.status).toBeNull();
    expect(stored.receipt).toBe(receipt);
    expect(stored.pending).toEqual([
      record({
        decision: "declined",
        action: "change",
        generation: CONSENT_GENERATION - 1,
      }),
    ]);
    // Sin decisión vigente: el banner pregunta de nuevo.
    expect(effectiveConsent(stored, ["ga4"])).toBe("unknown");
    // Registrado lo último que quedaba, el almacén se vacía.
    acknowledgePending(storage, stored.pending[0]!, receipt);
    expect(storage.getItem(CONSENT_KEY)).toBeNull();
  });

  it("una decisión nueva se encola DETRÁS de lo antiguo por enviar", () => {
    const storage = memoryStorage();
    storage.setItem(
      CONSENT_KEY,
      JSON.stringify({
        v: CONSENT_GENERATION - 1,
        status: "accepted",
        receipt: null,
        pending: [record()],
      }),
    );
    discardObsoleteConsent(storage);
    storeDecision(
      storage,
      record({ clientAt: "2026-09-28T20:00:00.000Z", generation: CONSENT_GENERATION }),
    );
    expect(readStoredConsent(storage)!.pending.map((p) => p.generation)).toEqual([
      CONSENT_GENERATION - 1,
      CONSENT_GENERATION,
    ]);
  });

  it("no toca una decisión vigente ni un almacén vacío", () => {
    const storage = memoryStorage();
    expect(discardObsoleteConsent(storage)).toBe(false);
    storeDecision(storage, record());
    expect(discardObsoleteConsent(storage)).toBe(false);
    expect(readStoredConsent(storage)?.status).toBe("accepted");
  });
});

describe("storeDecision / acknowledgePending", () => {
  it("guarda la decisión y la deja en cola hasta que el servidor la registra", () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    const stored = readStoredConsent(storage);
    expect(stored?.status).toBe("accepted");
    expect(stored?.pending).toEqual([record()]);

    acknowledgePending(storage, record(), "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c");
    expect(readStoredConsent(storage)).toEqual({
      v: CONSENT_GENERATION,
      status: "accepted",
      services: null,
      receipt: "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c",
      pending: [],
    });
  });

  it("un cambio antes de sincronizar se ENCOLA: la aceptación intermedia también se prueba", () => {
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
    const stored = readStoredConsent(storage)!;
    expect(stored.status).toBe("declined");
    expect(stored.pending.map((p) => p.decision)).toEqual(["accepted", "declined"]);
  });

  it("la cola tiene tope: se queda con las más recientes", () => {
    const storage = memoryStorage();
    for (let i = 0; i < MAX_PENDING + 3; i += 1) {
      storeDecision(storage, record({ clientAt: new Date(i * 1000).toISOString() }));
    }
    const pending = readStoredConsent(storage)!.pending;
    expect(pending).toHaveLength(MAX_PENDING);
    expect(pending.at(-1)?.clientAt).toBe(
      new Date((MAX_PENDING + 2) * 1000).toISOString(),
    );
  });

  it("conserva el comprobante ya recibido al tomar una decisión nueva", () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    acknowledgePending(storage, record(), "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c");
    storeDecision(storage, record({ decision: "declined", action: "change" }));
    expect(readStoredConsent(storage)?.receipt).toBe(
      "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c",
    );
  });

  it("guarda los servicios que nombraba el banner", () => {
    const storage = memoryStorage();
    storeDecision(storage, record({ services: ["ga4", "meta"] }));
    expect(readStoredConsent(storage)?.services).toEqual(["ga4", "meta"]);
  });

  it("escribe JSON en la clave de siempre", () => {
    const storage = memoryStorage();
    storeDecision(storage, record());
    expect(JSON.parse(storage.getItem(CONSENT_KEY)!).status).toBe("accepted");
  });
});
