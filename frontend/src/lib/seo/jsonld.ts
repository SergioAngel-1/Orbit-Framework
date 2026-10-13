/**
 * Constructores de JSON-LD para SEO/GEO. Funciones puras: reciben los datos ya
 * resueltos (producto, config, URLs) y devuelven el objeto schema.org listo
 * para serializar. Mantener el markup aquí evita duplicarlo en cada página.
 *
 * Este archivo es la API pública del módulo; la implementación vive por tema en
 * `builders/`:
 *   · `product`        — `Product` (oferta, envío, devoluciones, reseñas)
 *   · `site`           — `@graph` del sitio: Organization, WebSite, fundador, personas
 *   · `content`        — FAQPage, Article, VideoObject, Service, BreadcrumbList
 *   · `local-business` — una sede = un nodo de negocio local
 *   · `collection`     — CollectionPage + ItemList
 *   · `shared`         — URL base, listas del panel, PostalAddress
 */

export * from "./builders/product";
export * from "./builders/site";
export * from "./builders/content";
export * from "./builders/local-business";
export * from "./builders/collection";
export { parseList, postalAddress } from "./builders/shared";
