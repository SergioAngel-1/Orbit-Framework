import { instanceRegistry } from "@/instance/registry";
import type { DecorPreset } from "@/lib/instance/types";

// ============================================================================
//  Visuales decorativos de plantilla de un slide: de la CLAVE que elige el
//  editor en el CMS al archivo que se pinta.
//
//  El plugin `hwe-banners` guarda y sirve solo la clave (`decorPreset`): los
//  assets son del frontend de la instancia, y una URL guardada en la base de
//  datos dejaría de resolver en cuanto se renombrase un archivo. La instancia los
//  declara en `InstanceRegistry.decorPresets` y, en PHP, con el filtro
//  `hwe_banners_decor_presets` (etiquetas del desplegable): ambos lados deben
//  declarar las MISMAS claves y en el MISMO orden —un test de paridad del
//  framework lo comprueba contra un fixture compartido—.
//
//  Cada preset declara su política de inversión en tema oscuro, que no es
//  cosmética sino legibilidad:
//
//   · `theme`  — se invierte bajo `html.dark` (trazo oscuro sobre fondo claro que
//                pasa a claro sobre fondo oscuro). Es el valor por defecto.
//   · `light`  — invertido en tema CLARO y tal cual en oscuro: es el archivo
//                blanco, que sobre un fondo claro sería invisible.
//   · `never`  — sin inversión nunca: invertir un trazo policromo no lo adapta al
//                tema, le cambia los colores de marca.
//
//  Sin presets declarados no hay visual de plantilla que servir (`src` vacío): el
//  componente decide qué enseñar en su lugar.
// ============================================================================

export type { DecorInvert, DecorPreset } from "@/lib/instance/types";

/** Clases de inversión de cada política, para el `<img>` del visual decorativo. */
const INVERT_CLASS = {
  theme: "dark:invert",
  light: "invert dark:invert-0",
  never: "",
} as const;

export interface ResolvedDecor {
  /** Ruta del archivo a pintar; vacía si no hay visual (modo sin presets declarados). */
  src: string;
  /** Clases de inversión; cadena vacía cuando el acabado no se invierte. */
  invertClass: string;
}

/** Los presets de la instancia, en el orden en que los declaró. */
export function decorPresets(
  presets: Record<string, DecorPreset> | undefined = instanceRegistry.decorPresets,
): Record<string, DecorPreset> {
  return presets ?? {};
}

/**
 * Clave que se sirve cuando el slide no trae ninguna (guardado antes de que el campo
 * existiera) o trae una que ya no está declarada: el PRIMER preset. `""` si no hay
 * ninguno.
 */
export function defaultDecorPreset(
  presets: Record<string, DecorPreset> | undefined = instanceRegistry.decorPresets,
): string {
  return Object.keys(decorPresets(presets))[0] ?? "";
}

/**
 * Resuelve el visual decorativo de un slide: el SVG subido (`custom`) manda sobre
 * cualquier preset, y un archivo del editor NO se invierte —no sabemos de qué color
 * es su trazo, y adivinarlo lo estropea la mitad de las veces—.
 */
export function resolveDecor(
  slide: { decorMode?: string; decorSvg?: string; decorPreset?: string },
  presets: Record<string, DecorPreset> | undefined = instanceRegistry.decorPresets,
): ResolvedDecor {
  if (slide.decorMode === "custom" && slide.decorSvg) {
    return { src: slide.decorSvg, invertClass: "" };
  }
  const all = decorPresets(presets);
  const preset =
    (slide.decorPreset ? all[slide.decorPreset] : undefined) ??
    all[defaultDecorPreset(all)];
  if (!preset) return { src: "", invertClass: "" };
  return { src: preset.src, invertClass: INVERT_CLASS[preset.invert ?? "theme"] };
}
