// ============================================================================
//  ESLint flat config (ESLint 10 + eslint-config-next 16).
//
//  Next 16 elimina el comando `next lint`; el linting se ejecuta con la CLI de
//  ESLint (`eslint .`, ver package.json) usando este "flat config". Sustituye
//  al antiguo `.eslintrc.json` (formato eslintrc, deprecado).
// ============================================================================

import { FlatCompat } from "@eslint/eslintrc";

const compat = new FlatCompat({ baseDirectory: import.meta.dirname });

const eslintConfig = [
  ...compat.extends("next/core-web-vitals", "next/typescript"),
  {
    ignores: [
      ".next/**",
      "node_modules/**",
      "next-env.d.ts",
      "coverage/**",
      "tests/stubs/**",
    ],
  },
];

export default eslintConfig;
