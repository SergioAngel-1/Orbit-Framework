import { describe, expect, it } from "vitest";
import {
  CUSTOMER_IDENTITY_META,
  EMPTY_IDENTITY,
  ORDER_IDENTITY_META,
  hasDocument,
  identityFromMeta,
  orderIdentityMetaData,
  storeApiIdentityExtension,
} from "./identity";

const IDENTITY = {
  documentType: "CC",
  documentNumber: "1012345678",
  termsAcceptedAt: "2026-10-13T10:00:00+00:00",
};

describe("identityFromMeta", () => {
  it("lee el documento y la aceptación del meta del cliente", () => {
    const meta = [
      { key: "billing_document_type", value: "CC" },
      { key: "billing_document", value: " 1012345678 " },
      { key: "hwe_terms_accepted_at", value: "2026-10-13T10:00:00+00:00" },
      { key: "otro", value: "no cuenta" },
    ];

    expect(identityFromMeta(meta)).toEqual(IDENTITY);
  });

  it("lee el meta del pedido con sus claves", () => {
    const meta = [
      { key: "_hwe_document_type", value: "NIT" },
      { key: "_hwe_document_number", value: "9001234567" },
    ];

    expect(identityFromMeta(meta, ORDER_IDENTITY_META)).toEqual({
      documentType: "NIT",
      documentNumber: "9001234567",
      termsAcceptedAt: null,
    });
  });

  it("un documento a medias no es un documento", () => {
    const meta = [{ key: CUSTOMER_IDENTITY_META.type, value: "CC" }];

    expect(identityFromMeta(meta)).toEqual(EMPTY_IDENTITY);
    expect(
      identityFromMeta([{ key: CUSTOMER_IDENTITY_META.number, value: "1012345678" }])
        .documentNumber,
    ).toBeNull();
  });

  it("sin meta, o con valores que no son texto, está vacío", () => {
    expect(identityFromMeta(undefined)).toEqual(EMPTY_IDENTITY);
    expect(identityFromMeta([])).toEqual(EMPTY_IDENTITY);
    expect(
      identityFromMeta([{ key: CUSTOMER_IDENTITY_META.terms, value: ["x"] }])
        .termsAcceptedAt,
    ).toBeNull();
  });
});

describe("hasDocument / orderIdentityMetaData / extensión", () => {
  it("hasDocument pide tipo y número", () => {
    expect(hasDocument(IDENTITY)).toBe(true);
    expect(hasDocument({ ...IDENTITY, documentNumber: null })).toBe(false);
  });

  it("deja en el pedido el documento y la aceptación", () => {
    expect(orderIdentityMetaData(IDENTITY)).toEqual([
      { key: "_hwe_document_type", value: "CC" },
      { key: "_hwe_document_number", value: "1012345678" },
      { key: "_hwe_terms_accepted_at", value: "2026-10-13T10:00:00+00:00" },
    ]);
    expect(orderIdentityMetaData({ ...EMPTY_IDENTITY, termsAcceptedAt: "t" })).toEqual([
      { key: "_hwe_terms_accepted_at", value: "t" },
    ]);
    expect(orderIdentityMetaData(EMPTY_IDENTITY)).toEqual([]);
  });

  it("la extensión de la Store API usa los nombres que declara el plugin", () => {
    expect(storeApiIdentityExtension(IDENTITY)).toEqual({
      document_type: "CC",
      document_number: "1012345678",
      terms_accepted_at: "2026-10-13T10:00:00+00:00",
    });
  });
});
