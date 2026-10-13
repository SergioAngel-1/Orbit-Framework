import { describe, expect, it } from "vitest";
import {
  CONSENT_STORAGE_KEY,
  clearStoredConsent,
  readStoredConsent,
  storeConsent,
} from "./consent-storage";

function memory(initial: Record<string, string> = {}) {
  const data = new Map(Object.entries(initial));
  return {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
    removeItem: (k: string) => void data.delete(k),
  };
}

const throwing = {
  getItem: () => {
    throw new Error("SecurityError");
  },
  setItem: () => {
    throw new Error("QuotaExceededError");
  },
  removeItem: () => {
    throw new Error("SecurityError");
  },
};

describe("consentimiento guardado", () => {
  it("lee las decisiones válidas, en el formato de la v1", () => {
    expect(readStoredConsent(memory({ [CONSENT_STORAGE_KEY]: "accepted" }))).toEqual({
      status: "accepted",
    });
    expect(readStoredConsent(memory({ [CONSENT_STORAGE_KEY]: "declined" }))).toEqual({
      status: "declined",
    });
  });

  it("sin decisión o con basura, «sin respuesta»", () => {
    expect(readStoredConsent(memory())).toBeNull();
    expect(readStoredConsent(memory({ [CONSENT_STORAGE_KEY]: "quizas" }))).toBeNull();
  });

  it("guardar y retirar", () => {
    const storage = memory();

    expect(storeConsent(storage, "accepted")).toBe(true);
    expect(readStoredConsent(storage)?.status).toBe("accepted");
    clearStoredConsent(storage);
    expect(readStoredConsent(storage)).toBeNull();
  });

  it("sin almacenamiento no lanza", () => {
    expect(readStoredConsent(throwing)).toBeNull();
    expect(storeConsent(throwing, "accepted")).toBe(false);
    expect(() => clearStoredConsent(throwing)).not.toThrow();
  });
});
