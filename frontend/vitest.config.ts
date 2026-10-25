import { defineConfig } from "vitest/config";
import { existsSync } from "node:fs";
import path from "node:path";

/**
 * Catálogos que la instancia puede borrar (una tienda monolingüe se queda con `es`).
 * `messages/catalogs.ts` los importa de forma estática: si el archivo no está, el test
 * fallaría al cargar el módulo. Se resuelven al catálogo español, igual que en
 * `next.config.mjs` y `tsconfig.json`.
 */
const OPTIONAL_CATALOGS = [
  { specifier: "@/i18n/messages/en.json", file: "src/i18n/messages/en.json" },
].filter(({ file }) => !existsSync(path.resolve(__dirname, file)));

const catalogAliases = Object.fromEntries(
  OPTIONAL_CATALOGS.map(({ specifier }) => [
    specifier,
    path.resolve(__dirname, "src/i18n/messages/es.json"),
  ]),
);

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
      // Los tests del núcleo no dependen de los idiomas de la instancia: ven siempre la
      // configuración del framework (`tests/stubs/i18n-routing.ts`). Un test que necesite
      // la configuración real importa `i18n/routing` por ruta relativa.
      "@/i18n/routing": path.resolve(__dirname, "tests/stubs/i18n-routing.ts"),
      ...catalogAliases,
      "@": path.resolve(__dirname, "src"),
    },
  },
});
