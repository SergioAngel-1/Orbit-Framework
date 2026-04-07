/**
 * Helper: genera un color Tailwind que soporta modificadores de opacidad (/10, /50, etc.)
 * Usa la variable CSS -rgb (formato "R G B" space-separated) para que Tailwind pueda
 * componer rgb(R G B / <alpha>) dinámicamente. Fallback a la variable hex cuando no hay opacidad.
 */
function withOpacity(variableRgb, variableHex) {
  return ({ opacityValue }) => {
    if (opacityValue !== undefined) {
      return `rgb(var(${variableRgb}) / ${opacityValue})`;
    }
    return `var(${variableHex})`;
  };
}

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    screens: {
      'xxs': '360px',
      'xs': '480px',
      'sm': '640px',
      'md': '768px',
      'lg': '1024px',
      'xl': '1280px',
      '2xl': '1536px',
    },
    extend: {
      colors: {
        primario: withOpacity('--color-primary-rgb', '--color-primary'),
        'primario-dark': withOpacity('--color-primary-dark-rgb', '--color-primary-dark'),
        secundario: withOpacity('--color-secondary-rgb', '--color-secondary'),
        acento: withOpacity('--color-accent-rgb', '--color-accent'),
        oscuro: withOpacity('--oscuro-rgb', '--oscuro'),
        claro: withOpacity('--claro-rgb', '--claro'),
        texto: withOpacity('--texto-rgb', '--texto'),
        hover: withOpacity('--hover-rgb', '--hover'),
        border: withOpacity('--border-rgb', '--border'),
      },
      fontFamily: {
        'sans': ['var(--font-family)', 'system-ui', 'sans-serif'],
      },
      fontSize: {
        '2xs': '0.65rem',
      },
    },
  },
  plugins: [],
}
