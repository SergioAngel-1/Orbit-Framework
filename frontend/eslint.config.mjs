// ============================================================================
//  ESLint flat config (ESLint 10 + eslint-config-next 16).
//
//  Next 16 elimina el comando `next lint`; el linting se ejecuta con la CLI de
//  ESLint (`eslint .`, ver package.json) usando este "flat config".
//
//  eslint-config-next 16 exporta arrays flat NATIVOS: se importan y spreadean
//  directamente. No uses FlatCompat/extends con ellos — sus objetos plugin son
//  circulares por diseño y el validador eslintrc revienta al procesarlos.
// ============================================================================

import coreWebVitals from "eslint-config-next/core-web-vitals";
import typescript from "eslint-config-next/typescript";

const eslintConfig = [
  {
    ignores: [
      ".next/**",
      "node_modules/**",
      "next-env.d.ts",
      "coverage/**",
      "tests/stubs/**",
    ],
  },
  ...coreWebVitals,
  ...typescript,
  // eslint-plugin-react-hooks v7 marca como error el patrón "leer estado
  // solo-navegador tras montar" (setState síncrono en un efecto). Es un aviso
  // de rendimiento, no un bug de corrección: se deja visible como warning para
  // ir migrando esos efectos sin bloquear la CI.
  {
    files: ["**/*.{ts,tsx}"],
    rules: {
      "react-hooks/set-state-in-effect": "warn",
    },
  },
];

export default eslintConfig;
