/**
 * Sistema de sizing fluido para Starter
 * Usa clamp() para responsive automático sin breakpoints
 * 
 * Fórmula: clamp(MIN, MIN + (MAX - MIN) * ((100vw - 320px) / (1920px - 320px)), MAX)
 * Viewports: 320px (móvil) → 1920px (desktop)
 * 
 * @package Starter
 * @version 2.0.0
 */

export interface FluidSizingSpec {
  space: {
    xs: string;
    sm: string;
    md: string;
    lg: string;
    xl: string;
    '2xl': string;
    '3xl': string;
    '4xl': string;
  };
  size: {
    iconXs: string;
    iconSm: string;
    iconMd: string;
    iconLg: string;
    iconXl: string;
    avatar: string;
    buttonSm: string;
    buttonMd: string;
    buttonLg: string;
    floatingButton: string;
    floatingIcon: string;
    inputHeight: string;
    modalSm: string;
    modalMd: string;
    modalLg: string;
    modalXl: string;
  };
  text: {
    '2xs': string;
    xs: string;
    sm: string;
    base: string;
    lg: string;
    xl: string;
    '2xl': string;
    '3xl': string;
    '4xl': string;
    '5xl': string;
    '6xl': string;
  };
  modal: {
    padding: string;
    gap: string;
    borderRadius: string;
  };
  banner: {
    heroHeight: string;
    heroHeightStatic: string;
    tallMinHeight: string;
  };
  layout: {
    maxWidth: string;
  };
}

