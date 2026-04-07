import { api, createApiRequest, API_CONFIG } from './apiConfig';
import { MenuCategory } from '../types/menu';
import logger from '../utils/logger';

/**
 * Transforma los elementos del menú de WordPress al formato esperado por el hook
 * @param menuItems Elementos del menú devueltos por WordPress
 * @returns Array de categorías de menú en el formato esperado
 */
const mapItemToSubCategory = (item: any, parentId?: number): any => ({
  id: item.id || 0,
  name: item.title || 'Sin nombre',
  slug: item.term?.slug || item.slug || '',
  parentId: parentId ?? item.parent ?? item.menu_item_parent ?? undefined,
  min_membership_level: item.term?.minMembership ?? item.minMembership ?? undefined,
  children: Array.isArray(item.children)
    ? item.children.map((child: any) => mapItemToSubCategory(child, item.id || item.ID))
    : []
});

// Construye un árbol desde una lista plana usando referencias de padre
const buildTreeFromFlat = (items: any[]): any[] => {
  const nodeById = new Map<number, any>();
  const roots: any[] = [];

  // Crear nodos base
  for (const raw of items) {
    const node = mapItemToSubCategory(raw, raw.parent ?? raw.menu_item_parent ?? undefined);
    node.children = []; // aseguramos arreglo
    nodeById.set(node.id, node);
  }

  // Enlazar con padres
  for (const node of nodeById.values()) {
    const parentId = (node as any).parentId;
    if (parentId && nodeById.has(parentId)) {
      nodeById.get(parentId).children.push(node);
    } else {
      roots.push(node);
    }
  }

  return roots;
};

const transformMenuItems = (menuItems: any[]): MenuCategory[] => {
  try {
    if (!Array.isArray(menuItems)) {
      logger.warn('menuService', 'menuItems no es un array:', menuItems);
      return [];
    }

    // Si viene plano, construir árbol primero
    const looksFlat = menuItems.some(i => i.parent || i.menu_item_parent);
    const treeSource = looksFlat ? buildTreeFromFlat(menuItems) : menuItems;

    // Mapear con recursión para soportar múltiples niveles
    return treeSource.map(item => ({
      id: item.id || 0,
      name: item.title || 'Sin nombre',
      slug: item.term?.slug || item.slug || '',
      min_membership_level: item.term?.minMembership ?? item.minMembership ?? undefined,
      subcategories: Array.isArray(item.children)
        ? item.children.map((child: any) => mapItemToSubCategory(child, item.id))
        : []
    } as MenuCategory));
  } catch (error) {
    logger.error('menuService', 'Error al transformar elementos del menú:', error);
    return [];
  }
};

/**
 * Retorna menú vacío cuando no hay menú configurado en WordPress
 */
const getEmptyMenu = (): MenuCategory[] => {
  logger.warn('menuService', 'No hay menú activo. Configura uno en WP Admin → Apariencia → Menús');
  return [];
};

/**
 * Servicio para obtener datos del menú principal desde WordPress
 */
const menuService = {
  /**
   * Obtiene la estructura del menú principal desde WordPress
   * @param forceRefresh Si es true, fuerza la recarga del caché en el backend
   * @returns Promise con los datos del menú
   */
  getMainMenu: async (forceRefresh: boolean = false): Promise<MenuCategory[]> => {
    try {
      logger.info('menuService', 'Obteniendo menú desde WordPress...');
      
      // Parámetros para evitar caché
      const params: Record<string, string> = {};
      if (forceRefresh) {
        params.refresh = '1';
      }
      // Agregar timestamp para evitar caché del navegador/CDN
      params._t = Date.now().toString();
      
      // Usar createApiRequest con reintentos automáticos
      const response = await createApiRequest(
        () => api.get('/starter/v1/menu', {
          timeout: API_CONFIG.timeouts.short, // 15 segundos para el menú
          params
        }),
        API_CONFIG.retries.quick, // Solo 1 reintento para el menú
        API_CONFIG.delays.base
      );
      
      logger.info('menuService', 'Respuesta del menú:', response.data);
      
      // Verificar que la respuesta tenga la estructura esperada
      if (response.data && response.data.menu && Array.isArray(response.data.menu.items)) {
        // Transformar los elementos del menú al formato esperado
        const transformedMenu = transformMenuItems(response.data.menu.items);
        
        if (transformedMenu.length > 0) {
          logger.info('menuService', `Menú cargado exitosamente con ${transformedMenu.length} categorías`);
          return transformedMenu;
        } else {
          return getEmptyMenu();
        }
      } else {
        logger.warn('menuService', 'Estructura de respuesta inválida:', response.data);
        return getEmptyMenu();
      }
    } catch (error: any) {
      logger.error('menuService', 'Error al obtener el menú:', error);
      
      // Mostrar información más detallada para depuración
      if (error instanceof Error) {
        logger.error('menuService', 'Detalles del error:', {
          mensaje: error.message,
          tipo: error.name
        });
      }
      
      return getEmptyMenu();
    }
  }
};

export default menuService;
