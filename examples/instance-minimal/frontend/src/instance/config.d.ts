/**
 * Los ajustes propios de la instancia de EJEMPLO (ver `examples/instance-minimal/README.md`).
 *
 * En el job «contrato» de CI este archivo se superpone al `config.d.ts` del framework para
 * demostrar el *module augmentation*: `SiteConfig.instance` queda tipado con el campo
 * `instance.example.greeting` que el ejemplo declara en el Control Center.
 *
 * El campo es OPCIONAL a propósito: con WordPress caído (o el stub de CI) la config son
 * los defaults del framework, que no traen nada de la instancia. La vista cae al saludo
 * por defecto en ese caso.
 */
declare module "@/lib/config/types" {
  interface InstanceConfig {
    example?: {
      greeting: string;
    };
  }
}

export {};