export const fluidSizing = {
  // Spacing - Espaciado fluido para márgenes y padding
  // Fórmula aplicada: escalado lineal entre 320px y 1920px
  space: {
    xs: 'clamp(0.25rem, 0.25rem + 0.125 * ((100vw - 20rem) / 100), 0.375rem)',    // 4px → 6px
    sm: 'clamp(0.5rem, 0.5rem + 0.25 * ((100vw - 20rem) / 100), 0.75rem)',        // 8px → 12px
    md: 'clamp(0.75rem, 0.75rem + 0.5 * ((100vw - 20rem) / 100), 1.25rem)',       // 12px → 20px
    lg: 'clamp(1rem, 1rem + 0.5 * ((100vw - 20rem) / 100), 1.5rem)',              // 16px → 24px
    xl: 'clamp(1.25rem, 1.25rem + 0.75 * ((100vw - 20rem) / 100), 2rem)',         // 20px → 32px
    '2xl': 'clamp(1.5rem, 1.5rem + 1.5 * ((100vw - 20rem) / 100), 3rem)',         // 24px → 48px
    '3xl': 'clamp(2rem, 2rem + 2 * ((100vw - 20rem) / 100), 4rem)',               // 32px → 64px
    '4xl': 'clamp(2.5rem, 2.5rem + 2.5 * ((100vw - 20rem) / 100), 5rem)',         // 40px → 80px
  },

  // Sizing - Tamaños de elementos (iconos, botones, modales)
  size: {
    iconXs: 'clamp(0.75rem, 0.75rem + 0.25 * ((100vw - 20rem) / 100), 1rem)',     // 12px → 16px
    iconSm: 'clamp(1rem, 1rem + 0.25 * ((100vw - 20rem) / 100), 1.25rem)',        // 16px → 20px
    iconMd: 'clamp(1.25rem, 1.25rem + 0.25 * ((100vw - 20rem) / 100), 1.5rem)',   // 20px → 24px
    iconLg: 'clamp(1.5rem, 1.5rem + 0.5 * ((100vw - 20rem) / 100), 2rem)',        // 24px → 32px
    iconXl: 'clamp(2rem, 2rem + 1 * ((100vw - 20rem) / 100), 3rem)',              // 32px → 48px
    avatar: 'clamp(3rem, 3rem + 1 * ((100vw - 20rem) / 100), 4rem)',              // 48px → 64px
    buttonSm: 'clamp(2rem, 2rem + 0.5 * ((100vw - 20rem) / 100), 2.5rem)',        // 32px → 40px
    buttonMd: 'clamp(2.5rem, 2.5rem + 0.5 * ((100vw - 20rem) / 100), 3rem)',      // 40px → 48px
    buttonLg: 'clamp(3rem, 3rem + 0.5 * ((100vw - 20rem) / 100), 3.5rem)',        // 48px → 56px
    floatingButton: 'clamp(3.5rem, 3.5rem + 1 * ((100vw - 20rem) / 100), 4.5rem)', // 56px → 72px
    floatingIcon: 'clamp(1.75rem, 1.75rem + 0.5 * ((100vw - 20rem) / 100), 2.25rem)', // 28px → 36px
    inputHeight: 'clamp(2.5rem, 2.5rem + 0.5 * ((100vw - 20rem) / 100), 3rem)',   // 40px → 48px
    modalSm: 'clamp(18rem, 90vw, 24rem)',        // 288px → 384px (90vw en móvil)
    modalMd: 'clamp(20rem, 85vw, 32rem)',        // 320px → 512px (85vw en móvil)
    modalLg: 'clamp(28rem, 80vw, 42rem)',        // 448px → 672px (80vw en móvil)
    modalXl: 'clamp(32rem, 75vw, 56rem)',        // 512px → 896px (75vw en móvil)
  },

  // Typography - Tamaños de texto fluidos con escalado real
  text: {
    '2xs': 'clamp(0.625rem, 0.625rem + 0.0625 * ((100vw - 20rem) / 100), 0.6875rem)',  // 10px → 11px
    xs: 'clamp(0.75rem, 0.75rem + 0.0625 * ((100vw - 20rem) / 100), 0.8125rem)',       // 12px → 13px
    sm: 'clamp(0.875rem, 0.875rem + 0.0625 * ((100vw - 20rem) / 100), 0.9375rem)',     // 14px → 15px
    base: 'clamp(0.875rem, 0.875rem + 0.125 * ((100vw - 20rem) / 100), 1rem)',         // 14px → 16px
    lg: 'clamp(1rem, 1rem + 0.125 * ((100vw - 20rem) / 100), 1.125rem)',               // 16px → 18px
    xl: 'clamp(1.125rem, 1.125rem + 0.1875 * ((100vw - 20rem) / 100), 1.3125rem)',     // 18px → 21px
    '2xl': 'clamp(1.25rem, 1.25rem + 0.375 * ((100vw - 20rem) / 100), 1.625rem)',      // 20px → 26px
    '3xl': 'clamp(1.5rem, 1.5rem + 0.75 * ((100vw - 20rem) / 100), 2.25rem)',          // 24px → 36px
    '4xl': 'clamp(1.875rem, 1.875rem + 1.125 * ((100vw - 20rem) / 100), 3rem)',        // 30px → 48px
    '5xl': 'clamp(2.25rem, 2.25rem + 1.5 * ((100vw - 20rem) / 100), 3.75rem)',         // 36px → 60px
    '6xl': 'clamp(3rem, 3rem + 2 * ((100vw - 20rem) / 100), 5rem)',                    // 48px → 80px
  },

  // Banner - Alturas responsivas para banners y heros
  banner: {
    heroHeight: 'clamp(320px, 55vw, 700px)',                // fullWidth hero (landing toures)
    heroHeightStatic: 'clamp(400px, 50vw, 80vh)',           // hero estático con overlay
    tallMinHeight: 'clamp(250px, 30vw, 500px)',             // tall sidebar carousel
  },

  // Layout - Anchos máximos de contenedores
  layout: {
    maxWidth: '1280px',                                      // max-width estándar de secciones
  },

  // Modal specific - Configuración específica para modales
  modal: {
    padding: 'clamp(1rem, 1rem + 0.5 * ((100vw - 20rem) / 100), 1.5rem)',              // 16px → 24px
    gap: 'clamp(0.75rem, 0.75rem + 0.5 * ((100vw - 20rem) / 100), 1.25rem)',           // 12px → 20px
    borderRadius: 'clamp(0.5rem, 0.5rem + 0.25 * ((100vw - 20rem) / 100), 0.75rem)',   // 8px → 12px
  },
} as const satisfies FluidSizingSpec;

/**
 * Helper para crear clamp personalizado
 * @param min - Tamaño mínimo (ej: '1rem')
 * @param preferred - Tamaño preferido viewport-based (ej: '2vw')
 * @param max - Tamaño máximo (ej: '2rem')
 */
export function clamp(min: string, preferred: string, max: string): string {
  return `clamp(${min}, ${preferred}, ${max})`;
}

