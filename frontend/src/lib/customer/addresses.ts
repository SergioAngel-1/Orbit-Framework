import type { SavedAddress } from "@/types/address";
import type { WooCustomer } from "@/types/woocommerce";

/** Meta del cliente donde se guarda su libreta de direcciones (JSON). */
export const ADDRESSES_META_KEY = "hwe_addresses";

/**
 * Libreta de direcciones de un cliente, a partir de su `meta_data` de wc/v3.
 * Tolerante: sin meta, JSON roto o una forma inesperada dan `[]`.
 */
export function parseSavedAddresses(
  metaData: WooCustomer["meta_data"] | undefined,
): SavedAddress[] {
  const meta = metaData?.find((m) => m.key === ADDRESSES_META_KEY);
  if (!meta?.value) return [];
  try {
    const parsed = typeof meta.value === "string" ? JSON.parse(meta.value) : meta.value;
    return Array.isArray(parsed) ? (parsed as SavedAddress[]) : [];
  } catch {
    return [];
  }
}

/** La dirección predeterminada de la libreta, o la primera; `null` si no hay ninguna. */
export function defaultAddress(
  addresses: readonly SavedAddress[],
): SavedAddress | null {
  return addresses.find((a) => a.is_default) ?? addresses[0] ?? null;
}
