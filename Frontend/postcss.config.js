export default {
  plugins: {
    // postcss-nesting MUST come before tailwindcss to process nested CSS from swiper
    'postcss-nesting': {},
    tailwindcss: {},
    autoprefixer: {},
  },
}
