import type { SavedAddress } from "@/types/address";
import type { WooCustomer } from "@/types/woocommerce";

/** Meta del cliente donde se guarda su libreta de direcciones (JSON). */
export const ADDRESSES_META_KEY = "hwe_addresses";

const ADDRESS_ID_RE = /^[A-Za-z0-9_-]{1,64}$/;

function isAddressEntry(entry: unknown): entry is SavedAddress {
  return typeof entry === "object" && entry !== null && !Array.isArray(entry);
}

/** Identificador de una dirección nueva. */
export function newAddressId(): string {
  return `addr_${crypto.randomUUID()}`;
}

/** FNV-1a de 32 bits, en dos pasadas: este módulo también lo usa código sin `node:crypto`. */
function contentHash(text: string): string {
  const pass = (seed: number) => {
    let hash = seed;
    for (let i = 0; i < text.length; i++) {
      hash = Math.imul(hash ^ text.charCodeAt(i), 0x01000193);
    }
    return (hash >>> 0).toString(36);
  };
  return pass(0x811c9dc5) + pass(0x1b873593);
}

/**
 * Da su identificador a cada dirección. Las guardadas antes de que existiera no se migran: el suyo se
 * deriva de su contenido (y de la repetición, si hay dos iguales), así que no cambia entre lecturas,
 * y queda grabado en la siguiente escritura de la libreta, que guarda todas las direcciones.
 */
function withAddressIds(addresses: SavedAddress[]): SavedAddress[] {
  const taken = new Set<string>();
  const stored = (address: SavedAddress) =>
    typeof address.id === "string" && ADDRESS_ID_RE.test(address.id)
      ? address.id
      : null;

  // Primero los ya guardados: uno derivado nunca debe quitarle el suyo a otra dirección.
  for (const address of addresses) {
    const id = stored(address);
    if (id) taken.add(id);
  }

  const seen = new Set<string>();
  return addresses.map((address) => {
    const id = stored(address);
    if (id && !seen.has(id)) {
      seen.add(id);
      return address;
    }
    const content = { ...address };
    delete content.id;
    const base = `addr_${contentHash(JSON.stringify(content))}`;
    let derived = base;
    for (let repeat = 2; taken.has(derived); repeat++) derived = `${base}_${repeat}`;
    taken.add(derived);
    seen.add(derived);
    return { ...address, id: derived };
  });
}

/**
 * Posición de la dirección a la que apunta una petición, o `-1` si ya no existe. Con `id` se busca
 * esa dirección aunque la lista haya cambiado; `index` es la forma anterior del contrato, y puede
 * acabar en otra dirección si la libreta cambió desde que se leyó.
 */
export function findAddressIndex(
  addresses: readonly SavedAddress[],
  target: { id?: string; index?: number },
): number {
  if (target.id !== undefined) return addresses.findIndex((a) => a?.id === target.id);
  const { index } = target;
  return index !== undefined && index >= 0 && addresses[index] ? index : -1;
}

/**
 * Libreta de direcciones de un cliente, a partir de su `meta_data` de wc/v3, con el identificador de
 * cada dirección (ver `withAddressIds`).
 * Tolerante: sin meta, JSON roto o una forma inesperada dan `[]`, y lo que no sea una dirección en
 * una libreta corrupta se descarta (toda la lectura y la escritura parten de esta lista: `index` es la
 * posición en ella, la misma que entrega `GET`, y la siguiente escritura deja la libreta limpia).
 */
export function parseSavedAddresses(
  metaData: WooCustomer["meta_data"] | undefined,
): SavedAddress[] {
  const meta = metaData?.find((m) => m.key === ADDRESSES_META_KEY);
  if (!meta?.value) return [];
  try {
    const parsed = typeof meta.value === "string" ? JSON.parse(meta.value) : meta.value;
    return Array.isArray(parsed) ? withAddressIds(parsed.filter(isAddressEntry)) : [];
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
