// ============================================================================
//  Cupones PÚBLICOS: forma, ventana de fechas y alcance.
//
//  Puro (sin `server-only`): lo usan la capa de datos (`public-coupons.ts`) y
//  el componente cliente que oculta el aviso fuera de su ventana. La fuente
//  es el endpoint interno `hwe/v1/coupons/public` de `hwe-coupon-rules.php`,
//  que ya descarta los privados, los caducados y los agotados.
//
//  El aviso NO calcula precios: solo dice qué descuento se aplica al
//  finalizar la compra y con qué código. Quien decide si el cupón vale es
//  WordPress al aplicarlo; este módulo solo decide DÓNDE anunciarlo.
// ============================================================================

import { formatMoney } from "@/lib/format";

export type CouponScope = "all" | "products" | "categories";
export type CouponDiscountType = "percent" | "fixed_cart" | "fixed_product";

export interface PublicCoupon {
  code: string;
  discountType: CouponDiscountType;
  amount: number;
  /**
   * «Envío gratis» del cupón: la tarifa de la ciudad queda a 0 (lo aplica el
   * plugin HWE Shipping). Un cupón puede dar SOLO esto, con `amount` 0.
   */
  freeShipping: boolean;
  /** Todo el catálogo, unos productos o unas categorías (con su descendencia). */
  scope: CouponScope;
  productSlugs: string[];
  categorySlugs: string[];
  excludedProductSlugs: string[];
  excludedCategorySlugs: string[];
  excludeSaleItems: boolean;
  /** Gasto mínimo del carrito (en la moneda de la tienda), o `null` si no hay. */
  minimumAmount: number | null;
  /** Gasto máximo del carrito, o `null` si no hay. */
  maximumAmount: number | null;
  requiresLogin: boolean;
  firstOrderOnly: boolean;
  /**
   * Si que siga disponible depende del cliente (límite por usuario o primera
   * compra). Solo entonces hace falta preguntar a WordPress por su historial.
   */
  perUserLimited: boolean;
  /** Timestamp (s) del primer instante válido, o `null` si no tiene inicio. */
  startsAt: number | null;
  /** Timestamp (s) EXCLUSIVO del fin, o `null` si no caduca. */
  endsAt: number | null;
}

/**
 * Cupón listo para anunciar: con `activeAtRender`, lo que decidió el servidor
 * al renderizar (el navegador lo corrige después con su reloj). Se calcula en
 * el servidor y no en el componente: leer el reloj durante el render no es puro.
 */
export type NoticeCoupon = PublicCoupon & { activeAtRender: boolean };

const SCOPES: readonly CouponScope[] = ["all", "products", "categories"];
const DISCOUNT_TYPES: readonly CouponDiscountType[] = [
  "percent",
  "fixed_cart",
  "fixed_product",
];

function stringList(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((v): v is string => typeof v === "string" && v !== "")
    : [];
}

function spendLimit(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) && value > 0
    ? value
    : null;
}

