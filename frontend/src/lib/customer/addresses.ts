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

/**
 * Forma de comparar calle y ciudad: minúsculas, sin tildes y con los espacios colapsados. Es la
 * misma huella que usa WordPress para el punto de entrega (`DeliveryPoint::fingerprint`): un
 * cambio solo de mayúsculas o de espacios no invalida el pin en ninguno de los dos lados.
 */
function fold(value: string): string {
  return value
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .replace(/\s+/g, " ")
    .trim()
    .toLowerCase();
}

/**
 * Dirección a guardar tras una edición, con su pin según la regla del envío por distancia:
 *
 * - Si la edición trae una ubicación (el cliente acaba de marcarla), esa es la que vale.
 * - Si no, la anterior se conserva SOLO si la calle y la ciudad no cambian. El teléfono,
 *   el nombre u otros datos no invalidan el pin.
 * - Alta (sin dirección anterior): sin ubicación.
 *
 * Editar la calle o la ciudad borra el pin: el cliente debe volver a marcarlo. Así el punto de
 * entrega nunca queda asociado a una dirección que ya no es la suya.
 */
export function mergeAddressLocation(
  previous: SavedAddress | undefined,
  next: SavedAddress,
): SavedAddress {
  const { location, ...base } = next;
  if (location) return { ...base, location };
  if (
    previous?.location &&
    fold(previous.address_1) === fold(next.address_1) &&
    fold(previous.city) === fold(next.city)
  ) {
    return { ...base, location: previous.location };
  }
  return base;
}
