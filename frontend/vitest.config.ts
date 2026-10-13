import { defineConfig } from "vitest/config";
import path from "node:path";

// ============================================================================
//  Configuración de Vitest.
//
//  Dónde viven los tests:
//    · `tests/unit/**/*.test.ts`        — lógica de `src/lib` y rutas del BFF.
//    · `src/**/*.test.{ts,tsx}`         — colocados junto al código que prueban
//                                         (sobre todo componentes).
//
//  Entorno: `node` por defecto. Un test de componente pide el DOM con el
//  docblock `// @vitest-environment jsdom` en su primera línea (ver button.test.tsx). Con `globals: true` Testing Library registra
//  su limpieza automática entre tests.
//
//  Solo cubre lógica pura / de servidor que no requiere la pila Docker. Los
//  módulos marcados `server-only` se neutralizan con un alias a un stub vacío
//  (ese paquete solo existe para fallar el bundle del cliente, no en tests).
// ============================================================================

export default defineConfig({
  test: {
    environment: "node",
    include: ["tests/unit/**/*.test.ts", "src/**/*.test.ts", "src/**/*.test.tsx"],
    globals: true,
    // next-intl (su middleware, y `createNavigation`) importa "next/server" y
    // "next/navigation" sin extensión. Next no declara "exports" en su
    // package.json, así que bajo la resolución ESM nativa de Node —la que usa
    // Vitest para las dependencias externas— esos imports no se encuentran
    // ("Did you mean next/server.js?"). Con `inline` pasan por el resolvedor de
    // Vite, que sí completa la extensión. Lo necesitan los tests que importan
    // `@/proxy` (tests/unit/proxy-*.test.ts).
    server: {
      deps: {
        inline: ["next-intl"],
      },
    },
    coverage: {
      provider: "v8",
      include: ["src/lib/**/*.ts"],
      reporter: ["text", "lcov"],
      // Umbrales de trinquete: un punto o dos por debajo de lo medido, para que un
      // cambio no pueda bajar la cobertura sin que CI lo diga. Se suben cuando la
      // cobertura sube; nunca se bajan para que pase un cambio.
      thresholds: {
        statements: 83,
        branches: 75,
        functions: 87,
        lines: 84,
        // Lo que decide la seguridad y el cobro se exige más que el resto.
        "src/lib/security/**": {
          statements: 92,
          branches: 86,
          functions: 94,
          lines: 94,
        },
        "src/lib/payments/**": {
          statements: 82,
          branches: 70,
          functions: 85,
          lines: 80,
        },
        "src/lib/i18n/**": {
          statements: 97,
          branches: 94,
          functions: 97,
          lines: 97,
        },
      },
    },
  },
  resolve: {
    alias: {
      "server-only": path.resolve(__dirname, "tests/stubs/server-only.ts"),
      "@": path.resolve(__dirname, "src"),
    },
  },
});
