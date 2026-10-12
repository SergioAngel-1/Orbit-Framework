/**
 * Ampliación de la config con los ajustes propios de LA INSTANCIA.
 *
 * `InstanceConfig` está vacía en el framework. Cada instancia declara aquí sus
 * campos (los del grupo `instance` del Control Center) y `SiteConfig.instance`
 * queda tipado con ellos. Ejemplo:
 *
 * ```ts
 * declare module "@/lib/config/types" {
 *   interface InstanceConfig {
 *     example: {
 *       greeting: string;
 *     };
 *   }
 * }
 * ```
 *
 * La fusión de `deepMerge` adopta las claves remotas que los defaults no conocen,
 * así que `config.instance.example.greeting` llega con el valor de WordPress.
 */
export {};
