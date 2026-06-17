/** @type {import('postcss-load-config').Config} */
const config = {
  plugins: {
    // Tailwind CSS v4: el plugin de PostCSS dedicado.
    // Incluye autoprefixer e import por defecto (no hacen falta por separado).
    "@tailwindcss/postcss": {},
  },
};

export default config;
