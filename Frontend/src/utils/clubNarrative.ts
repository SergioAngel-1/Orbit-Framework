/**
 * Transformaciones de terminología para la narrativa del club
 * 
 * El club usa una narrativa propia donde:
 * - "Productos" → "Beneficios"
 * - "Categorías" → "Variedades"
 * - "Carrito" → "Reserva"
 * - "Comprar" → "Reservar"
 * - "Tienda" → "Catálogo"
 * 
 * Esta utilidad centraliza las transformaciones para mantener
 * consistencia en toda la interfaz de usuario.
 */

/**
 * Mapa de transformaciones exactas (case-sensitive)
 * Se aplican en orden, de más específico a más general
 */
import i18n from '../config/i18n';

const DEFAULT_TRANSFORMATIONS: Record<string, string> = {
  // Beneficios (plural)
  'Productos Exclusivos': 'Beneficios Exclusivos',
  'productos exclusivos': 'beneficios exclusivos',
  'Acceso a productos exclusivos': 'Acceso a beneficios exclusivos',
  'Productos seleccionados': 'Beneficios seleccionados',
  'productos seleccionados': 'beneficios seleccionados',
  'Todos los productos': 'Todos los beneficios',
  'No se encontraron productos': 'No se encontraron beneficios',
  'Cargando productos': 'Cargando beneficios',
  'Todos los productos ya están seleccionados': 'Todos los beneficios ya están seleccionados',
  'Error al cargar productos': 'Error al cargar beneficios',

  // Categorías → Variedades
  'Descuento en Categorías': 'Descuento en Variedades',
  'Descuento en categorías': 'Descuento en variedades',

  // Producto singular
  'Eliminar producto': 'Eliminar beneficio',
  'Editar producto': 'Editar beneficio',
  'Este producto requiere': 'Este beneficio requiere',
};

function getTransformations(): Record<string, string> {
  const resource = i18n.getResource(
    i18n.language,
    'clubNarrative',
    'transformations'
  ) as Record<string, string> | undefined;

  if (resource && Object.keys(resource).length > 0) {
    return resource;
  }

  return DEFAULT_TRANSFORMATIONS;
}

/**
 * Transforma un texto usando las transformaciones exactas del club
 * Solo reemplaza coincidencias exactas del mapa, no hace reemplazos parciales
 * 
 * @param text - Texto a transformar
 * @returns Texto transformado según la narrativa del club
 */
export function transformClubText(text: string): string {
  const transformations = getTransformations();
  return transformations[text] ?? text;
}

/**
 * Transforma un texto aplicando todas las transformaciones como substrings
 * Útil para textos largos que pueden contener múltiples términos
 * 
 * @param text - Texto largo a transformar
 * @returns Texto con todas las transformaciones aplicadas
 */
export function transformClubTextFull(text: string): string {
  const transformations = getTransformations();
  let result = text;
  for (const [from, to] of Object.entries(transformations)) {
    if (result.includes(from)) {
      result = result.split(from).join(to);
    }
  }
  return result;
}

export default transformClubText;
