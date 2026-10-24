// ============================================================================
//  Contrato TypeScript del módulo `memberships`.
//
//  Describe lo que devuelven las rutas internas de WordPress
//  (`hwe/v1/memberships/…`) ya normalizado para el BFF: los textos que ve el
//  cliente se componen aquí (`describe.ts`), nunca en WordPress. Nada de este
//  archivo depende de `@/components/**` ni de `@/instance/**`: `lib/` es núcleo.
// ============================================================================

/** Nivel mínimo que abre un producto o una categoría. */
export interface MembershipRequiredLevel {
  slug: string;
  rank: number;
  name: string;
}

/**
 * Requisito de acceso de la ficha de escaparate (`Product.hweMembership` en
 * GraphQL; `hwe_membership` en las rutas internas). `locked` lo evalúa
 * WordPress para la petición; el catálogo ISR se lee sin usuario.
 */
export interface MembershipRequirement {
  requiredLevel: MembershipRequiredLevel | null;
  /** Fecha de apertura anticipada (ISO 8601 UTC) o `null`. */
  opensAt: string | null;
  locked: boolean;
}

/** Datos estructurados de un beneficio (clave + valores, nunca frases). */
export type MembershipBenefitData = Record<string, unknown>;

/**
 * Un beneficio. En `/levels` lleva los datos configurados del nivel; en `/me`
 * añade el estado calculado para el socio (cupos, porcentaje efectivo…). El
 * texto llega ya compuesto en el idioma de la petición.
 */
export interface MembershipBenefit {
  /** Clave estable del tipo (`member_discount`, `early_access`…). */
  key: string;
  /** `publicData()` del tipo para el nivel. */
  data: MembershipBenefitData;
  /** `stateFor()` del tipo; solo lo añade `/me`. */
  state?: MembershipBenefitData;
  /** Texto para el cliente, compuesto en el BFF. */
  text: string;
}

/** Producto con el que se compra un nivel. */
export interface MembershipLevelProduct {
  id: number;
  /** Precio decimal de WooCommerce, como texto. */
  price: string;
  /** Días de la membresía; `null` = indefinida. */
  days: number | null;
  /** Duración ya compuesta («30 días», «Indefinida»). */
  duration: string;
}

/** Nivel publicado con sus beneficios y sus productos de compra. */
export interface MembershipLevel {
  id: number;
  name: string;
  /** Descripción del editor; puede traer HTML (sanéalo en la vista). */
  description: string;
  /** Slug nativo (identidad del nivel). */
  slug: string;
  /** Slug por idioma secundario (el nativo es `slug`). */
  slugs: Record<string, string>;
  rank: number;
  icon: string;
  color: string;
  purchasable: boolean;
  min_account_age_days: number;
  benefits: MembershipBenefit[];
  products: MembershipLevelProduct[];
}

/** Estado del socio: `none` sin membresía, `active` vigente, `frozen` congelada. */
export type MembershipStatus = "none" | "active" | "frozen";

/** Entrada de la cola programada por nivel. */
export interface MembershipQueueEntry {
  level_id: number;
  rank: number;
  seconds: number;
  days: number;
  starts_at: string | null;
  ends_at: string | null;
  undated: boolean;
  infinite: boolean;
  /** Duración ya compuesta en el idioma de la petición. */
  duration: string;
}

/** Elegibilidad de compra de un nivel (`HWE_Memberships_Purchase::canPurchase`). */
export interface MembershipPurchaseEligibility {
  level_id: number;
  ok: boolean;
  /** Motivo estable (`membership_*`); vacío si se puede comprar. */
  reason: string;
  days_left: number;
  min_account_age_days: number;
  account_age_days: number | null;
  current_level_id: number;
}

/** Regalos por referido disponibles en el periodo de la concesión activa. */
export interface MembershipGifts {
  max: number;
  used: number;
  remaining: number;
}

/** Estado completo del socio (`GET hwe/v1/memberships/me`). */
export interface MembershipState {
  status: MembershipStatus;
  /** Nivel efectivo: solo id y rango; la etiqueta sale de `/levels`. */
  level: { id: number; rank: number } | null;
  ends_at: string | null;
  member_since: string | null;
  /** Número listo para mostrar: prefijo de la configuración ya aplicado. */
  member_number: string;
  queue: MembershipQueueEntry[];
  benefits: MembershipBenefit[];
  gifts: MembershipGifts;
  purchasable: MembershipPurchaseEligibility[];
}

/** Un evento del historial. `description` lo añade el BFF. */
export interface MembershipHistoryEntry {
  id: number;
  /** Tipo del ciclo (`activated`, `renewed`, `revoked`…). */
  type: string;
  level_from: number;
  level_to: number;
  /** Lista blanca por tipo; nunca ids internos ni claves de idempotencia. */
  data: Record<string, unknown>;
  created_at: string | null;
  /** Texto compuesto en el idioma de la petición. */
  description?: string;
}