function timestamp(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

/** Normaliza la respuesta del endpoint (defensivo: es JSON externo). */
export function parsePublicCoupons(payload: unknown): PublicCoupon[] {
  const items = (payload as { items?: unknown } | null)?.items;
  if (!Array.isArray(items)) return [];
  const coupons: PublicCoupon[] = [];
  for (const raw of items) {
    if (!raw || typeof raw !== "object") continue;
    const row = raw as Record<string, unknown>;
    const code = typeof row.code === "string" ? row.code.trim() : "";
    const amount = Number(row.amount);
    const discountType = row.discountType as CouponDiscountType;
    const scope = row.scope as CouponScope;
    const freeShipping = row.freeShipping === true;
    // Sin descuento ni envío gratis no hay nada que anunciar.
    if (!code || !Number.isFinite(amount) || amount < 0) continue;
    if (amount === 0 && !freeShipping) continue;
    if (!DISCOUNT_TYPES.includes(discountType) || !SCOPES.includes(scope)) continue;
    const productSlugs = stringList(row.productSlugs);
    const categorySlugs = stringList(row.categorySlugs);
    coupons.push({
      code,
      discountType,
      amount,
      freeShipping,
      // Un alcance con la lista vacía aplica a todo, igual que en WooCommerce.
      scope:
        (scope === "products" && productSlugs.length === 0) ||
        (scope === "categories" && categorySlugs.length === 0)
          ? "all"
          : scope,
      productSlugs,
      categorySlugs,
      excludedProductSlugs: stringList(row.excludedProductSlugs),
      excludedCategorySlugs: stringList(row.excludedCategorySlugs),
      excludeSaleItems: row.excludeSaleItems === true,
      minimumAmount: spendLimit(row.minimumAmount),
      maximumAmount: spendLimit(row.maximumAmount),
      requiresLogin: row.requiresLogin === true,
      firstOrderOnly: row.firstOrderOnly === true,
      // Sin el campo (WordPress anterior), se asume que sí: es lo seguro.
      perUserLimited: row.perUserLimited !== false,
      startsAt: timestamp(row.startsAt),
      endsAt: timestamp(row.endsAt),
    });
  }
  return coupons;
}

/** ¿Está el cupón dentro de su ventana en `nowMs`? */
export function isCouponActive(
  coupon: Pick<PublicCoupon, "startsAt" | "endsAt">,
  nowMs: number,
): boolean {
  if (coupon.startsAt !== null && nowMs < coupon.startsAt * 1000) return false;
  if (coupon.endsAt !== null && nowMs >= coupon.endsAt * 1000) return false;
  return true;
}

/** Lo que hay que saber del momento y del cliente para decidir si se enseña. */
export interface CouponViewState {
  /** ¿Está dentro de su ventana de fechas AHORA? (`isCouponActive`). */
  active: boolean;
  /**
   * Códigos (en minúsculas) que ESTE cliente ya no puede usar: agotó su límite
   * por usuario, es de primera compra y ya compró, o tiene un pedido pendiente
   * con él. `null` = todavía no se sabe (la respuesta está en camino).
   */
  usedUp: ReadonlySet<string> | null;
}

/**
 * **El único sitio que decide si un cupón PÚBLICO se enseña a este cliente**:
 * avisos de ficha y categoría, de login y registro, y «Cupones disponibles» del
 * carrito y el checkout pasan por aquí, en servidor (`getCartCoupons`) o en el
 * navegador (`CouponGate`). Dónde cabe el cupón (alcance, gasto mínimo) es otra
 * pregunta (`couponAppliesTo*`); esta es «¿le conviene verlo a quien mira?».
 *
 * Un cupón que depende del cliente (`perUserLimited`) no se enseña mientras no
 * se sepa si ya lo agotó: mejor un instante sin aviso que un aviso que luego
 * desaparece o que promete lo que WordPress va a rechazar.
 */
export function shouldShowCoupon(
  coupon: Pick<PublicCoupon, "code" | "perUserLimited">,
  view: CouponViewState,
): boolean {
  if (!view.active) return false;
  if (!coupon.perUserLimited) return true;
  if (view.usedUp === null) return false;
  return !view.usedUp.has(coupon.code.toLowerCase());
}

/** Próximo instante (ms) en que el cupón entra o sale de su ventana, o `null`. */
export function nextCouponBoundary(
  coupon: Pick<PublicCoupon, "startsAt" | "endsAt">,
  nowMs: number,
): number | null {
  const boundaries = [coupon.startsAt, coupon.endsAt]
    .filter((t): t is number => t !== null)
    .map((t) => t * 1000)
    .filter((t) => t > nowMs);
  return boundaries.length > 0 ? Math.min(...boundaries) : null;
}

function intersects(a: readonly string[], b: readonly string[]): boolean {
  return a.some((slug) => b.includes(slug));
}

/**
 * ¿Aplica el cupón a este producto? `categorySlugs` son sus categorías CON
 * sus ancestros: WooCommerce aplica una categoría a toda su descendencia.
 */
export function couponAppliesToProduct(
  coupon: PublicCoupon,
  product: { slug: string; categorySlugs: readonly string[]; onSale?: boolean },
): boolean {
  if (coupon.excludeSaleItems && product.onSale) return false;
  if (coupon.excludedProductSlugs.includes(product.slug)) return false;
  if (intersects(product.categorySlugs, coupon.excludedCategorySlugs)) return false;
  if (coupon.scope === "products") return coupon.productSlugs.includes(product.slug);
  if (coupon.scope === "categories")
    return intersects(product.categorySlugs, coupon.categorySlugs);
  return true;
}

/**
 * ¿Sale el producto en «Ofertas» de la tienda (`?sort=on-sale`)? Lo de
 * siempre —está rebajado— o, además, lo cubre alguno de los cupones públicos
 * que quien mira PUEDE usar (`coupons` llega ya filtrado por vigencia y por
 * cliente). `categorySlugs` con ancestros, como en `couponAppliesToProduct`.
 */
export function isOfferProduct(
  product: { slug: string; categorySlugs: readonly string[]; onSale?: boolean },
  coupons: readonly PublicCoupon[],
): boolean {
  if (product.onSale) return true;
  return coupons.some((coupon) => couponAppliesToProduct(coupon, product));
}

/**
 * ¿Se anuncia en la página de esta categoría? `categoryPath` es la categoría
 * y sus ancestros. Un cupón de productos sueltos no se anuncia en ninguna
 * categoría: solo en las fichas de esos productos.
 */
export function couponAppliesToCategory(
  coupon: PublicCoupon,
  categoryPath: readonly string[],
): boolean {
  if (intersects(categoryPath, coupon.excludedCategorySlugs)) return false;
  if (coupon.scope === "categories")
    return intersects(categoryPath, coupon.categorySlugs);
  return coupon.scope === "all";
}

/** Qué da el cupón: descuento, envío gratis o las dos cosas (argumento `kind` de i18n). */
export type CouponBenefit = "discount" | "shipping" | "both";

export function couponBenefit(
  coupon: Pick<PublicCoupon, "amount" | "freeShipping">,
): CouponBenefit {
  if (coupon.amount > 0) return coupon.freeShipping ? "both" : "discount";
  return "shipping";
}

/**
 * Argumento `type` de los rótulos de cupón: cómo se aplica el importe. Un
 * `fixed_cart` descuenta UNA vez sobre la compra y un `fixed_product` por
 * unidad, y «$20.000 de descuento en este producto» es falso para el primero.
 * Un cupón de solo envío gratis no descuenta nada, así que sale como `percent`
 * (sin complemento): su `discountType` es el que WooCommerce le puso por
 * defecto y no dice nada.
 */
export function couponMessageType(
  coupon: Pick<PublicCoupon, "discountType" | "amount" | "freeShipping">,
): CouponDiscountType {
  return couponBenefit(coupon) === "shipping" ? "percent" : coupon.discountType;
}

/**
 * El descuento con su formato: «10 %» o el importe en la moneda de la tienda,
 * con los decimales de la tienda si se conocen.
 */
export function formatCouponDiscount(
  coupon: Pick<PublicCoupon, "discountType" | "amount">,
  locale: string,
  currency: string,
  decimals?: number | null,
): string {
  if (coupon.discountType === "percent") {
    return new Intl.NumberFormat(locale, {
      style: "percent",
      maximumFractionDigits: 2,
    }).format(coupon.amount / 100);
  }
  return formatMoney(coupon.amount, currency, locale, decimals);
}

/** Subtotal del carrito en unidades mayores, con y sin impuestos. */
export interface CartSubtotal {
  exclTax: number;
  inclTax: number;
}

/**
 * ¿Cabe el subtotal en el gasto mínimo y máximo del cupón? WooCommerce los
 * compara contra el subtotal del carrito ENTERO (no solo contra lo que el
 * cupón descuenta) y según «Mostrar precios en el carrito» lo hace con o sin
 * impuestos; el BFF no sabe cuál, así que se es permisivo: el mínimo se
 * compara con el subtotal más alto y el máximo con el más bajo. Si aun así
 * WordPress lo rechaza, el motivo se ve junto al campo.
 */
export function couponFitsSubtotal(
  coupon: Pick<PublicCoupon, "minimumAmount" | "maximumAmount">,
  subtotal: CartSubtotal,
): boolean {
  if (coupon.minimumAmount !== null && subtotal.inclTax < coupon.minimumAmount)
    return false;
  if (coupon.maximumAmount !== null && subtotal.exclTax > coupon.maximumAmount)
    return false;
  return true;
}

/**
 * ¿Aplica el cupón a ALGO del carrito? Cada artículo trae su slug y sus
 * categorías con ancestros (`StoreCartItem.couponRef`, lo añade el BFF). Un
 * artículo sin esa referencia (catálogo caído) solo cuenta para un cupón de
 * todo el catálogo, que no necesita saber qué es. Con `subtotal`, también
 * se respeta el gasto mínimo y máximo del cupón.
 */
export function couponAppliesToCart(
  coupon: PublicCoupon,
  items: readonly {
    couponRef?: { slug: string; categorySlugs: readonly string[] } | null;
    prices: { regular_price: string; sale_price: string };
  }[],
  subtotal?: CartSubtotal,
): boolean {
  if (subtotal && !couponFitsSubtotal(coupon, subtotal)) return false;
  return items.some((item) => {
    if (!item.couponRef) {
      return coupon.scope === "all" && !coupon.excludedProductSlugs.length;
    }
    const onSale = item.prices.sale_price !== item.prices.regular_price;
    return couponAppliesToProduct(coupon, { ...item.couponRef, onSale });
  });
}
