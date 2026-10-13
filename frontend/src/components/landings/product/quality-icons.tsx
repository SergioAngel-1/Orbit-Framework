import type { SVGProps } from "react";

// ============================================================================
//  Íconos del selector de cualidades y componentes de la landing de producto.
//
//  Las CLAVES son las del set neutro del plugin (`Fields::DEFAULT_ICONS`):
//  `tests/unit/product-landing-parity.test.ts` comprueba que coinciden. Una instancia que amplíe el
//  set con `hwe_product_landing_icons` declara aquí (o en su propia vista) los
//  dibujos de las claves nuevas; una clave desconocida cae a la hoja.
// ============================================================================

type IconProps = SVGProps<SVGSVGElement>;

const PATHS: Record<string, string> = {
  sparkles:
    "M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z",
  heart:
    "M12 20.5s-7.5-4.7-9.5-9.6A5.3 5.3 0 0 1 12 6.4a5.3 5.3 0 0 1 9.5 4.5c-2 4.9-9.5 9.6-9.5 9.6z",
  leaf: "M5 19C5 10 10.5 5 20 4c0 10-5 15-13 15M5 19l8-8",
  shield: "M12 3l8 3v6c0 5-3.4 8-8 9-4.6-1-8-4-8-9V6l8-3z",
  sun: "M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4",
  moon: "M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z",
  droplets: "M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z",
  wind: "M3 8h11a3 3 0 1 0-3-3M3 12h16a3 3 0 1 1-3 3M3 16h8",
  flame: "M12 3c1 4 5 5.5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 .5 1.5 1 2 2 2 0-3-1-5 1-8z",
  anchor: "M12 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM12 7v14M6 12H3a9 9 0 0 0 18 0h-3M8 10h8",
  compass: "M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM15.5 8.5l-2 5-5 2 2-5 5-2z",
  sprout:
    "M12 21v-9M12 12C12 8 9 6 5 6c0 4 3 6 7 6zM12 14c0-3 2.5-5 6-5 0 3-2.5 5-6 5z",
};

/** Claves del set neutro, en el orden del plugin. */
export const QUALITY_ICON_KEYS = Object.keys(PATHS);

export function QualityIcon({
  icon,
  ...props
}: IconProps & { icon: string | null | undefined }) {
  const path = (icon && PATHS[icon]) || PATHS.leaf;
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.6}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <path d={path} />
    </svg>
  );
}
