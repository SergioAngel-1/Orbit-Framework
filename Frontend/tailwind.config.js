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
        primario: 'var(--color-primary)',
        'primario-dark': 'var(--color-primary-dark)',
        secundario: 'var(--color-secondary)',
        acento: 'var(--color-accent)',
        oscuro: 'var(--oscuro)',
        claro: 'var(--claro)',
        texto: 'var(--texto)',
        hover: 'var(--hover)',
        border: 'var(--border)',
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
