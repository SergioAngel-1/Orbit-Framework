// ============================================================================
//  Identidad del cliente: documento + aceptación de términos.
//
//  Los datos los escribe el plugin `hwe-customer-identity` en WordPress. El BFF
//  los lee del `meta_data` que devuelve wc/v3 (cliente o pedido), y aquí es donde
//  se traducen, sin I/O, a una forma única. El tipo es el código del paquete de
//  país (`CC`, `DNI`…), no una lista cerrada del framework.
// ============================================================================

export interface CustomerIdentity {
  documentType: string | null;
  documentNumber: string | null;
  /** ISO 8601; null si nunca se registró la aceptación. */
  termsAcceptedAt: string | null;
}

type MetaEntry = { key: string; value: unknown };
type MetaKeys = { type: string; number: string; terms: string };

/** User meta del cliente (sale en `WooCustomer.meta_data`). */
export const CUSTOMER_IDENTITY_META: MetaKeys = {
  type: "billing_document_type",
  number: "billing_document",
  terms: "hwe_terms_accepted_at",
};

/** Order meta del pedido (lo escribe el checkout, lo lee el cobro). */
export const ORDER_IDENTITY_META: MetaKeys = {
  type: "_hwe_document_type",
  number: "_hwe_document_number",
  terms: "_hwe_terms_accepted_at",
};

export const EMPTY_IDENTITY: CustomerIdentity = {
  documentType: null,
  documentNumber: null,
  termsAcceptedAt: null,
};

function metaString(meta: MetaEntry[], key: string): string | null {
  const value = meta.find((m) => m.key === key)?.value;
  return typeof value === "string" && value.trim() !== "" ? value.trim() : null;
}

export function identityFromMeta(
  meta: MetaEntry[] | undefined,
  keys: MetaKeys = CUSTOMER_IDENTITY_META,
): CustomerIdentity {
  if (!meta?.length) return EMPTY_IDENTITY;
  const type = metaString(meta, keys.type);
  const number = metaString(meta, keys.number);
  // Un documento a medias (tipo sin número o al revés) no es un documento.
  const complete = type !== null && number !== null;
  return {
    documentType: complete ? type : null,
    documentNumber: complete ? number : null,
    termsAcceptedAt: metaString(meta, keys.terms),
  };
}

export function hasDocument(
  identity: CustomerIdentity,
): identity is CustomerIdentity & { documentType: string; documentNumber: string } {
  return identity.documentType !== null && identity.documentNumber !== null;
}

/** `meta_data` de wc/v3 para dejar la identidad en el pedido. */
export function orderIdentityMetaData(
  identity: CustomerIdentity,
): { key: string; value: string }[] {
  const meta: { key: string; value: string }[] = [];
  if (hasDocument(identity)) {
    meta.push(
      { key: ORDER_IDENTITY_META.type, value: identity.documentType },
      { key: ORDER_IDENTITY_META.number, value: identity.documentNumber },
    );
  }
  if (identity.termsAcceptedAt) {
    meta.push({ key: ORDER_IDENTITY_META.terms, value: identity.termsAcceptedAt });
  }
  return meta;
}

/**
 * Espacio de nombres de `extensions` en el `POST /checkout` de la Store API
 * (`HWE_Customer_Identity::CHECKOUT_NAMESPACE` en el plugin).
 */
export const STORE_API_IDENTITY_NAMESPACE = "hwe-identity";

/**
 * Datos de extensión para que el pedido NAZCA con su documento: con efectivo,
 * la Store API lo pasa a «Procesando» —y salen los correos— dentro de la
 * misma petición que lo crea, antes de que el BFF pueda escribir en él.
 */
export function storeApiIdentityExtension(identity: CustomerIdentity): {
  document_type: string | null;
  document_number: string | null;
  terms_accepted_at: string | null;
} {
  return {
    document_type: identity.documentType,
    document_number: identity.documentNumber,
    terms_accepted_at: identity.termsAcceptedAt,
  };
}
