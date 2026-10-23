import type { LoyaltyConfig } from "@/lib/config/types";

// ============================================================================
//  Helpers puros de presentación del monedero.
//
//  No formatean dinero: el valor de la unidad es un decimal de configuración y
//  aquí solo se convierte para poder mostrarlo. Los importes de la Store API
//  (unidades menores) siguen pasando por `formatStoreAmount` de `lib/format`.
// ============================================================================

/**
 * Valor de una unidad en la moneda de la tienda. La config lo trae como texto;
 * vacío, no numérico o ≤ 0 significa «canje desactivado» (el backend lo sanea
 * igual), así que se devuelve 0 en vez de inventar un importe.
 */
function pointValue(config: Pick<LoyaltyConfig, "point_value">): number {
  if (typeof config.point_value !== "string") return 0;
  const value = Number(config.point_value.trim().replace(",", "."));
  return Number.isFinite(value) && value > 0 ? value : 0;
}

/**
 * Convierte una cantidad de unidades a dinero (unidades mayores de la moneda).
 *
 * Es una conversión de presentación: el descuento real lo calcula WordPress con
 * la precisión de la moneda de WooCommerce. Un cargo (puntos negativos) produce
 * un importe negativo; un valor de unidad inválido produce 0.
 */
export function pointsToMoney(
  points: number,
  config: Pick<LoyaltyConfig, "point_value">,
): number {
  const value = pointValue(config);
  if (value <= 0 || !Number.isFinite(points)) return 0;
  return points * value;
}

/**
 * Cantidad con el nombre de la unidad en singular o plural y su icono.
 *
 * El nombre sale SIEMPRE de `config.loyalty` (nunca está escrito a mano) y una
 * unidad se considera singular también en negativo (-1). `icon` es `null`
 * cuando la configuración no define uno.
 */
export function formatPoints(
  points: number,
  config: Pick<
    LoyaltyConfig,
    "currency_name" | "currency_name_plural" | "currency_icon"
  >,
  locale = "es",
): { text: string; icon: string | null } {
  const name =
    Math.abs(points) === 1
      ? config.currency_name.trim()
      : config.currency_name_plural.trim();
  const amount = new Intl.NumberFormat(locale, {
    maximumFractionDigits: 0,
  }).format(points);

  return {
    text: name ? `${amount} ${name}` : amount,
    icon: config.currency_icon.trim() || null,
  };
}
