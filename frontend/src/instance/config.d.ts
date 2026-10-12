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
 *     example?: {
 *       greeting: string;
 *     };
 *   }
 * }
 * ```
 *
 * El campo es opcional: con WordPress caído la config son los defaults del framework, que
 * no traen nada de la instancia. La vista debe caer a un valor por defecto en ese caso.
 *
 * La fusión de `deepMerge` adopta las claves remotas que los defaults no conocen,
 * así que `config.instance.example.greeting` llega con el valor de WordPress.
 */
export {};
