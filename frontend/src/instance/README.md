# `src/instance/` — el código de TU instancia (frontend)

Esta carpeta es **tuya**. El framework nunca la toca: puedes actualizar el núcleo sin
conflictos en nada de lo que pongas aquí.

## Qué va aquí

| Ruta | Para qué |
|---|---|
| `registry.ts` | El registro de la instancia. Es el **único** módulo de esta carpeta que el núcleo importa (ver `InstanceRegistry` en `src/lib/instance/types.ts`). Rellena sus campos según necesites (`timeZone` para la zona horaria de la tienda, por ejemplo). |
| `config.d.ts` | Ejemplo (comentado) de cómo ampliar `InstanceConfig` con tus ajustes, para que `SiteConfig.instance` quede tipado. |
| `lib/` | El dominio de tu instancia (fetchers, formato, validación…). Solo lo usa tu propio código: el núcleo nunca lo importa. |

## La frontera (qué no hacer)

- El núcleo (`src/lib/**`, `src/app/api/**`, `src/proxy.ts`, `src/components/ui/**`)
  **no puede** importar `@/components/**` (salvo `ui/`) ni `@/instance/**` (salvo
  `@/instance/registry`). Lo impone la regla ESLint «Frontera núcleo/instancia»:
  si tu código necesita algo del núcleo, el núcleo debe exponerlo en `lib/`, no
  al revés.
- `InstanceRegistry` son **solo datos**, nunca componentes de React. Las secciones
  únicas, los presets o los campos de producto se *declaran* aquí; el *mapa* de clave
  a componente vive en tus vistas.

## Ejemplo completo

`examples/instance-minimal/` replica esta carpeta (y la de backend) con un campo, un
tag de revalidación y una vista. Copia de ahí para empezar.
