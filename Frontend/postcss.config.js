import nesting from 'tailwindcss/nesting/index.js';
import postcssNesting from 'postcss-nesting';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

export default {
  plugins: [
    // tailwindcss/nesting wraps postcss-nesting so Tailwind processes nested CSS (e.g. Swiper) correctly
    nesting(postcssNesting),
    tailwindcss,
    autoprefixer,
  ],
}