/** Página del historial. */
export interface MembershipHistoryPage {
  items: MembershipHistoryEntry[];
  total: number;
  /** `ceil(total / per_page)`; 0 sin resultados. */
  pages: number;
}

/** «Precio para ti» de un producto. */
export interface MembershipPrice {
  id: number;
  regular: string;
  price: string;
  member_price: string;
  percent: number;
}

/** Imagen de la ficha del módulo. */
export interface MembershipProductImage {
  id: number;
  src: string;
  alt: string;
}

/** Categoría de la ficha del módulo. */
export interface MembershipProductCategory {
  id: number;
  name: string;
  slug: string;
}

/** Atributo de la ficha completa. */
export interface MembershipProductAttribute {
  id: number;
  name: string;
  options: string[];
  visible: boolean;
  variation: boolean;
}

/** Variación de la ficha completa (máx. 50). */
export interface MembershipProductVariation {
  id: number;
  price: string;
  regular_price: string;
  sale_price: string | null;
  on_sale: boolean;
  stock_status: string;
  manage_stock: boolean;
  stock_quantity: number | null;
  backorders_allowed: boolean;
  attributes: { name: string; option: string }[];
}

/**
 * Ficha que devuelve `GET hwe/v1/memberships/product`: completa para el socio
 * que cumple el requisito y recortada (ficha de escaparate) para el resto. Los
 * campos de la ficha completa son opcionales porque la recortada no los trae.
 */
export interface MembershipProduct {
  id: number;
  name: string;
  slug: string;
  type: string;
  status: string;
  purchasable: boolean;
  on_sale: boolean;
  price: string;
  regular_price: string;
  sale_price: string | null;
  stock_status: string;
  manage_stock: boolean;
  stock_quantity: number | null;
  backorders_allowed: boolean;
  categories: MembershipProductCategory[];
  images: MembershipProductImage[];
  locked: boolean;
  /** Requisito con las claves de las rutas internas (nunca el id de usuario). */
  hwe_membership: {
    required_level: MembershipRequiredLevel | null;
    opens_at: string | null;
  };
  short_description?: string;
  description?: string;
  attributes?: MembershipProductAttribute[];
  variations?: MembershipProductVariation[];
  hweFields?: { key: string; value: string }[];
}

/** Carné emitido por WordPress, sin la URL absoluta (la compone el BFF). */
export interface MembershipCardToken {
  token: string;
  /** ISO 8601 UTC. */
  expires_at: string;
  /** Ruta del frontend configurada para el QR. */
  verify_path: string;
}

/** Carné listo para el cliente. */
export interface MembershipCard {
  token: string;
  expires_at: string;
  /** URL absoluta del QR, compuesta con `config.brand.url`. */
  verify_url: string;
}

/** Documento enmascarado del carné (solo con `customer-identity` activo). */
export interface MembershipVerificationDocument {
  type: string;
  number: string;
}

/**
 * Resultado de la verificación pública. Un token inexistente, caducado o
 * revocado da EXACTAMENTE `{ valid: false }`: no se distingue el motivo.
 */
export type MembershipVerification =
  | { valid: false }
  | {
      valid: true;
      level: { name: string; slug: string };
      member_since: string | null;
      member_number: string;
      member_name: string;
      document?: MembershipVerificationDocument;
    };

/** Códigos de fallo de un lector del módulo. */
export type MembershipsReadErrorCode =
  "not_found" | "forbidden" | "invalid_request" | "unavailable" | "invalid_response";

/**
 * Error tipado de los lectores. Un fallo de WordPress NUNCA se convierte en un
 * estado inventado: se lanza esto y la ruta responde un 5xx con su código.
 * `reason` conserva el motivo estable de WordPress (p. ej.
 * `membership_card_unavailable`) cuando lo hay.
 */
export class MembershipsReadError extends Error {
  readonly code: MembershipsReadErrorCode;
  readonly status: number | null;
  readonly reason: string | null;

  constructor(
    code: MembershipsReadErrorCode,
    status: number | null = null,
    reason: string | null = null,
  ) {
    super(MESSAGE[code]);
    this.name = "MembershipsReadError";
    this.code = code;
    this.status = status;
    this.reason = reason;
  }
}

const MESSAGE: Record<MembershipsReadErrorCode, string> = {
  not_found: "La función de membresías no está disponible.",
  forbidden: "El carné de socio no está disponible.",
  invalid_request: "La petición de membresías no es válida.",
  unavailable: "No se pudo consultar el módulo de membresías.",
  invalid_response: "La respuesta de membresías no cumple el contrato.",
};
