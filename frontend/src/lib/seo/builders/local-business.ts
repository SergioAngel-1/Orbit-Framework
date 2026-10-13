import type { SiteConfig } from "@/lib/config/types";
import { getConfiguredLocations } from "@/lib/config/locations";
import { dialableNumber } from "@/lib/config/social";
import { cleanBase, postalAddress } from "./shared";

// ============================================================================
//  JSON-LD de negocio local, uno por sede configurada. Funciones puras.
// ============================================================================

/**
 * Tipos schema.org admitidos para una sede (`seo.business_type`). La lista
 * debe coincidir con las opciones de `SchemaGroups/Seo.php` (lo comprueba
 * `jsonld.test.ts`): un valor que no esté aquí vuelve a `LocalBusiness`, nunca
 * se publica un `@type` que el panel no ofreció.
 */
export const BUSINESS_TYPES = [
  "LocalBusiness",
  "Store",
  "ClothingStore",
  "ShoeStore",
  "JewelryStore",
  "BookStore",
  "ElectronicsStore",
  "FurnitureStore",
  "HardwareStore",
  "PetStore",
  "SportingGoodsStore",
  "GroceryStore",
  "Florist",
  "Pharmacy",
  "HealthAndBeautyBusiness",
  "BeautySalon",
  "Restaurant",
  "CafeOrCoffeeShop",
  "Bakery",
  "ProfessionalService",
  "HomeAndConstructionBusiness",
] as const;

export function businessType(config: SiteConfig): string {
  const configured = (config.seo.business_type || "").trim();
  return (BUSINESS_TYPES as readonly string[]).includes(configured)
    ? configured
    : "LocalBusiness";
}

/**
 * JSON-LD de negocio local por sede configurada en Control Center → Legal →
 * Sedes, con el tipo de `seo.business_type`. Se emite solo en `/contact`, donde
 * las sedes ya son contenido visible — no en el `@graph` global.
 */
export function buildLocalBusinessJsonLd(
  config: SiteConfig,
): Record<string, unknown>[] {
  const base = cleanBase(config.brand.url);
  const locations = getConfiguredLocations(config);
  const type = businessType(config);

  return locations.map((loc, i) => {
    const node: Record<string, unknown> = {
      "@context": "https://schema.org",
      "@type": type,
      ...(base ? { "@id": `${base}/#location-${i + 1}` } : {}),
      name: loc.name,
      address: postalAddress(config, {
        street: loc.address,
        city: loc.city,
        region: loc.region,
        postalCode: loc.postalCode,
      }),
    };
    if (base) node.parentOrganization = { "@id": `${base}/#organization` };
    // Un número que ya trae su «+» se publica como está escrito; el que lleva
    // el indicativo a la antigua («(057) 321…») sale en E.164, que es lo que
    // se puede marcar.
    const dialable = dialableNumber(loc.phone);
    const telephone =
      dialable?.startsWith("+") && !loc.phone.trim().startsWith("+")
        ? dialable
        : loc.phone;
    if (telephone) node.telephone = telephone;
    if (loc.mapsUrl) node.hasMap = loc.mapsUrl;
    if (loc.latitude !== null && loc.longitude !== null) {
      node.geo = {
        "@type": "GeoCoordinates",
        latitude: loc.latitude,
        longitude: loc.longitude,
      };
    }
    if (loc.hours.length > 0) node.openingHours = loc.hours;
    return node;
  });
}
