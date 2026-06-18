import { defineConfig } from "vitest/config";
import path from "node:path";

// ============================================================================
//  Configuración de Vitest (tests unitarios de `src/lib`).
//
//  Solo cubre lógica pura / de servidor que no requiere la pila Docker. Los
//  módulos marcados `server-only` se neutralizan con un alias a un stub vacío
//  (ese paquete solo existe para fallar el bundle del cliente, no en tests).
// ============================================================================

export default defineConfig({
  test: {
    environment: "node",
    include: ["tests/unit/**/*.test.ts"],
    globals: true,
    coverage: {
      provider: "v8",
      include: ["src/lib/**/*.ts"],
      reporter: ["text", "lcov"],
    },
  },
  resolve: {
    alias: {
      "server-only": path.resolve(__dirname, "tests/stubs/server-only.ts"),
      "@": path.resolve(__dirname, "src"),
    },
  },
});