/**
 * Genera objeto de estilos con sizing fluido
 * Útil para aplicar múltiples propiedades de una vez
 */
export function fluidStyle(config: {
  width?: keyof FluidSizingSpec['size'] | string;
  height?: keyof FluidSizingSpec['size'] | string;
  gap?: keyof FluidSizingSpec['space'] | string;
  padding?: keyof FluidSizingSpec['space'] | string;
  paddingX?: keyof FluidSizingSpec['space'] | string;
  paddingY?: keyof FluidSizingSpec['space'] | string;
  margin?: keyof FluidSizingSpec['space'] | string;
  marginX?: keyof FluidSizingSpec['space'] | string;
  marginY?: keyof FluidSizingSpec['space'] | string;
  fontSize?: keyof FluidSizingSpec['text'] | string;
  borderRadius?: string;
  [key: string]: any;
}): Record<string, any> {
  const style: Record<string, any> = {};

  // Width
  if (config.width) {
    style.width = typeof config.width === 'string' && config.width in fluidSizing.size
      ? fluidSizing.size[config.width as keyof typeof fluidSizing.size]
      : config.width;
  }

  // Height
  if (config.height) {
    style.height = typeof config.height === 'string' && config.height in fluidSizing.size
      ? fluidSizing.size[config.height as keyof typeof fluidSizing.size]
      : config.height;
  }

  // Gap
  if (config.gap) {
    style.gap = typeof config.gap === 'string' && config.gap in fluidSizing.space
      ? fluidSizing.space[config.gap as keyof typeof fluidSizing.space]
      : config.gap;
  }

  // Padding
  if (config.padding) {
    style.padding = typeof config.padding === 'string' && config.padding in fluidSizing.space
      ? fluidSizing.space[config.padding as keyof typeof fluidSizing.space]
      : config.padding;
  }

  // Padding X (horizontal)
  if (config.paddingX) {
    const value = typeof config.paddingX === 'string' && config.paddingX in fluidSizing.space
      ? fluidSizing.space[config.paddingX as keyof typeof fluidSizing.space]
      : config.paddingX;
    style.paddingLeft = value;
    style.paddingRight = value;
  }

  // Padding Y (vertical)
  if (config.paddingY) {
    const value = typeof config.paddingY === 'string' && config.paddingY in fluidSizing.space
      ? fluidSizing.space[config.paddingY as keyof typeof fluidSizing.space]
      : config.paddingY;
    style.paddingTop = value;
    style.paddingBottom = value;
  }

  // Margin
  if (config.margin) {
    style.margin = typeof config.margin === 'string' && config.margin in fluidSizing.space
      ? fluidSizing.space[config.margin as keyof typeof fluidSizing.space]
      : config.margin;
  }

  // Margin X (horizontal)
  if (config.marginX) {
    const value = typeof config.marginX === 'string' && config.marginX in fluidSizing.space
      ? fluidSizing.space[config.marginX as keyof typeof fluidSizing.space]
      : config.marginX;
    style.marginLeft = value;
    style.marginRight = value;
  }

  // Margin Y (vertical)
  if (config.marginY) {
    const value = typeof config.marginY === 'string' && config.marginY in fluidSizing.space
      ? fluidSizing.space[config.marginY as keyof typeof fluidSizing.space]
      : config.marginY;
    style.marginTop = value;
    style.marginBottom = value;
  }

  // Font Size
  if (config.fontSize) {
    style.fontSize = typeof config.fontSize === 'string' && config.fontSize in fluidSizing.text
      ? fluidSizing.text[config.fontSize as keyof typeof fluidSizing.text]
      : config.fontSize;
  }

  // Border Radius
  if (config.borderRadius) {
    style.borderRadius = config.borderRadius;
  }

  // Agregar cualquier otra propiedad personalizada
  Object.keys(config).forEach(key => {
    if (!['width', 'height', 'gap', 'padding', 'paddingX', 'paddingY', 'margin', 'marginX', 'marginY', 'fontSize', 'borderRadius'].includes(key)) {
      style[key as any] = config[key];
    }
  });

  return style;
}

/**
 * Hook personalizado para usar sizing fluido en componentes
 * Retorna el objeto fluidSizing completo
 */
export function useFluidSizing() {
  return fluidSizing;
}

export default fluidSizing;
