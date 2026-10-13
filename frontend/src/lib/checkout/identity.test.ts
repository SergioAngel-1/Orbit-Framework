import { describe, expect, it } from "vitest";
import { CO_PACK, FALLBACK_ES_PACK } from "../../../tests/helpers/geo-pack";
import type { CustomerIdentity } from "@/lib/customer/identity";
import type { IdentityPolicy } from "@/lib/customer/policy";
import { resolveCheckoutIdentity } from "./identity";

const WITH_DOC: IdentityPolicy = {
  enabled: true,
  documentTypes: CO_PACK.documentTypes,
};
const TERMS_ONLY: IdentityPolicy = {
  enabled: true,
  documentTypes: FALLBACK_ES_PACK.documentTypes,
};
const NOW = new Date("2026-10-13T10:00:00.000Z");
const DOC = { documentType: "CC", documentNumber: "1012345678" };

const STORED: CustomerIdentity = {
  documentType: "CC",
  documentNumber: "1098765432",
  termsAcceptedAt: "2025-01-01T00:00:00.000Z",
};

describe("resolveCheckoutIdentity — invitado", () => {
  it("exige el documento", () => {
    expect(
      resolveCheckoutIdentity(null, { terms_accepted: true }, WITH_DOC, NOW),
    ).toEqual({
      ok: false,
      field: "identity",
      error: "Documento requerido.",
    });
  });

  it("rechaza un documento que no cuadra con el paquete", () => {
    const r = resolveCheckoutIdentity(
      null,
      { identity: { documentType: "CC", documentNumber: "12" }, terms_accepted: true },
      WITH_DOC,
      NOW,
    );

    expect(r).toMatchObject({
      ok: false,
      field: "identity",
      error: "Número de documento no válido",
    });
  });

  it("exige aceptar los términos; nunca los da por aceptados", () => {
    expect(
      resolveCheckoutIdentity(null, { identity: DOC }, WITH_DOC, NOW),
    ).toMatchObject({
      ok: false,
      field: "terms_accepted",
    });
  });

  it("con documento y términos, la identidad lleva la fecha del servidor y no se guarda en ninguna cuenta", () => {
    expect(
      resolveCheckoutIdentity(
        null,
        { identity: DOC, terms_accepted: true },
        WITH_DOC,
        NOW,
      ),
    ).toEqual({
      ok: true,
      identity: { ...DOC, termsAcceptedAt: "2026-10-13T10:00:00.000Z" },
      persist: null,
    });
  });
});

describe("resolveCheckoutIdentity — país sin documentos", () => {
  it("solo pide los términos, y el documento enviado se ignora", () => {
    const r = resolveCheckoutIdentity(
      null,
      { identity: DOC, terms_accepted: true },
      TERMS_ONLY,
      NOW,
    );

    expect(r).toEqual({
      ok: true,
      identity: {
        documentType: null,
        documentNumber: null,
        termsAcceptedAt: NOW.toISOString(),
      },
      persist: null,
    });
    expect(resolveCheckoutIdentity(null, {}, TERMS_ONLY, NOW)).toMatchObject({
      ok: false,
      field: "terms_accepted",
    });
  });
});

describe("resolveCheckoutIdentity — cuenta", () => {
  it("lo que la cuenta ya tiene manda: ni documento ni términos nuevos, nada que guardar", () => {
    const r = resolveCheckoutIdentity(STORED, { identity: DOC }, WITH_DOC, NOW);

    expect(r).toEqual({ ok: true, identity: STORED, persist: null });
  });

  it("una cuenta sin documento lo toma del formulario y se guarda", () => {
    const r = resolveCheckoutIdentity(
      { ...STORED, documentType: null, documentNumber: null },
      { identity: DOC },
      WITH_DOC,
      NOW,
    );

    expect(r).toMatchObject({
      ok: true,
      identity: { ...DOC, termsAcceptedAt: STORED.termsAcceptedAt },
      persist: { documentType: "CC", documentNumber: "1012345678" },
    });
  });

  it("una cuenta que no aceptó los términos los acepta ahora y se guarda", () => {
    const r = resolveCheckoutIdentity(
      { ...STORED, termsAcceptedAt: null },
      { terms_accepted: true },
      WITH_DOC,
      NOW,
    );

    expect(r).toMatchObject({
      ok: true,
      identity: { termsAcceptedAt: NOW.toISOString() },
      persist: { termsAccepted: true },
    });
  });

  it("una cuenta sin términos no puede comprar sin aceptarlos", () => {
    expect(
      resolveCheckoutIdentity({ ...STORED, termsAcceptedAt: null }, {}, WITH_DOC, NOW),
    ).toMatchObject({ ok: false, field: "terms_accepted" });
  });
});
